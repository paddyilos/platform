<?php

namespace Ushahidi\Modules\V5\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Ushahidi\Modules\V5\Events\PostUpdatedEvent;
use Ushahidi\Modules\V5\Models\Alert;
use Ushahidi\Modules\V5\Models\Post\PostStatus;

/**
 * Liberia custom — emails "Get Alerts" subscribers when a report is
 * published within their radius/category. Registered against the stock
 * PostCreatedEvent/PostUpdatedEvent in EventServiceProvider.php so no
 * PostController changes are needed. Ports SendAlertsJob from the old
 * UNICC backend (ireport/backend/src/App/Listener/SendAlertsJob.php),
 * which was never carried over to this fork.
 */
class SendPostAlertsListener implements ShouldQueue
{
    public function handle($event)
    {
        $post = $event->post ?? null;
        if (!$post || $post->status !== PostStatus::PUBLISHED) {
            return;
        }

        // Only alert on the transition into "published" — not on every
        // subsequent edit of an already-published post. PostCreatedEvent's
        // post is already in its final state at dispatch time, so no
        // "changed" check applies there. wasChanged() only reflects the
        // save() that happened on this exact model instance, so this is
        // best-effort for the dedicated status-patch endpoints
        // (patch()/bulkPatchOperation()) rather than every possible path.
        if ($event instanceof PostUpdatedEvent && !$post->wasChanged('status')) {
            return;
        }

        $point = DB::table('post_point')
            ->where('post_id', $post->id)
            ->whereNotNull('value')
            ->selectRaw('ST_Y(value) as lat, ST_X(value) as lon')
            ->first();

        if (!$point) {
            return;
        }

        $categoryIds = DB::table('posts_tags')->where('post_id', $post->id)->pluck('tag_id')->all();

        foreach (Alert::where('status', 1)->get() as $alert) {
            if (!$this->isWithinRadius($alert, (float) $point->lat, (float) $point->lon)) {
                continue;
            }
            if (!$this->matchesCategories($alert, $categoryIds)) {
                continue;
            }
            $this->sendAlertEmail($alert, $post);
        }
    }

    private function isWithinRadius(Alert $alert, float $lat, float $lon): bool
    {
        $distanceKm = $this->haversineKm((float) $alert->latitude, (float) $alert->longitude, $lat, $lon);
        return $distanceKm <= (float) $alert->radius;
    }

    private function matchesCategories(Alert $alert, array $postCategoryIds): bool
    {
        $alertCategories = $alert->categories ? json_decode($alert->categories, true) : null;
        if (empty($alertCategories)) {
            // No category filter set on the subscription — alert on any published report.
            return true;
        }
        return count(array_intersect($alertCategories, $postCategoryIds)) > 0;
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function sendAlertEmail(Alert $alert, $post): void
    {
        $clientUrl = rtrim((string) env('DEFAULT_CLIENT_URL', ''), '/');

        $data = [
            'title' => $post->title,
            'excerpt' => $this->excerpt($post->content),
            'report_url' => $clientUrl ? $clientUrl . '/posts/' . $post->id : null,
            'unsubscribe_url' => url('/api/v3/get-alerts/unsubscribe-email/' . $alert->hash),
        ];

        try {
            Mail::send('emails/post-alert', $data, function ($message) use ($alert, $post) {
                $message->to($alert->email)
                    ->subject('iReport Liberia: New report near you' . ($post->title ? ' — ' . $post->title : ''));
            });
        } catch (\Exception $e) {
            // Log silently, per-subscriber — one bad address shouldn't block the rest.
            \Log::warning('Post alert email failed for alert #' . $alert->id . ': ' . $e->getMessage());
        }
    }

    private function excerpt(?string $content, int $length = 200): string
    {
        if (!$content) {
            return '';
        }
        $plain = trim(strip_tags($content));
        return mb_strlen($plain) > $length ? mb_substr($plain, 0, $length) . '…' : $plain;
    }
}
