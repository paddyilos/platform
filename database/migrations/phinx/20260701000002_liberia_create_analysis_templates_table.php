<?php

use Phinx\Migration\AbstractMigration;

class LiberiaCreateAnalysisTemplatesTable extends AbstractMigration
{
    public function change()
    {
        $this->table('analysis_templates')
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('user_id', 'integer', ['null' => true])
            ->addColumn('form_id', 'integer', ['null' => true])
            ->addColumn('date_range_start', 'integer', ['null' => true])
            ->addColumn('date_range_end', 'integer', ['null' => true])
            ->addColumn('group_by', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('group_by_attribute_key', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('chart_type', 'string', ['limit' => 30, 'default' => 'bar'])
            ->addColumn('filters', 'text', ['null' => true])
            ->addColumn('created', 'integer', ['default' => 0])
            ->addColumn('updated', 'integer', ['null' => true])
            ->addIndex(['user_id'])
            ->addIndex(['form_id'])
            ->create();
    }
}
