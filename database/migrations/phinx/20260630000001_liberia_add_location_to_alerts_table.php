<?php

use Phinx\Migration\AbstractMigration;

class LiberiaAddLocationToAlertsTable extends AbstractMigration
{
    public function change()
    {
        $this->table('alerts')
            ->addColumn('location', 'string', ['limit' => 255, 'null' => true, 'after' => 'longitude'])
            ->update();
    }
}
