<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Util;

use Symfony\Component\Process\Process;

/**
 * Locates a working composer binary and creates configured processes for it.
 * Shared by the composer_audit and composer_outdated checks so that the
 * (non-trivial) binary resolution and environment setup only lives in one place.
 */
interface ComposerRunnerInterface
{
    /**
     * @return list<string>|null argv prefix of a working composer command, or null if none was found
     */
    public function resolveCommand(): ?array;

    /**
     * @param list<string> $command
     */
    public function createProcess(array $command, int $timeout): Process;
}
