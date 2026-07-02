<?php
/**
 * Old Analysis Templates → New `analysis_templates` Migration Script
 *
 * Best-effort port of the old UNICC fork's saved Analysis report templates
 * (stored as Flexmonster pivot-config JSON in the generic `config` table,
 * group `filters`) into the new stack's dedicated `analysis_templates`
 * table (see ushahidi-api/LIBERIA_CUSTOM.md, "Analysis / Analysis
 * Templates"). The old templates are arbitrary 2D Flexmonster pivots
 * (a `rows` dimension × a `columns` dimension, with one or more chart
 * panels per template); the new schema only supports a flat array of
 * single-dimension `{group_by, group_by_attribute_key, chart_type}` chart
 * configs (report_config). This script decomposes each old 2D pivot into
 * up to two 1D charts — one per axis — rather than fabricating a 2D
 * crosstab equivalent. Anything it can't confidently map (a rows[] with
 * more than one entry — a true multi-level pivot — or a dimension name
 * that matches nothing on either the special-case list or the migrated
 * form_attributes) is DROPPED, not guessed, and logged so PBO staff know
 * exactly what needs manual recreation in the new Report Builder.
 *
 * Usage: php migration/migrate-liberia-analysis-templates.php
 *
 * Prerequisites:
 *   - ireport-mysql container running on localhost:3306
 *   - ushahidi-mysql container running on localhost:33061
 *   - migrate-liberia.php already run (forms/form_attributes migrated)
 *   - Phinx migration 20260702000001 (report_config/status_filter/tags_filter
 *     columns) already applied
 *
 * Run from: /Users/yddapsoli/Sites/blueseas/earlywarningpbo/ushahidi-api/
 */

// ── Connection config (same as migrate-liberia.php) ────────────────────────
$srcDsn = 'mysql:host=127.0.0.1;port=3306;dbname=ireport-liberia;charset=utf8mb4';
$dstDsn = 'mysql:host=127.0.0.1;port=33061;dbname=ushahidi;charset=utf8mb4';

try {
    $src = new PDO($srcDsn, 'root', 'rootpass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dst = new PDO($dstDsn, 'ushahidi', 'ushahidi', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

function log_step(string $msg): void {
    echo "[" . date('H:i:s') . "] $msg\n";
}

// ── Preflight: make sure the Phase 2 schema is deployed ────────────────────
$hasReportConfig = $dst
    ->query("SHOW COLUMNS FROM analysis_templates LIKE 'report_config'")
    ->fetch();
if (!$hasReportConfig) {
    die(
        "analysis_templates.report_config column not found — run Phinx migration " .
        "20260702000001_liberia_analysis_templates_multi_chart.php first.\n"
    );
}

// ── Dimension name → new group_by mapping ───────────────────────────────────
// Special-cased Flexmonster field names used across every old template
// (see ushahidi-client's report-format.ts / post-filters.component.ts).
const SPECIAL_DIMENSIONS = [
    'province' => ['group_by' => 'county'],
    'district' => ['group_by' => 'district'],
    'zone' => ['group_by' => 'district'],
    'incident status' => ['group_by' => 'status'],
    'status' => ['group_by' => 'status'],
];

// Attribute types the new backend's `group_by=attribute` supports
// (EloquentPostRepository::GROUPABLE_ATTRIBUTE_TABLES — see Phase 0).
const GROUPABLE_ATTRIBUTE_TYPES = ['varchar', 'text', 'datetime', 'decimal', 'int'];

/**
 * Load migrated form_attributes into a label → {key, type} lookup, so old
 * Flexmonster dimension names (which are attribute *labels*, e.g. "Type of
 * Incident") can be resolved to the new schema's attribute *keys*.
 */
function loadAttributeLookup(PDO $dst): array {
    $lookup = [];
    $rows = $dst->query("SELECT `key`, label, type FROM form_attributes")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $lookup[strtolower(trim($row['label']))] = ['key' => $row['key'], 'type' => $row['type']];
    }
    return $lookup;
}

/**
 * Map one old Flexmonster dimension name to a new {group_by,
 * group_by_attribute_key} pair, or null if it can't be mapped.
 */
function mapDimension(string $name, array $attributeLookup, array &$dropped): ?array {
    $normalized = strtolower(trim($name));

    if (isset(SPECIAL_DIMENSIONS[$normalized])) {
        return SPECIAL_DIMENSIONS[$normalized];
    }

    $attribute = $attributeLookup[$normalized] ?? null;
    if (!$attribute) {
        $dropped[] = "dimension '$name': no matching special-case or form_attributes.label";
        return null;
    }

    if ($attribute['type'] === 'tags') {
        // Per-attribute tag pivoting has no new-schema equivalent — the
        // closest match is the generic `tags` group-by (all post categories).
        return ['group_by' => 'tags'];
    }

    if (!in_array($attribute['type'], GROUPABLE_ATTRIBUTE_TYPES, true)) {
        $dropped[] = "dimension '$name': attribute type '{$attribute['type']}' is not groupable";
        return null;
    }

    return ['group_by' => 'attribute', 'group_by_attribute_key' => $attribute['key']];
}

/**
 * Decompose one old Flexmonster reportConfig[] entry (a rows × columns 2D
 * pivot) into up to two new 1D chart configs.
 */
function mapReportConfigEntry(array $rc, array $attributeLookup, array &$dropped): array {
    $charts = [];

    $rows = $rc['slice']['rows'] ?? [];
    if (count($rows) === 1) {
        $mapped = mapDimension($rows[0]['uniqueName'] ?? '', $attributeLookup, $dropped);
        if ($mapped) {
            $charts[] = $mapped + ['chart_type' => 'bar'];
        }
    } elseif (count($rows) > 1) {
        $dropped[] = 'rows[] has ' . count($rows) . ' entries — multi-level pivots are not supported';
    }

    $columns = array_values(array_filter(
        $rc['slice']['columns'] ?? [],
        fn($c) => ($c['uniqueName'] ?? '') !== '[Measures]'
    ));
    if (count($columns) === 1) {
        $mapped = mapDimension($columns[0]['uniqueName'] ?? '', $attributeLookup, $dropped);
        // Skip if it's the same dimension as the rows chart (avoid a duplicate).
        if ($mapped && (!$charts || $charts[0]['group_by'] !== $mapped['group_by'])) {
            $charts[] = $mapped + ['chart_type' => 'bar'];
        }
    } elseif (count($columns) > 1) {
        $dropped[] = 'columns[] has ' . count($columns) . ' real dimensions — multi-level pivots are not supported';
    }

    return $charts;
}

function parseOldDate(?array $date): ?int {
    if (!$date || !isset($date['year'], $date['month'], $date['day'])) {
        return null;
    }
    return mktime(0, 0, 0, (int) $date['month'], (int) $date['day'], (int) $date['year']);
}

// ── Migrate ──────────────────────────────────────────────────────────────
log_step('Starting Analysis Templates migration');

$attributeLookup = loadAttributeLookup($dst);

$rows = $src
    ->query("SELECT config_key, config_value, updated FROM config WHERE group_name = 'filters' AND config_key != 'filters'")
    ->fetchAll(PDO::FETCH_ASSOC);

log_step(count($rows) . ' saved template(s) found in the old config table');

$migrated = 0;
$skipped = 0;
$totalChartsDropped = 0;

$insert = $dst->prepare(
    "INSERT INTO analysis_templates
        (name, user_id, form_id, date_range_start, date_range_end, report_config,
         status_filter, tags_filter, group_by, group_by_attribute_key, chart_type, created)
     VALUES (:name, NULL, :form_id, :date_range_start, :date_range_end, :report_config,
             :status_filter, :tags_filter, :group_by, :group_by_attribute_key, :chart_type, :created)"
);

foreach ($rows as $row) {
    $name = $row['config_key'];
    $data = json_decode($row['config_value'], true);
    if (!is_array($data)) {
        log_step("  SKIP '$name': config_value is not valid JSON");
        $skipped++;
        continue;
    }

    $dropped = [];
    $reportConfig = [];
    foreach ($data['reportConfig'] ?? [] as $rc) {
        $reportConfig = array_merge($reportConfig, mapReportConfigEntry($rc, $attributeLookup, $dropped));
    }
    $totalChartsDropped += count($dropped);
    foreach ($dropped as $reason) {
        log_step("  SKIP chart in template '$name': $reason");
    }

    if (!$reportConfig) {
        log_step("  SKIP '$name': no chart could be mapped, nothing to migrate");
        $skipped++;
        continue;
    }

    // Old `region`/`category` filter fields, if present — best-effort;
    // absent in every template found in the production data dump used to
    // build this script, but handled defensively.
    $statusFilter = null;
    $tagsFilter = !empty($data['category'])
        ? json_encode(array_values((array) $data['category']))
        : null;

    $insert->execute([
        ':name' => $name,
        ':form_id' => $data['formId'] ?? null,
        ':date_range_start' => parseOldDate($data['startDate'] ?? null),
        ':date_range_end' => parseOldDate($data['endDate'] ?? null),
        ':report_config' => json_encode($reportConfig),
        ':status_filter' => $statusFilter,
        ':tags_filter' => $tagsFilter,
        ':group_by' => $reportConfig[0]['group_by'],
        ':group_by_attribute_key' => $reportConfig[0]['group_by_attribute_key'] ?? null,
        ':chart_type' => $reportConfig[0]['chart_type'],
        ':created' => strtotime($row['updated']) ?: time(),
    ]);
    log_step("  MIGRATED '$name': " . count($reportConfig) . ' chart(s)');
    $migrated++;
}

log_step("Done: $migrated migrated, $skipped skipped, $totalChartsDropped individual chart(s) dropped");
