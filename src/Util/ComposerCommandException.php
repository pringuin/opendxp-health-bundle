<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Util;

use RuntimeException;

final class ComposerCommandException extends RuntimeException
{
    /**
     * @param list<string> $details additional lines for the check result, e.g. composer's error output
     * @param bool $isCausedByNetworkFailure composer could not reach a remote server, e.g. packagist.org
     */
    public function __construct(
        string $message,
        public readonly array $details = [],
        public readonly bool $isCausedByNetworkFailure = false,
    ) {
        parent::__construct($message);
    }
}
