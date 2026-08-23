<?php

use Phinx\Migration\AbstractMigration;

class LiberiaAddIncidentStatusToPosts extends AbstractMigration
{
    /**
     * Add the Liberia PBO "Incident Status" workflow field to posts.
     * Independent of the stock `status` (published/draft/archived) column —
     * see IncidentStatus.php and LIBERIA_CUSTOM.md.
     */
    public function change()
    {
        $this->table('posts')
            ->addColumn('incident_status', 'string', [
                'limit' => 30,
                'default' => null,
                'null' => true,
                'after' => 'status',
            ])
            ->update();
    }
}
