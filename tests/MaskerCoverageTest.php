<?php

declare(strict_types=1);

namespace AutoHtmlI18n\Tests;

use AutoHtmlI18n\IcuValidationResult;
use AutoHtmlI18n\Masker;
use AutoHtmlI18n\TranslationFormat;
use AutoHtmlI18n\VariableInfo;
use AutoHtmlI18n\VariableType;
use PHPUnit\Framework\TestCase;

/**
 * Edge/defensive branches of {@see Masker} that the behavioral suites and the
 * shared fixtures don't reach: malformed inline markup, opaque markup/ignored
 * substitution in validateIcu(), unknown tag markers, ICU evaluation failures,
 * and the PCRE-failure fallbacks.
 */
final class MaskerCoverageTest extends TestCase
{
    private const ALLOWED = ['a', 'b', 'i', 'u', 'strong', 'em', 'span', 'small', 'mark', 'del'];

    private string $backtrackLimit = '';

    protected function setUp(): void
    {
        $this->backtrackLimit = (string) ini_get('pcre.backtrack_limit');
    }

    protected function tearDown(): void
    {
        ini_set('pcre.backtrack_limit', $this->backtrackLimit);
    }

    /**
     * @param array<string|array{word:string,meta?:array<string,string>}|\AutoHtmlI18n\IgnoreWordEntry> $ignoreWords
     */
    private function masker(array $ignoreWords = []): Masker
    {
        return new Masker($ignoreWords, self::ALLOWED);
    }

    // --- Phase 1 tag pairing edge cases ------------------------------------

    public function testSelfClosingAllowedTagIsOpaqueMarkupNotAPair(): void
    {
        $result = $this->masker()->mask('Hello <b/> world');

        // No tag pairing happened, so nothing lands in tagAttributes...
        self::assertSame([], $result->tagAttributes);
        // ...and the self-closing tag is masked as one opaque markup variable.
        self::assertSame('Hello {{0}} world', $result->masked);
        self::assertCount(1, $result->variables);
        self::assertSame(VariableType::Markup, $result->variables[0]->type);
        self::assertSame('<b/>', $result->variables[0]->value);

        self::assertSame(
            'Hello <b/> world',
            $this->masker()->unmask($result->masked, $result->variables, $result->tagAttributes, 'en'),
        );
    }

    public function testUnmatchedClosingTagIsOpaqueMarkup(): void
    {
        $result = $this->masker()->mask('Hello </b> world');

        self::assertSame([], $result->tagAttributes);
        self::assertSame('Hello {{0}} world', $result->masked);
        self::assertCount(1, $result->variables);
        self::assertSame(VariableType::Markup, $result->variables[0]->type);
        self::assertSame('</b>', $result->variables[0]->value);
    }

    public function testExtraClosingTagAfterAMatchedPairIsOpaqueMarkup(): void
    {
        $result = $this->masker()->mask('<b>bold</b></b> tail');

        self::assertArrayHasKey('b0', $result->tagAttributes);
        // The matched pair keys normally; the surplus closer becomes markup.
        self::assertSame('<b0>bold</b0>{{0}} tail', $result->masked);
        self::assertCount(1, $result->variables);
        self::assertSame(VariableType::Markup, $result->variables[0]->type);
        self::assertSame('</b>', $result->variables[0]->value);
    }

    // --- validateIcu() opaque-variable substitution -------------------------

    public function testValidateIcuSimpleFormatSubstitutesMarkupVerbatim(): void
    {
        $result = $this->masker()->validateIcu(
            'Voir {{0}} ici',
            [new VariableInfo('<svg class="x"><use href="#y"/></svg>', VariableType::Markup)],
            'en',
        );

        self::assertTrue($result->valid);
        self::assertSame(TranslationFormat::Simple, $result->format);
        // Restored verbatim: the sanitizer never escapes source markup.
        self::assertSame('Voir <svg class="x"><use href="#y"/></svg> ici', $result->output);
    }

    public function testValidateIcuSimpleFormatSubstitutesIgnoredRegionVerbatim(): void
    {
        $result = $this->masker()->validateIcu(
            'Bonjour {{0}}',
            [new VariableInfo('<span data-i18n-ignore>Jean</span>', VariableType::Ignored)],
            'ar',
        );

        self::assertTrue($result->valid);
        // Ignored/markup values are not bidi-isolated even for an RTL locale.
        self::assertSame('Bonjour <span data-i18n-ignore>Jean</span>', $result->output);
    }

    public function testValidateIcuIcuFormatSubstitutesMarkupVerbatim(): void
    {
        $result = $this->masker()->validateIcu(
            'Voir {0} ici',
            [new VariableInfo('<svg class="x"/>', VariableType::Markup)],
            'en',
        );

        self::assertTrue($result->valid);
        self::assertSame(TranslationFormat::Icu, $result->format);
        self::assertSame('Voir <svg class="x"/> ici', $result->output);
    }

    public function testValidateIcuIcuFormatSubstitutesIgnoredRegionVerbatim(): void
    {
        $result = $this->masker()->validateIcu(
            '{0} a {1, plural, one {# message} other {# messages}}',
            [
                new VariableInfo('<b data-i18n-ignore>Ada</b>', VariableType::Ignored),
                new VariableInfo('2', VariableType::Number),
            ],
            'en',
        );

        self::assertTrue($result->valid);
        self::assertSame('<b data-i18n-ignore>Ada</b> a 2 messages', $result->output);
    }

    // --- restoreTagAttributes() unknown marker ------------------------------

    public function testValidateIcuLeavesUnknownTagMarkerIntact(): void
    {
        // tagAttributes is non-empty (so restoration runs) but has no entry for
        // the marker the translation used — the marker must survive untouched
        // rather than being restored to a bare, meaningless tag.
        $result = $this->masker()->validateIcu(
            'Click <a7>here</a7> now',
            [],
            'en',
            ['a0' => ['href' => '/x']],
        );

        self::assertTrue($result->valid);
        self::assertNotNull($result->output);
        // `a7` is not a real tag name, so sanitizeTags escapes the leftover.
        self::assertStringContainsString('&lt;a7&gt;here&lt;/a7&gt;', $result->output);
        self::assertStringNotContainsString('<a href="/x">', $result->output);
    }

    public function testValidateIcuRestoresKnownMarkerAlongsideUnknownOne(): void
    {
        $result = $this->masker()->validateIcu(
            '<a0>known</a0> and <a7>unknown</a7>',
            [],
            'en',
            ['a0' => ['href' => '/x']],
        );

        self::assertTrue($result->valid);
        self::assertNotNull($result->output);
        self::assertStringContainsString('<a href="/x">known</a>', $result->output);
        self::assertStringContainsString('&lt;a7&gt;unknown&lt;/a7&gt;', $result->output);
    }

    // --- ICU evaluation failures -------------------------------------------

    public function testValidateIcuReportsFormatterRuntimeFailure(): void
    {
        // Pattern compiles fine, but ICU cannot format a non-numeric string as
        // a date — MessageFormatter::format() returns false at runtime.
        $result = $this->masker()->validateIcu(
            'Due {0, date, short}',
            [new VariableInfo('not-a-date', VariableType::Url)],
            'en',
        );

        self::assertFalse($result->valid);
        self::assertSame(TranslationFormat::Icu, $result->format);
        self::assertNotNull($result->error);
        self::assertNotSame('', $result->error);
        self::assertNull($result->output);
    }

    public function testUnmaskFallsBackToOriginalOnFormatterRuntimeFailure(): void
    {
        $m = $this->masker();
        $original = 'Due 2024-01-02';
        $mask = $m->mask($original);

        $out = $m->unmask(
            'Due {0, date, short}',
            [new VariableInfo('not-a-date', VariableType::Url)],
            $mask->tagAttributes,
            'en',
            $original,
        );

        self::assertSame($original, $out);
    }

    // --- PCRE failure fallbacks --------------------------------------------

    public function testMaskFallsBackWhenTagPassExceedsPcreLimits(): void
    {
        $m = $this->masker();
        ini_set('pcre.backtrack_limit', '1');
        try {
            $result = $m->mask('Hello <b>world</b>');
        } finally {
            ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }

        // Phase 1 bailed out: the text passes through untouched instead of
        // becoming null/empty, and no tag pairs were recorded.
        self::assertSame([], $result->tagAttributes);
        // The raw tags fall through to phase 2 and become opaque markup
        // variables instead of paired <tagN> markers.
        self::assertSame('Hello {{0}}world{{1}}', $result->masked);
        self::assertSame(VariableType::Markup, $result->variables[0]->type);
        self::assertSame('<b>', $result->variables[0]->value);
    }

    public function testValidateIcuReportsPreprocessFailureWhenPcreLimitsExceeded(): void
    {
        $m = $this->masker();
        ini_set('pcre.backtrack_limit', '1');
        try {
            $result = $m->validateIcu('{0} <b0>x</b0>', [
                new VariableInfo('5', VariableType::Number),
            ], 'en');
        } finally {
            ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }

        self::assertInstanceOf(IcuValidationResult::class, $result);
        self::assertFalse($result->valid);
        self::assertSame(TranslationFormat::Icu, $result->format);
        self::assertSame('failed to preprocess pattern', $result->error);
    }
}
