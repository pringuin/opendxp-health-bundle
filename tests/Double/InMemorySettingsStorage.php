<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Double;

use Pringuin\OpenDxpHealthBundle\Storage\SettingsStorageInterface;

final class InMemorySettingsStorage implements SettingsStorageInterface
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(
        private array $values = [],
    ) {
    }

    public function get(string $id): ?string
    {
        return $this->values[$id] ?? null;
    }

    public function set(string $id, string $value): void
    {
        $this->values[$id] = $value;
    }
}
