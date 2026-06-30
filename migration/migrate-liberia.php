<?php
/**
 * iReport Liberia → Ushahidi Data Migration Script
 *
 * Migrates data from the old UNICC fork (ireport-liberia) to the
 * official Ushahidi platform database (ushahidi).
 *
 * Usage: php migration/migrate-liberia.php
 *
 * Prerequisites:
 *   - ireport-mysql container running on localhost:3306
 *   - ushahidi-mysql container running on localhost:33061
 *   - Phinx migration 20260605000001 (mgmt_lev columns) already applied
 *
 * Run from: /Users/yddapsoli/Sites/blueseas/earlywarningpbo/ushahidi-api/
 */

// ── Connection config ────────────────────────────────────────────────────────
$srcDsn = 'mysql:host=127.0.0.1;port=3306;dbname=ireport-liberia;charset=utf8mb4';
$dstDsn = 'mysql:host=127.0.0.1;port=33061;dbname=ushahidi;charset=utf8mb4';

try {
    $src = new PDO($srcDsn, 'root', 'rootpass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dst = new PDO($dstDsn, 'ushahidi', 'ushahidi', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function log_step(string $msg): void {
    echo "[" . date('H:i:s') . "] $msg\n";
}

function migrate_table(
    PDO $src,
    PDO $dst,
    string $srcTable,
    string $dstTable,
    callable $rowMapper,   // maps one source row to target row (or null to skip)
    bool $truncate = false
): int {
    if ($truncate) {
        $dst->exec("SET FOREIGN_KEY_CHECKS=0");
        $dst->exec("TRUNCATE TABLE `$dstTable`");
        $dst->exec("SET FOREIGN_KEY_CHECKS=1");
    }

    $rows = $src->query("SELECT * FROM `$srcTable`")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        log_step("  $srcTable → $dstTable: 0 rows (empty source)");
        return 0;
    }

    $count = 0;
    $skipped = 0;
    $dst->beginTransaction();
    $dst->exec("SET FOREIGN_KEY_CHECKS=0");

    foreach ($rows as $row) {
        $mapped = $rowMapper($row);
        if ($mapped === null) {
            $skipped++;
            continue;
        }
        $cols = array_keys($mapped);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $stmt = $dst->prepare("INSERT IGNORE INTO `$dstTable` ($colList) VALUES ($placeholders)");
        $stmt->execute(array_values($mapped));
        $count++;
    }

    $dst->exec("SET FOREIGN_KEY_CHECKS=1");
    $dst->commit();
    log_step("  $srcTable → $dstTable: $count inserted" . ($skipped ? ", $skipped skipped" : ""));
    return $count;
}

// ── Start migration ───────────────────────────────────────────────────────────
log_step("Starting iReport Liberia → Ushahidi data migration");
$dst->exec("SET FOREIGN_KEY_CHECKS=0");


// ── 1. FORMS ─────────────────────────────────────────────────────────────────
log_step("1/13 Migrating forms...");
migrate_table($src, $dst, 'forms', 'forms', function ($r) {
    return [
        'id'                 => $r['id'],
        'parent_id'          => $r['parent_id'],
        'name'               => $r['name'],
        'description'        => $r['description'],
        'type'               => $r['type'],
        'created'            => $r['created'],
        'updated'            => $r['updated'],
        'disabled'           => $r['disabled'],
        'require_approval'   => $r['require_approval'],
        'everyone_can_create'=> $r['everyone_can_create'],
        'color'              => $r['color'],
        'hide_author'        => $r['hide_author'],
        'hide_time'          => $r['hide_time'],
        'hide_location'      => $r['hide_location'],
        'targeted_survey'    => $r['targeted_survey'],
        'base_language'      => 'en_US',
    ];
}, true);

// ── 2. FORM_STAGES ───────────────────────────────────────────────────────────
log_step("2/13 Migrating form_stages...");
migrate_table($src, $dst, 'form_stages', 'form_stages', function ($r) {
    return [
        'id'                    => $r['id'],
        'form_id'               => $r['form_id'],
        'label'                 => $r['label'],
        'priority'              => $r['priority'],
        'icon'                  => $r['icon'],
        'required'              => $r['required'],
        'type'                  => $r['type'],
        'description'           => $r['description'],
        'show_when_published'   => $r['show_when_published'],
        'task_is_internal_only' => $r['task_is_internal_only'],
    ];
}, true);

// ── 3. FORM_ATTRIBUTES ───────────────────────────────────────────────────────
log_step("3/13 Migrating form_attributes...");
migrate_table($src, $dst, 'form_attributes', 'form_attributes', function ($r) {
    return [
        'id'               => $r['id'],
        'key'              => $r['key'],
        'label'            => $r['label'],
        'instructions'     => $r['instructions'],
        'input'            => $r['input'],
        'type'             => $r['type'],
        'required'         => $r['required'],
        'default'          => $r['default'],
        'priority'         => $r['priority'],
        'options'          => $r['options'],
        'cardinality'      => $r['cardinality'],
        'config'           => $r['config'],
        'form_stage_id'    => $r['form_stage_id'],
        'response_private' => $r['response_private'],
        'description'      => $r['description'],
    ];
}, true);

// ── 4. ROLES ─────────────────────────────────────────────────────────────────
log_step("4/13 Migrating roles...");
migrate_table($src, $dst, 'roles', 'roles', function ($r) {
    return [
        'id'           => $r['id'],
        'name'         => $r['name'],
        // short_name removed in new Ushahidi schema
        'display_name' => $r['display_name'],
        'description'  => $r['description'],
        'protected'    => $r['protected'],
    ];
});

// ── 5. PERMISSIONS ───────────────────────────────────────────────────────────
log_step("5/13 Migrating permissions...");
migrate_table($src, $dst, 'permissions', 'permissions', function ($r) {
    return [
        'id'          => $r['id'],
        'name'        => $r['name'],
        'description' => $r['description'],
    ];
});

// ── 6. ROLES_PERMISSIONS ─────────────────────────────────────────────────────
log_step("6/13 Migrating roles_permissions...");
migrate_table($src, $dst, 'roles_permissions', 'roles_permissions', function ($r) {
    return [
        'id'         => $r['id'],
        'role'       => $r['role'],
        'permission' => $r['permission'],
    ];
});

// ── 7. USERS ─────────────────────────────────────────────────────────────────
log_step("7/13 Migrating users...");
migrate_table($src, $dst, 'users', 'users', function ($r) {
    // Skip Ushahidi default admin (already exists in target)
    if ($r['email'] === 'admin@example.com') return null;
    return [
        'id'              => $r['id'],
        'email'           => $r['email'],
        'realname'        => $r['realname'],
        'password'        => $r['password'],
        'role'            => $r['role'],
        'logins'          => $r['logins'],
        'failed_attempts' => $r['failed_attempts'],
        'last_login'      => $r['last_login'],
        'last_attempt'    => $r['last_attempt'],
        'created'         => $r['created'],
        'updated'         => $r['updated'],
        'language'        => $r['language'],
        // mgmt_lev, organization, position, department dropped (not in new schema)
    ];
});

// ── 8. TAGS ──────────────────────────────────────────────────────────────────
log_step("8/13 Migrating tags...");
migrate_table($src, $dst, 'tags', 'tags', function ($r) {
    return [
        'id'            => $r['id'],
        'parent_id'     => $r['parent_id'],
        'user_id'       => null,
        'tag'           => $r['tag'],
        'slug'          => $r['slug'],
        'type'          => $r['type'],
        'color'         => $r['color'],
        'icon'          => substr($r['icon'] ?? 'tag', 0, 20),  // truncate fa fa-* to 20 chars
        'description'   => $r['description'],
        'role'          => $r['role'],
        'priority'      => $r['priority'],
        'created'       => $r['created'],
        'base_language' => 'en_US',
    ];
}, true);

// ── 9. POSTS ─────────────────────────────────────────────────────────────────
log_step("9/13 Migrating posts...");
migrate_table($src, $dst, 'posts', 'posts', function ($r) {
    return [
        'id'               => $r['id'],
        'parent_id'        => $r['parent_id'],
        'form_id'          => $r['form_id'],
        'user_id'          => $r['user_id'],
        'type'             => 'report',  // 'lern' is UNICC-specific; new Ushahidi only uses 'report'
        'title'            => $r['title'],
        'slug'             => $r['slug'],
        'content'          => $r['content'],
        'author_email'     => $r['author_email'],
        'author_realname'  => $r['author_realname'],
        'contact_id'       => null,
        'status'           => $r['status'],
        'source'           => null,
        'published_to'     => $r['published_to'],
        'locale'           => $r['locale'],
        'created'          => $r['created'],
        'updated'          => $r['updated'],
        'post_date'        => $r['post_date'],
        'mgmt_lev_1'       => $r['mgmt_lev_1'] ?? '',
        'mgmt_lev_2'       => $r['mgmt_lev_2'] ?? '',
        'base_language'    => 'en_US',
        'metadata'         => null,
        // Dropped: priority, mgmt_lev_3, mgmt_lev, updated_by, import_post_id, published
    ];
}, true);

// ── 10. POST ATTRIBUTE TABLES ─────────────────────────────────────────────────
log_step("10/13 Migrating post attribute tables...");

foreach (['post_varchar', 'post_int', 'post_decimal', 'post_text', 'post_point', 'post_geometry'] as $t) {
    migrate_table($src, $dst, $t, $t, function ($r) {
        return [
            'id'                => $r['id'],
            'post_id'           => $r['post_id'],
            'form_attribute_id' => $r['form_attribute_id'],
            'value'             => $r['value'],
            'created'           => $r['created'],
        ];
    }, true);
}

// post_datetime has extra metadata column in new schema
migrate_table($src, $dst, 'post_datetime', 'post_datetime', function ($r) {
    return [
        'id'                => $r['id'],
        'post_id'           => $r['post_id'],
        'form_attribute_id' => $r['form_attribute_id'],
        'value'             => $r['value'],
        'created'           => $r['created'],
        'metadata'          => null,
    ];
}, true);

// post_markdown → migrate into post_text (no post_markdown table in new schema)
migrate_table($src, $dst, 'post_markdown', 'post_text', function ($r) {
    return [
        // id intentionally omitted so auto_increment assigns new IDs (avoid clash with post_text)
        'post_id'           => $r['post_id'],
        'form_attribute_id' => $r['form_attribute_id'],
        'value'             => $r['value'],
        'created'           => $r['created'],
    ];
});

// ── 11. JUNCTION TABLES ──────────────────────────────────────────────────────
log_step("11/13 Migrating junction tables...");

// posts_tags: new schema has extra form_attribute_id and created columns
migrate_table($src, $dst, 'posts_tags', 'posts_tags', function ($r) {
    return [
        'post_id'           => $r['post_id'],
        'tag_id'            => $r['tag_id'],
        'form_attribute_id' => 0,
        'created'           => 0,
    ];
}, true);

// posts_sets: direct copy
migrate_table($src, $dst, 'posts_sets', 'posts_sets', function ($r) {
    return [
        'id'       => $r['id'],
        'post_id'  => $r['post_id'],
        'set_id'   => $r['set_id'],
        'created'  => $r['created'] ?? 0,
    ];
}, true);

// posts_media: direct copy
migrate_table($src, $dst, 'posts_media', 'posts_media', function ($r) {
    return [
        'post_id'  => $r['post_id'],
        'media_id' => $r['media_id'],
    ];
}, true);

// ── 12. MEDIA ────────────────────────────────────────────────────────────────
log_step("12/13 Migrating media...");
migrate_table($src, $dst, 'media', 'media', function ($r) {
    return [
        'id'         => $r['id'],
        'user_id'    => $r['user_id'],
        'mime'       => $r['mime'],
        'caption'    => $r['caption'],
        'o_filename' => $r['o_filename'],
        'o_size'     => $r['o_size'],
        'o_width'    => $r['o_width'],
        'o_height'   => $r['o_height'],
        'created'    => $r['created'],
        'updated'    => $r['updated'],
    ];
}, true);

// ── 13. MESSAGES ─────────────────────────────────────────────────────────────
log_step("13/13 Migrating messages...");
migrate_table($src, $dst, 'messages', 'messages', function ($r) {
    return [
        'id'                    => $r['id'],
        'parent_id'             => $r['parent_id'],
        'contact_id'            => $r['contact_id'],
        'post_id'               => $r['post_id'],
        'user_id'               => $r['user_id'],
        'data_source'           => $r['data_source'],
        'data_source_message_id'=> $r['data_source_message_id'],
        'title'                 => $r['title'],
        'message'               => $r['message'],
        'datetime'              => $r['datetime'],
        'type'                  => $r['type'],
        'status'                => $r['status'],
        'direction'             => $r['direction'],
        'created'               => $r['created'],
        'additional_data'       => $r['additional_data'],
        'notification_post_id'  => $r['notification_post_id'],
    ];
}, true);

// ── Re-enable FK checks ───────────────────────────────────────────────────────
$dst->exec("SET FOREIGN_KEY_CHECKS=1");

// ── Final verification ────────────────────────────────────────────────────────
log_step("Migration complete. Verification counts:");
foreach (['posts', 'users', 'messages', 'forms', 'form_attributes', 'tags', 'post_varchar', 'media'] as $t) {
    $count = $dst->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "  $t: $count\n";
}
