<?php

use Phinx\Migration\AbstractMigration;

class LiberiaCreateAlertsTable extends AbstractMigration
{
    public function change()
    {
        $this->table('alerts')
            ->addColumn('radius', 'integer', ['default' => 0])
            ->addColumn('latitude', 'string', ['limit' => 255])
            ->addColumn('longitude', 'string', ['limit' => 255])
            ->addColumn('email', 'string', ['limit' => 255])
            ->addColumn('categories', 'string', ['limit' => 1000, 'null' => true])
            ->addColumn('status', 'integer', ['default' => 0])
            ->addColumn('hash', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('created', 'integer', ['default' => 0])
            ->addColumn('updated', 'integer', ['null' => true])
            ->addIndex(['email'], ['unique' => true])
            ->create();
    }
}
