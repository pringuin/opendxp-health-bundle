<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Storage;

/**
 * Persistent string storage for the bundle's own state (the last report, the
 * previous table size measurement, ...). Abstracted from the OpenDXP settings
 * store so that its consumers can be unit-tested without a database.
 */
interface SettingsStorageInterface
{
    public function get(string $id): ?string;

    public function set(string $id, string $value): void;
}
