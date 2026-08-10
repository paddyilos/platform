<?php
/**
 * Migrated Categories Dedup Script
 *
 * migrate-liberia.php copied the legacy platform's `tags` table verbatim,
 * `parent_id` included — but the legacy app never used `parent_id` for real
 * hierarchy, it used the `type` column as an ad-hoc per-form grouping key
 * (`category`, `category-1`, `security`, `health-and-environment`,
 * `indicators`, `security-indicators`, `type-of-incident`, `testing`, ...).
 * The same category name was reused across several of those groups (e.g.
 * "Physical violence" appears under 5 different `type` values), leaving
 * ~13 groups of duplicate `tags.tag` names in the migrated data.
 *
 * Ushahidi's stock `PUT /categories/{id}` validation
 * (src/Ushahidi/Modules/V5/Requests/CategoryRequest.php, `'unique:tags,tag,'
 * . $category_id`) rejects any edit to a category whose `tag` collides with
 * another row's — and the client always resubmits the unchanged `tag` on
 * every save, including a save that only changes `parent_id`. So every one
 * of these duplicate-named migrated categories was silently un-editable:
 * assigning a parent appeared to save (no error surfaced in the UI — see
 * ushahidi-client's create-category-form.component fix, LIBERIA_CUSTOM.md)
 * but the 422 meant the update never actually persisted.
 *
 * This script disambiguates every duplicate group by appending the row's
 * legacy `type`, translated to a readable label, e.g. "Physical violence"
 * becomes "Physical violence (Security)", "Physical violence (Health &
 * Environment)", etc. Two rows in the same group that also share the same
 * `type` (a real case in the production data: two "Test"/type=category
 * rows) get a further `#<id>` suffix, logged explicitly.
 *
 * Usage:
 *   Local dev: php migration/migrate-liberia-dedupe-categories.php
 *     (connects to the local ushahidi-mysql container on localhost:33061)
 *   Production: run from inside the `platform` container, where the
 *   standard Laravel DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD
 *   env vars are already set to reach the real database over the Docker
 *   network (see deploy/docker-compose.prod.yml) — e.g.:
 *     docker compose --env-file deploy/.env -f deploy/docker-compose.prod.yml \
 *       exec -T platform php migration/migrate-liberia-dedupe-categories.php
 *
 * Prerequisites:
 *   - The target database reachable via the env vars/defaults below
 *   - migrate-liberia.php already run (tags migrated)
 *
 * Run from: the ushahidi-api repo root (local dev) or /var/www (in-container)
 *
 * Idempotency: unlike migrate-liberia.php / migrate-liberia-analysis-templates.php
 * (plain INSERT, unsafe to rerun), this script re-detects duplicates from the
 * *current* state of `tags` on every run. After a successful run no two rows
 * share a `tag` string anymore, so running it again finds nothing to do and
 * is a safe no-op.
 */

// ── Connection config (dst only — post-migration cleanup, no source DB) ────
// Defaults match local dev (the ushahidi-mysql container's host-mapped
// port); env vars let this run unchanged inside the production `platform`
// container, which already has these set to reach `mysql:3306` internally.
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '33061';
$dbName = getenv('DB_DATABASE') ?: 'ushahidi';
$dbUser = getenv('DB_USERNAME') ?: 'ushahidi';
$dbPass = getenv('DB_PASSWORD') ?: 'ushahidi';
$dstDsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";

try {
    $dst = new PDO($dstDsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

function log_step(string $msg): void {
    echo "[" . date('H:i:s') . "] $msg\n";
}

// ── Legacy `type` slug → readable label ─────────────────────────────────────
// The new platform's `tags.type` column is `varchar(20)` (the legacy schema's
// was `varchar(150)`), so migrate-liberia.php's verbatim copy silently
// truncated any longer legacy `type` value on import — confirmed live:
// 'health-and-environment' (23 chars) became 'health-and-environme' (20
// chars, no trailing "nt"). The lookup key below matches the truncated,
// actually-stored value, not the legacy source value.
const TYPE_LABELS = [
    'category' => 'Category',
    'category-1' => 'Category 1',
    'security' => 'Security',
    'health-and-environme' => 'Health & Environment',
    'indicators' => 'Indicators',
    'security-indicators' => 'Security Indicators',
    'type-of-incident' => 'Type of Incident',
    'testing' => 'Testing',
    'test' => 'Test',
];

function typeLabel(?string $type): string {
    if ($type === null || trim($type) === '') {
        return 'Uncategorized';
    }
    if (isset(TYPE_LABELS[$type])) {
        return TYPE_LABELS[$type];
    }
    return ucwords(str_replace('-', ' ', $type));
}

function slugify(string $name): string {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}

// ── Load and group ───────────────────────────────────────────────────────
log_step('Starting migrated-category dedup');

$rows = $dst->query("SELECT id, tag, slug, type FROM tags ORDER BY tag, id")->fetchAll(PDO::FETCH_ASSOC);
log_step(count($rows) . ' categor(y/ies) loaded from tags');

// `tags.tag` uses a case-insensitive collation (utf8mb4_unicode_520_ci,
// confirmed via `SHOW FULL COLUMNS FROM tags`), and Laravel's `unique:`
// validation rule respects the column's own collation — so "test" and
// "Test" collide as far as the API is concerned even though they're
// different strings. Group (and later check collisions) case-insensitively
// to match that, while still preserving each row's own original casing in
// the renamed value.
$groups = [];
foreach ($rows as $row) {
    $key = mb_strtolower(trim($row['tag']));
    $groups[$key][] = $row;
}

// Seed the used-name/used-slug sets (both tracked lowercased, for the same
// case-insensitive-collation reason) with every non-duplicate row, so a
// rename can't accidentally collide with an unrelated singleton category.
$usedNames = [];
$usedSlugs = [];
foreach ($groups as $key => $groupRows) {
    if (count($groupRows) === 1) {
        $usedNames[$key] = true;
        $usedSlugs[mb_strtolower($groupRows[0]['slug'])] = true;
    }
}

$duplicateGroups = array_filter($groups, fn($g) => count($g) > 1);
log_step(count($duplicateGroups) . ' duplicate-name group(s) found');

$update = $dst->prepare("UPDATE tags SET tag = :tag, slug = :slug WHERE id = :id");

$renamed = 0;
$collisions = 0;

foreach ($duplicateGroups as $key => $groupRows) {
    $ids = implode(',', array_column($groupRows, 'id'));
    $displayName = trim($groupRows[0]['tag']);
    log_step("Duplicate group '$displayName': " . count($groupRows) . " rows (ids: $ids)");

    foreach ($groupRows as $row) {
        $label = typeLabel($row['type']);
        $rowName = trim($row['tag']);
        $candidateName = "$rowName ($label)";

        if (isset($usedNames[mb_strtolower($candidateName)])) {
            $collisions++;
            $candidateName = "$candidateName #{$row['id']}";
            log_step("  Collision: '$rowName ($label)' already used, disambiguated id {$row['id']} to '$candidateName'");
        }
        $usedNames[mb_strtolower($candidateName)] = true;

        $candidateSlug = slugify($candidateName);
        if (isset($usedSlugs[$candidateSlug])) {
            $candidateSlug .= "-{$row['id']}";
        }
        $usedSlugs[$candidateSlug] = true;

        $update->execute([
            ':tag' => $candidateName,
            ':slug' => $candidateSlug,
            ':id' => $row['id'],
        ]);
        log_step("  Renamed id {$row['id']}: '{$row['tag']}' -> '$candidateName'");
        $renamed++;
    }
}

log_step("Done: $renamed row(s) renamed across " . count($duplicateGroups) . " duplicate group(s), $collisions same-type collision(s) resolved with an id suffix");
