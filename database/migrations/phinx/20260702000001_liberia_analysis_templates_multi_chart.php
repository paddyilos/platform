<?php

use Phinx\Migration\AbstractMigration;

/**
 * Extends `analysis_templates` for multi-chart reports (the ngx-charts
 * equivalent of the old platform's "+ Add more charts" Flexmonster feature)
 * and template-wide status/tag filters. See ushahidi-client/LIBERIA_CUSTOM.md
 * ("Analysis / Analysis Templates") for the frontend shape this stores.
 *
 * The previously-dead `filters` column (declared but never populated by any
 * frontend code) is renamed to `report_config` and repurposed to hold a JSON
 * array of `{group_by, group_by_attribute_key, chart_type}` objects, one per
 * chart. The original `group_by`/`group_by_attribute_key`/`chart_type`
 * columns are kept (nullable, unused going forward) as a fast single-chart
 * summary and for backward compatibility; any pre-existing row is backfilled
 * into a single-element `report_config` array so it still renders as a
 * 1-chart report under the new model.
 */
class LiberiaAnalysisTemplatesMultiChart extends AbstractMigration
{
    public function up()
    {
        $this->table('analysis_templates')
            ->renameColumn('filters', 'report_config')
            ->addColumn('status_filter', 'text', ['null' => true, 'after' => 'report_config'])
            ->addColumn('tags_filter', 'text', ['null' => true, 'after' => 'status_filter'])
            ->update();

        // Backfill existing single-chart rows into the new report_config shape.
        $this->execute(
            "UPDATE analysis_templates
             SET report_config = JSON_ARRAY(JSON_OBJECT(
                 'group_by', group_by,
                 'group_by_attribute_key', group_by_attribute_key,
                 'chart_type', chart_type
             ))
             WHERE report_config IS NULL AND group_by IS NOT NULL"
        );
    }

    public function down()
    {
        $this->table('analysis_templates')
            ->removeColumn('status_filter')
            ->removeColumn('tags_filter')
            ->renameColumn('report_config', 'filters')
            ->update();
    }
}
