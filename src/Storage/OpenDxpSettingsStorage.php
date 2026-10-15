<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Storage;

use OpenDxp\Model\Tool\SettingsStore;

/**
 * Keeps the bundle state in the OpenDXP settings store, under a scope of its
 * own so it cannot collide with entries of other bundles.
 */
final class OpenDxpSettingsStorage implements SettingsStorageInterface
{
    private const string SCOPE = 'pringuin_opendxp_health';

    public function get(string $id): ?string
    {
        $entry = SettingsStore::get($id, self::SCOPE);

        return $entry === null ? null : (string) $entry->getData();
    }

    public function set(string $id, string $value): void
    {
        SettingsStore::set($id, $value, SettingsStore::TYPE_STRING, self::SCOPE);
    }
}
