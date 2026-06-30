<?php

use Phinx\Migration\AbstractMigration;

class AddLiberiaMgmtLevels extends AbstractMigration
{
    /**
     * Add Liberia geographic hierarchy columns to posts.
     * mgmt_lev_1 = county, mgmt_lev_2 = district.
     * Required for geographic alert matching and LERN data import.
     */
    public function change()
    {
        $this->table('posts')
            ->addColumn('mgmt_lev_1', 'string', [
                'limit' => 100,
                'default' => '',
                'null' => false,
                'after' => 'post_date',
            ])
            ->addColumn('mgmt_lev_2', 'string', [
                'limit' => 100,
                'default' => '',
                'null' => false,
                'after' => 'mgmt_lev_1',
            ])
            ->update();
    }
}
