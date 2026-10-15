<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Util;

final class ByteFormatter
{
    private const array UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public static function format(int $bytes): string
    {
        $value = (float) $bytes;
        $unitIndex = 0;
        while ($value >= 1024 && $unitIndex < count(self::UNITS) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return sprintf($unitIndex === 0 ? '%.0f %s' : '%.1f %s', $value, self::UNITS[$unitIndex]);
    }
}
