<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle;

use OpenDxp\Db;
use OpenDxp\Extension\Bundle\Installer\SettingsStoreAwareInstaller;

/**
 * @internal
 */
class Installer extends SettingsStoreAwareInstaller
{
    protected const string USER_PERMISSIONS_CATEGORY = 'Pringuin Health Bundle';

    protected const array USER_PERMISSIONS = [
        'pringuin_health',
    ];

    public function install(): void
    {
        $this->addUserPermissions();
        parent::install();
    }

    public function uninstall(): void
    {
        $this->removeUserPermissions();
        parent::uninstall();
    }

    private function addUserPermissions(): void
    {
        $db = Db::get();

        foreach (self::USER_PERMISSIONS as $permission) {
            $db->insert('users_permission_definitions', [
                $db->quoteIdentifier('key') => $permission,
                $db->quoteIdentifier('category') => self::USER_PERMISSIONS_CATEGORY,
            ]);
        }
    }

    private function removeUserPermissions(): void
    {
        $db = Db::get();

        foreach (self::USER_PERMISSIONS as $permission) {
            $db->delete('users_permission_definitions', [
                $db->quoteIdentifier('key') => $permission,
            ]);
        }
    }
}
