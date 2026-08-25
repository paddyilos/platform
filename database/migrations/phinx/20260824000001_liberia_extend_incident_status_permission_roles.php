<?php

use Phinx\Migration\AbstractMigration;

class LiberiaExtendIncidentStatusPermissionRoles extends AbstractMigration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Matches the legacy UNICC fork's role allowlist for setting Incident Status
        // (AdminAccess::isUserAllowedToUpdateStatus()): admin, super, Management User,
        // operatoruser. `admin` is already seeded by
        // 20260822000002_liberia_add_set_incident_status_permission.php.
        $this->execute("INSERT INTO `roles_permissions` (`role`, `permission`)
            VALUES
                ('Management User', 'Set incident status'),
                ('super', 'Set incident status'),
                ('operatoruser', 'Set incident status')");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->execute("DELETE FROM roles_permissions WHERE permission = 'Set incident status'
            AND role IN ('Management User', 'super', 'operatoruser')");
    }
}
