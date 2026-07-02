<?php
/**
 * Old Analysis Templates → New `analysis_templates` Migration Script
 *
 * Best-effort port of the old UNICC fork's saved Analysis report templates
 * (stored as Flexmonster pivot-config JSON in the generic `config` table,
 * group `filters`) into the new stack's WebDataRocks-based Report Builder
 * (see ushahidi-api/LIBERIA_CUSTOM.md, "Analysis / Analysis Templates").
 *
 * WebDataRocks' report JSON shape (`slice.rows[]`/`columns[]`/`measures[]`,
 * each `{uniqueName, ...}`) is close enough to the old Flexmonster shape
 * that a full 2D (or N-level) pivot can be preserved almost verbatim —
 * unlike this script's first version (written before the Report Builder
 * was rebuilt around WebDataRocks), which had to decompose every 2D pivot
 * into separate 1D bar charts. This version instead remaps each row/column
 * dimension's `uniqueName` from the old Flexmonster field name (e.g.
 * "Province", "Type of Incident") to the equivalent column name the new
 * `PivotDataController`-backed data fetch actually produces (e.g.
 * "County", or a migrated `form_attributes.label`) — dropping (and
 * logging, not guessing) only the specific rows/columns entries that don't
 * map, rather than the whole chart. Old per-member `filter` values (tied
 * to old category/tag IDs) are stripped, not carried over — they'd
 * reference IDs that mean nothing in the new dataset.
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

// ── Dimension name → new PivotDataController column name ───────────────────
// Special-cased Flexmonster field names used across every old template
// (see ushahidi-client's old report-format.ts / post-filters.component.ts),
// mapped to the exact column names PivotDataController::index() produces.
const SPECIAL_DIMENSIONS = [
    'province' => 'County',
    'district' => 'District',
    'zone' => 'District',
    'incident status' => 'Status',
    'status' => 'Status',
    'title of incident' => 'Title',
    'post id' => 'Post ID',
];

// Attribute types PivotDataController exposes as pivotable columns (must
// match its own VALUE_TABLES keys exactly).
const GROUPABLE_ATTRIBUTE_TYPES = ['varchar', 'text', 'datetime', 'decimal', 'int'];

/**
 * Load migrated form_attributes into a label → {type, input} lookup, so old
 * Flexmonster dimension names (which are attribute *labels*, e.g. "Type of
 * Incident") can be checked against what PivotDataController will actually
 * expose (it uses the label itself as the JSON key, no key remapping).
 */
function loadAttributeLookup(PDO $dst): array {
    $lookup = [];
    $rows = $dst->query("SELECT label, type, input FROM form_attributes")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $lookup[strtolower(trim($row['label']))] = ['type' => $row['type'], 'input' => $row['input']];
    }
    return $lookup;
}

/**
 * Map one old Flexmonster dimension name to the new column name
 * PivotDataController produces, or null if it can't be mapped.
 */
function mapDimensionName(string $name, array $attributeLookup, array &$dropped): ?string {
    $normalized = strtolower(trim($name));

    if (isset(SPECIAL_DIMENSIONS[$normalized])) {
        return SPECIAL_DIMENSIONS[$normalized];
    }

    $attribute = $attributeLookup[$normalized] ?? null;
    if (!$attribute) {
        $dropped[] = "dimension '$name': no matching special-case or form_attributes.label";
        return null;
    }

    if (!in_array($attribute['type'], GROUPABLE_ATTRIBUTE_TYPES, true)) {
        $dropped[] = "dimension '$name': attribute type '{$attribute['type']}' has no column in the new pivot data (only varchar/text/datetime/decimal/int attributes do)";
        return null;
    }
    if ($attribute['type'] === 'varchar' && $attribute['input'] === 'text') {
        $dropped[] = "dimension '$name': plain free-text field, excluded from the new pivot data (same rule the Report Builder's own field discovery uses)";
        return null;
    }

    // PivotDataController uses the attribute's label directly as the row key.
    return $name;
}

/**
 * Remap one old Flexmonster `slice.rows`/`columns` array to the new column
 * names, dropping (and logging) individual entries that don't map. Strips
 * `filter` (references old category/tag member IDs, meaningless on the new
 * dataset) but keeps `sort` (harmless, still valid).
 */
function mapHierarchies(array $hierarchies, array $attributeLookup, array &$dropped): array {
    $mapped = [];
    foreach ($hierarchies as $h) {
        if (($h['uniqueName'] ?? '') === '[Measures]') {
            $mapped[] = ['uniqueName' => '[Measures]'];
            continue;
        }
        $newName = mapDimensionName($h['uniqueName'] ?? '', $attributeLookup, $dropped);
        if ($newName === null) {
            continue;
        }
        $entry = ['uniqueName' => $newName];
        if (isset($h['sort'])) {
            $entry['sort'] = $h['sort'];
        }
        $mapped[] = $entry;
    }
    return $mapped;
}

/**
 * Remap one old Flexmonster reportConfig[] entry's slice into the new
 * schema. Measures pass through largely as-is — "Post ID" is a stable
 * field name present in both the old and new data shapes.
 */
function mapReportConfigEntry(array $rc, array $attributeLookup, array &$dropped): ?array {
    $rows = mapHierarchies($rc['slice']['rows'] ?? [], $attributeLookup, $dropped);
    $columns = mapHierarchies($rc['slice']['columns'] ?? [], $attributeLookup, $dropped);
    $measures = array_values(array_filter(
        $rc['slice']['measures'] ?? [],
        fn($m) => ($m['uniqueName'] ?? '') !== ''
    ));
    if (!$measures) {
        $measures = [['uniqueName' => 'Post ID', 'aggregation' => 'count']];
    }

    // A chart with no dimensions left on either axis isn't a meaningful pivot.
    if (!$rows && !$columns) {
        $dropped[] = 'entire chart: no rows/columns dimension survived remapping';
        return null;
    }

    return [
        'slice' => array_filter([
            'rows' => $rows ?: null,
            'columns' => $columns ?: null,
            'measures' => $measures,
        ]),
    ];
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
         status_filter, tags_filter, chart_type, created)
     VALUES (:name, NULL, :form_id, :date_range_start, :date_range_end, :report_config,
             :status_filter, :tags_filter, 'bar', :created)"
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
        $mapped = mapReportConfigEntry($rc, $attributeLookup, $dropped);
        if ($mapped) {
            $reportConfig[] = $mapped;
        }
    }
    $totalChartsDropped += count($dropped);
    foreach ($dropped as $reason) {
        log_step("  NOTE in template '$name': $reason");
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
        ':created' => strtotime($row['updated']) ?: time(),
    ]);
    log_step("  MIGRATED '$name': " . count($reportConfig) . ' pivot(s)');
    $migrated++;
}

log_step("Done: $migrated migrated, $skipped skipped, $totalChartsDropped individual dimension(s)/chart(s) dropped");
