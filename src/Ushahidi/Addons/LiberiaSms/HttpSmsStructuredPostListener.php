<?php

namespace Ushahidi\Addons\LiberiaSms;

use Illuminate\Support\Facades\Log;
use Ushahidi\Core\Entity\Message;
use Ushahidi\Contracts\Repository\Entity\PostRepository;
use Ushahidi\Contracts\Repository\Entity\MessageRepository;
use Ushahidi\Contracts\Repository\Entity\FormAttributeRepository;
use Ushahidi\Contracts\Repository\Entity\TargetedSurveyStateRepository;

/**
 * Liberia custom — parses the legacy semicolon-delimited SMS report format
 * ("Title; Description; Type-of-incident code; Indicator code; Date; Location")
 * into structured survey fields, replacing the single-blob behaviour of the
 * stock Ushahidi\Modules\V3\Listener\CreatePostFromMessage for httpSMS
 * messages only. Fully dynamic: discovers the target survey's own fields
 * at runtime via FormAttributeRepository::getByForm() — no hardcoded form
 * id or attribute keys — so it keeps working if the httpSMS data source is
 * ever repointed at a different survey. Any SMS part that has nowhere to
 * go on the current survey (missing field type) is folded into the
 * Description text instead of being silently dropped.
 *
 * Registered ahead of the stock listeners via
 * Addons/LiberiaSms/ServiceProvider.php (see that file for why). Returns
 * false to halt propagation only when it actually handles a message —
 * everything else (other data sources, free-text SMS, targeted-survey
 * replies) falls through to stock behaviour unchanged.
 */
class HttpSmsStructuredPostListener
{
    protected $messageRepo;

    protected $targetedSurveyStateRepo;

    protected $postRepo;

    protected $formAttributeRepo;

    public function __construct(
        MessageRepository $messageRepo,
        TargetedSurveyStateRepository $targetedSurveyStateRepo,
        PostRepository $postRepo,
        FormAttributeRepository $formAttributeRepo
    ) {
        $this->messageRepo = $messageRepo;
        $this->targetedSurveyStateRepo = $targetedSurveyStateRepo;
        $this->postRepo = $postRepo;
        $this->formAttributeRepo = $formAttributeRepo;
    }

    public function handle($id, $message, $inbound_form_id, $inbound_fields)
    {
        if (!$this->shouldHandle($message)) {
            return;
        }

        if ($this->targetedSurveyStateRepo->isContactInActiveTargetedSurveyAndReceivedMessage($message->contact_id)) {
            return;
        }

        if (!$inbound_form_id) {
            return;
        }

        try {
            $post_id = $this->createStructuredPost($message, $inbound_form_id);

            $message->setState(compact('post_id'));
            $this->messageRepo->update($message);

            return false;
        } catch (\Throwable $e) {
            Log::warning(
                'HttpSmsStructuredPostListener failed, falling back to stock handling: ' . $e->getMessage(),
                ['message_id' => $id]
            );
            return;
        }
    }

    /**
     * Only intercept messages that actually look like structured httpSMS
     * reports. HttpSMS::getId() returns lowercase 'httpsms' — confirmed by
     * reading Addons/HttpSMS/HttpSMS.php, not assumed.
     */
    protected function shouldHandle(Message $message): bool
    {
        if (strtolower((string) $message->data_source) !== 'httpsms') {
            return false;
        }

        if (!is_string($message->message) || trim($message->message) === '') {
            return false;
        }

        $parts = explode(';', $message->message);
        if (count($parts) < 3) {
            return false;
        }

        // Guard against ordinary prose SMS that happens to contain a ';' —
        // the type-of-incident code (3rd field) must look numeric.
        return is_numeric(trim($parts[2]));
    }

    protected function createStructuredPost(Message $message, $form_id): int
    {
        $parts = array_map('trim', explode(';', $message->message));

        $title = $parts[0] ?? '';
        $description = $parts[1] ?? '';
        $typeCode = isset($parts[2]) && $parts[2] !== '' ? (int) $parts[2] : null;
        $indicatorCode = isset($parts[3]) && $parts[3] !== '' ? (int) $parts[3] : null;
        $dateRaw = $parts[4] ?? null;
        $locationRaw = $parts[5] ?? null;

        $attributes = $this->formAttributeRepo->getByForm($form_id);

        $datetimeAttr = null;
        $pointAttr = null;
        $indicatorAttrs = [];

        foreach ($attributes as $attribute) {
            switch ($attribute->type) {
                case 'datetime':
                    $datetimeAttr = $datetimeAttr ?? $attribute;
                    break;
                case 'point':
                    $pointAttr = $pointAttr ?? $attribute;
                    break;
                case 'varchar':
                    if (preg_match('/indicators\s*$/i', trim((string) $attribute->label))) {
                        $indicatorAttrs[] = $attribute;
                    }
                    break;
            }
        }

        usort($indicatorAttrs, function ($a, $b) {
            return [$a->priority, $a->id] <=> [$b->priority, $b->id];
        });

        $values = [];
        $fallback = [];

        // Type of incident + Indicator: the survey's own "<Category>
        // Indicators" varchar attributes ARE the category list — SMS
        // type-code selects which one, indicator-code indexes into that
        // attribute's own options array. No-ops silently if the survey has
        // none of these fields.
        if ($typeCode !== null && count($indicatorAttrs) > 0) {
            $categoryAttr = $indicatorAttrs[$typeCode - 1] ?? null;
            if ($categoryAttr) {
                $options = array_values((array) $categoryAttr->options);
                $indicatorValue = $indicatorCode !== null ? ($options[$indicatorCode - 1] ?? null) : null;
                if ($indicatorValue !== null) {
                    $values[$categoryAttr->key] = [$indicatorValue];
                } else {
                    $fallback[] = "Type: {$categoryAttr->label} (indicator code "
                        . ($indicatorCode ?? 'none') . ' not found)';
                }
            } else {
                $fallback[] = "Type/Indicator codes: {$typeCode}/"
                    . ($indicatorCode ?? 'none') . ' (no matching category on this survey)';
            }
        } elseif ($typeCode !== null) {
            $fallback[] = "Type: {$typeCode}, Indicator: " . ($indicatorCode ?? 'none');
        }

        // Date
        if (!empty($dateRaw)) {
            $parsedDate = $this->parseSmsDate($dateRaw);
            if ($parsedDate && $datetimeAttr) {
                $values[$datetimeAttr->key] = [$parsedDate->format('Y-m-d H:i:s')];
            } elseif ($parsedDate) {
                $fallback[] = 'Date: ' . $parsedDate->format('Y-m-d H:i:s');
            } else {
                $fallback[] = 'Date (unparsed): ' . $dateRaw;
            }
        }

        // Location — geocode via Nominatim (no API key needed), write to a
        // point attribute if one exists. Raw text always kept in the
        // fallback too, since it's cheap and useful context either way.
        if (!empty($locationRaw)) {
            $coords = $this->geocode($locationRaw);
            if ($coords && $pointAttr) {
                $values[$pointAttr->key] = [['lon' => $coords['lon'], 'lat' => $coords['lat']]];
            }
            $fallback[] = 'Location: ' . $locationRaw;
        }

        $content = $description;
        if (!empty($fallback)) {
            $content = trim($content) . "\n\n[" . implode('] [', $fallback) . ']';
        }

        $post = $this->postRepo->getEntity()->setState([
            'title' => $title !== '' ? $title : null,
            'content' => $content,
            'values' => $values,
            'form_id' => $form_id,
            'post_date' => $message->datetime,
        ]);

        return $this->postRepo->create($post);
    }

    /**
     * Explicit format list (no strtotime()) to avoid ambiguous day/month
     * swaps. Handover doc example SMS uses both '/' and '.' separators.
     */
    protected function parseSmsDate(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        $tz = new \DateTimeZone(config('app.timezone', 'UTC'));

        foreach (['d/m/Y H:i', 'd.m.Y H:i', 'd/m/Y H:i:s', 'd.m.Y H:i:s'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw, $tz);
            $errors = \DateTimeImmutable::getLastErrors();
            $hasErrors = $errors && ($errors['warning_count'] + $errors['error_count'] > 0);
            if ($dt && !$hasErrors) {
                return $dt;
            }
        }

        return null;
    }

    protected function geocode(string $query): ?array
    {
        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://nominatim.openstreetmap.org',
                'timeout' => 4.0,
                'connect_timeout' => 2.0,
            ]);

            $response = $client->get('/search', [
                'query' => [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 1,
                    'countrycodes' => 'lr',
                ],
                'headers' => [
                    'User-Agent' => config('app.name', 'iReport Liberia') . ' (' . config('app.url') . ')',
                ],
            ]);

            $results = json_decode((string) $response->getBody(), true);

            if (!empty($results[0]['lat']) && !empty($results[0]['lon'])) {
                return ['lat' => (float) $results[0]['lat'], 'lon' => (float) $results[0]['lon']];
            }
        } catch (\Throwable $e) {
            Log::warning('httpSMS geocoding failed for "' . $query . '": ' . $e->getMessage());
        }

        return null;
    }
}
