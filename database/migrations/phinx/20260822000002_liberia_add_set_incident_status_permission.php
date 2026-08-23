<?php

use Phinx\Migration\AbstractMigration;

class LiberiaAddSetIncidentStatusPermission extends AbstractMigration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->execute("INSERT INTO permissions (name, description)
            VALUES ('Set incident status', 'Set the Liberia PBO admin-only Incident Status field on a post')");

        $this->execute("INSERT INTO `roles_permissions` (`role`, `permission`)
            VALUES ('admin', 'Set incident status')");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->execute("DELETE FROM roles_permissions WHERE permission = 'Set incident status'");

        $this->execute("DELETE FROM permissions WHERE name = 'Set incident status'");
    }
}
