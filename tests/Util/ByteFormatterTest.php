<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Tests\Util;

use Pringuin\OpenDxpHealthBundle\Util\ByteFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ByteFormatter::class)]
final class ByteFormatterTest extends TestCase
{
    #[DataProvider('formatProvider')]
    public function testFormatsBytesWithTheLargestFittingUnit(int $bytes, string $expected): void
    {
        self::assertSame($expected, ByteFormatter::format($bytes));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function formatProvider(): iterable
    {
        yield 'zero' => [0, '0 B'];
        yield 'bytes' => [512, '512 B'];
        yield 'exactly one kilobyte' => [1024, '1.0 KB'];
        yield 'kilobytes' => [1536, '1.5 KB'];
        yield 'megabytes' => [5 * 1024 * 1024, '5.0 MB'];
        yield 'gigabytes' => [3 * 1024 ** 3, '3.0 GB'];
        yield 'terabytes' => [2 * 1024 ** 4, '2.0 TB'];
        yield 'stays at terabytes beyond the largest unit' => [5 * 1024 ** 5, '5120.0 TB'];
    }
}
