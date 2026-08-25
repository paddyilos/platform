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
        //
        // `Management User` already exists in this deployment's migrated `roles` table
        // (4 real users). `super`/`operatoruser` do not — those role rows were deleted
        // from Liberia's production data before the migration dump was taken. Since
        // `roles_permissions.role` has a foreign key to `roles.name`, granting them the
        // permission ahead of time means creating the (currently unused) role rows too.
        $this->execute("INSERT INTO `roles` (`name`, `display_name`, `description`, `protected`)
            VALUES
                ('super', 'Super', 'Legacy role, unused in this deployment - kept for parity with the UNICC fork Incident Status allowlist', 0),
                ('operatoruser', 'Operator User', 'Legacy role, unused in this deployment - kept for parity with the UNICC fork Incident Status allowlist', 0)");

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

        $this->execute("DELETE FROM roles WHERE name IN ('super', 'operatoruser')");
    }
}
