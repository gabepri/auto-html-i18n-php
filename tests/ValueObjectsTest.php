<?php

declare(strict_types=1);

namespace AutoHtmlI18n\Tests;

use AutoHtmlI18n\IcuValidationResult;
use AutoHtmlI18n\IgnoreWordEntry;
use AutoHtmlI18n\TranslationFormat;
use AutoHtmlI18n\TranslationItem;
use AutoHtmlI18n\VariableInfo;
use AutoHtmlI18n\VariableType;
use PHPUnit\Framework\TestCase;

/**
 * Covers the public DTO surface consumers serialize (`toArray()`) and the
 * IgnoreWordEntry input-normalization branches.
 */
final class ValueObjectsTest extends TestCase
{
    public function testIcuValidationResultToArrayOmitsAbsentFields(): void
    {
        $r = new IcuValidationResult(true, TranslationFormat::Plain);
        self::assertSame(['valid' => true, 'format' => 'plain'], $r->toArray());
    }

    public function testIcuValidationResultToArrayIncludesError(): void
    {
        $r = new IcuValidationResult(false, TranslationFormat::Icu, 'boom');
        self::assertSame(
            ['valid' => false, 'format' => 'icu', 'error' => 'boom'],
            $r->toArray(),
        );
    }

    public function testIcuValidationResultToArrayIncludesOutput(): void
    {
        $r = new IcuValidationResult(true, TranslationFormat::Simple, null, 'Hello 5');
        self::assertSame(
            ['valid' => true, 'format' => 'simple', 'output' => 'Hello 5'],
            $r->toArray(),
        );
    }

    public function testIcuValidationResultToArrayIncludesBothErrorAndOutput(): void
    {
        $r = new IcuValidationResult(false, TranslationFormat::Icu, 'boom', 'partial');
        self::assertSame(
            ['valid' => false, 'format' => 'icu', 'error' => 'boom', 'output' => 'partial'],
            $r->toArray(),
        );
    }

    public function testTranslationItemToArrayMinimal(): void
    {
        $item = new TranslationItem('Hello {{0}}', 'Hello 5', [
            new VariableInfo('5', VariableType::Number),
        ]);
        self::assertSame([
            'masked' => 'Hello {{0}}',
            'original' => 'Hello 5',
            'variables' => [['value' => '5', 'type' => 'number']],
        ], $item->toArray());
    }

    public function testTranslationItemToArrayWithScopeAndDebug(): void
    {
        $item = new TranslationItem(
            'Hi',
            'Hi',
            [new VariableInfo('BrandX', VariableType::IgnoreWord, ['gender' => 'female'])],
            'checkout',
            ['selector' => 'p'],
        );
        self::assertSame([
            'masked' => 'Hi',
            'original' => 'Hi',
            'variables' => [[
                'value' => 'BrandX',
                'type' => 'ignoreWord',
                'meta' => ['gender' => 'female'],
            ]],
            'scope' => 'checkout',
            'debug' => ['selector' => 'p'],
        ], $item->toArray());
    }

    public function testTranslationItemToArrayWithNoVariables(): void
    {
        $item = new TranslationItem('Hi', 'Hi', []);
        self::assertSame([], $item->toArray()['variables']);
    }

    public function testIgnoreWordEntryFromPassesThroughInstance(): void
    {
        $entry = new IgnoreWordEntry('BrandX', ['gender' => 'female']);
        self::assertSame($entry, IgnoreWordEntry::from($entry));
    }

    public function testIgnoreWordEntryFromString(): void
    {
        $entry = IgnoreWordEntry::from('BrandX');
        self::assertSame('BrandX', $entry->word);
        self::assertNull($entry->meta);
    }

    public function testIgnoreWordEntryFromArray(): void
    {
        $entry = IgnoreWordEntry::from(['word' => 'BrandX', 'meta' => ['gender' => 'female']]);
        self::assertSame('BrandX', $entry->word);
        self::assertSame(['gender' => 'female'], $entry->meta);
    }

    public function testIgnoreWordEntryFromArrayMissingWordYieldsEmpty(): void
    {
        /** @phpstan-ignore-next-line intentionally malformed input */
        self::assertSame('', IgnoreWordEntry::from(['meta' => ['a' => 'b']])->word);
    }

    public function testIgnoreWordEntryFromArrayEmptyWordYieldsEmpty(): void
    {
        self::assertSame('', IgnoreWordEntry::from(['word' => ''])->word);
    }

    public function testIgnoreWordEntryFromArrayNonStringWordYieldsEmpty(): void
    {
        /** @phpstan-ignore-next-line intentionally malformed input */
        self::assertSame('', IgnoreWordEntry::from(['word' => 123])->word);
    }

    public function testIgnoreWordEntryFromArrayNonArrayMetaIsDropped(): void
    {
        /** @phpstan-ignore-next-line intentionally malformed input */
        $entry = IgnoreWordEntry::from(['word' => 'BrandX', 'meta' => 'nope']);
        self::assertSame('BrandX', $entry->word);
        self::assertNull($entry->meta);
    }

    public function testIgnoreWordEntryNormalizeDropsEmptyWords(): void
    {
        $out = IgnoreWordEntry::normalize(['A', ['word' => ''], ['word' => 'B'], new IgnoreWordEntry('')]);
        self::assertSame(['A', 'B'], array_map(static fn (IgnoreWordEntry $e) => $e->word, $out));
    }
}
