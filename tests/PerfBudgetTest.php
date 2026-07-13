<?php

declare(strict_types=1);

namespace AutoHtmlI18n\Tests;

use AutoHtmlI18n\Masker;
use PHPUnit\Framework\TestCase;

/**
 * Performance budgets.
 *
 * The JS port asserts on operation counts, which is exact and never flaky. PHP gives us
 * no way to count PCRE probes from userland, so these are timing-based — but they are
 * deliberately *order-of-magnitude* guards, not precision budgets. The margins below are
 * 30x or more over the real cost, so only an algorithmic blowup trips them, never a
 * loaded CI box.
 *
 * The regression they exist for: mask() used to probe the variable regex at every byte,
 * against a fresh substr() of the remainder of the string — quadratic in both time and
 * allocation. Masking one 1.2KB paragraph cost ~8.8ms; a 20KB page section would have
 * cost seconds.
 */
final class PerfBudgetTest extends TestCase
{
    private const ALLOWED_INLINE_TAGS = ['a', 'b', 'i', 'u', 'strong', 'em', 'span', 'small', 'mark', 'del'];

    private function makeMasker(): Masker
    {
        return new Masker(['Acme', 'Widget Pro'], self::ALLOWED_INLINE_TAGS);
    }

    public function testMaskingALargeBlockStaysWellUnderABudget(): void
    {
        $masker = $this->makeMasker();
        // ~20KB, the size of a content-heavy page section.
        $text = str_repeat('The quick brown fox jumps over the lazy dog. ', 460);
        self::assertGreaterThan(20000, strlen($text));

        $start = microtime(true);
        $masker->mask($text);
        $elapsedMs = (microtime(true) - $start) * 1000;

        // Linear masking does this in ~20ms. Per-byte probing takes seconds. The 1000ms
        // ceiling is far above any plausible machine variance and far below the quadratic
        // cost, so it discriminates the two without flaking.
        self::assertLessThan(
            1000,
            $elapsedMs,
            sprintf('masking 20KB took %.0fms — suspect a non-linear scan in Masker::mask()', $elapsedMs)
        );
    }

    public function testMaskingAPageOfContentStaysWellUnderABudget(): void
    {
        $masker = $this->makeMasker();
        // Markup-heavy, variable-heavy content — the shape a real page section takes.
        $text = str_repeat(
            'Order 42 for <strong>Acme</strong> shipped on 04/12/2026 to the <a href="/depot/7">depot</a>. ',
            200
        );

        $start = microtime(true);
        $masker->mask($text);
        $elapsedMs = (microtime(true) - $start) * 1000;

        self::assertLessThan(
            1000,
            $elapsedMs,
            sprintf('masking a page section took %.0fms — suspect a non-linear scan', $elapsedMs)
        );
    }
}
