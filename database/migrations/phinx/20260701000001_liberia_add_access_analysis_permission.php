<?php

use Phinx\Migration\AbstractMigration;

class LiberiaAddAccessAnalysisPermission extends AbstractMigration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->execute("INSERT INTO permissions (name, description)
            VALUES ('Access analysis', 'Access the Liberia PBO analysis dashboard and saved report templates')");

        $this->execute("INSERT INTO `roles_permissions` (`role`, `permission`)
            VALUES ('admin', 'Access analysis')");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->execute("DELETE FROM roles_permissions WHERE permission = 'Access analysis'");

        $this->execute("DELETE FROM permissions WHERE name = 'Access analysis'");
    }
}
