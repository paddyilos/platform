<?php

use Phinx\Migration\AbstractMigration;

class LiberiaCreateContactUsTable extends AbstractMigration
{
    public function change()
    {
        $this->table('contact_us')
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('email', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('phone_number', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('subject', 'string', ['limit' => 255])
            ->addColumn('message', 'text')
            ->addColumn('status', 'integer', ['default' => 0])
            ->addColumn('created', 'integer', ['default' => 0])
            ->addColumn('updated', 'integer', ['null' => true])
            ->create();
    }
}
