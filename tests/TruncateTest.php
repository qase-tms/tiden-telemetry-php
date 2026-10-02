<?php

declare(strict_types=1);

namespace Tiden\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tiden\Truncate;

final class TruncateTest extends TestCase
{
    /** @return iterable<string,array{string,int,string}> */
    public static function cases(): iterable
    {
        yield 'short string untouched' => ['abc', 10, 'abc'];
        yield 'ascii cut' => ['abcdef', 3, 'abc'];
        yield 'zero bytes' => ['abc', 0, ''];
        yield 'two-byte char not split' => ['aé', 2, 'a'];         // é = C3 A9
        yield 'three-byte char not split' => ['a€', 3, 'a'];       // € = E2 82 AC
        yield 'four-byte char not split' => ['a😀', 4, 'a'];       // 😀 = F0 9F 98 80
        yield 'whole multibyte char kept' => ['a€b', 4, 'a€'];
        yield 'cut right after a char' => ['ééé', 4, 'éé'];
        yield 'cut inside the second char' => ['ééé', 3, 'é'];
    }

    #[DataProvider('cases')]
    public function test_utf8(string $in, int $bytes, string $expected): void
    {
        $out = Truncate::utf8($in, $bytes);

        $this->assertSame($expected, $out);
        $this->assertLessThanOrEqual(max(0, $bytes), strlen($out));
        $this->assertTrue(preg_match('//u', $out) === 1, 'result must be valid UTF-8');
    }

    #[DataProvider('cases')]
    public function test_fallback_without_mbstring(string $in, int $bytes, string $expected): void
    {
        $this->assertSame($expected, Truncate::cutBytes($in, $bytes));
    }

    public function test_fallback_matches_mb_strcut_on_random_text(): void
    {
        if (! function_exists('mb_strcut')) {
            $this->markTestSkipped('mbstring not loaded');
        }
        $text = str_repeat('aé€😀Ж', 50);
        for ($n = 1; $n < 80; $n++) {
            $this->assertSame(mb_strcut($text, 0, $n, 'UTF-8'), Truncate::cutBytes($text, $n), "cut at $n");
        }
    }
}
