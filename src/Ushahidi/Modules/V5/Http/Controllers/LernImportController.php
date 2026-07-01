<?php

namespace Ushahidi\Modules\V5\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Ushahidi\Modules\V5\Helpers\GeoLookupHelper;

class LernImportController extends V5Controller
{
    // Form and attribute IDs match the migrated Liberia data
    private const LERN_FORM_ID = 6;
    private const LERN_POINT_ATTR_ID = 108;

    /**
     * Import LERN incident data from CSV or JSON array.
     * POST /api/v3/lern-import
     * Requires admin authentication.
     *
     * Request body: JSON array of incident objects, or CSV file upload.
     * Each row: { incident_title, incident_description, locale, incident_date,
     *             incident_dateadd, latitude?, longitude? }
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAnyone();

        // Accept JSON body array OR file upload
        if ($request->hasFile('file')) {
            $rows = $this->parseCsv($request->file('file')->path());
        } else {
            $rows = $request->validate(['rows' => 'required|array'])['rows'];
        }

        $accepted = 0;
        $rejected = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            try {
                $this->importRow($row);
                $accepted++;
            } catch (\Exception $e) {
                $rejected++;
                $errors[] = "Row $i: " . $e->getMessage();
            }
        }

        return response()->json([
            'accepted' => $accepted,
            'rejected' => $rejected,
            'errors'   => $errors,
        ]);
    }

    private function importRow(array $row): void
    {
        $title = trim($row['incident_title'] ?? '');
        if (empty($title)) {
            throw new \InvalidArgumentException('incident_title is required');
        }

        // Skip duplicates
        if (DB::table('posts')->where('type', 'lern')->where('title', $title)->exists()) {
            throw new \RuntimeException("Already exists: $title");
        }

        $postDate = !empty($row['incident_date'])
            ? date('Y-m-d H:i:s', strtotime($row['incident_date']))
            : null;

        $created = !empty($row['incident_dateadd'])
            ? strtotime($row['incident_dateadd'])
            : time();

        // Determine mgmt_lev from coordinates if provided
        $mgmtLev1 = '';
        $mgmtLev2 = '';
        $lat = $row['latitude'] ?? null;
        $lng = $row['longitude'] ?? null;
        if ($lat && $lng) {
            [$mgmtLev1, $mgmtLev2] = GeoLookupHelper::lookup((float)$lat, (float)$lng);
        }

        // Insert post
        $postId = DB::table('posts')->insertGetId([
            'type'          => 'lern',
            'form_id'       => self::LERN_FORM_ID,
            'title'         => $title,
            'content'       => $row['incident_description'] ?? '',
            'locale'        => $row['locale'] ?? 'en_US',
            'status'        => 'published',
            'post_date'     => $postDate,
            'created'       => $created,
            'updated'       => time(),
            'mgmt_lev_1'    => $mgmtLev1,
            'mgmt_lev_2'    => $mgmtLev2,
            'base_language' => 'en_US',
        ]);

        // Insert point geometry if coordinates provided
        if ($lat && $lng) {
            DB::statement(
                "INSERT INTO post_point (post_id, form_attribute_id, value, created)
                 VALUES (?, ?, ST_GeomFromText(?), ?)",
                [
                    $postId,
                    self::LERN_POINT_ATTR_ID,
                    "POINT($lng $lat)",
                    time(),
                ]
            );
        }
    }

    private function parseCsv(string $path): array
    {
        $rows = [];
        if (($handle = fopen($path, 'r')) === false) return [];
        $headers = fgetcsv($handle);
        if (!$headers) { fclose($handle); return []; }
        $headers = array_map('trim', $headers);
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($headers, $data);
        }
        fclose($handle);
        return $rows;
    }
}
