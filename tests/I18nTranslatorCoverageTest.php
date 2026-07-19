<?php

declare(strict_types=1);

namespace AutoHtmlI18n\Tests;

use AutoHtmlI18n\I18nTranslator;
use AutoHtmlI18n\TranslationItem;
use PHPUnit\Framework\TestCase;

/**
 * Edge-path coverage for I18nTranslator: attribute short-circuits, the pending
 * write-back guards, the debug payload, and the small accessors.
 */
final class I18nTranslatorCoverageTest extends TestCase
{
    /**
     * @param array<string,mixed> $extra
     */
    private function make(array $extra = []): I18nTranslator
    {
        return new I18nTranslator(array_merge([
            'locale' => 'es',
            'onMissingTranslation' => static fn (array $items, string $locale): array => [],
        ], $extra));
    }

    public function testGetLocaleReturnsConfiguredLocale(): void
    {
        $i = $this->make(['locale' => 'fr-CA']);
        self::assertSame('fr-CA', $i->getLocale());
        $i->setLocale('de');
        self::assertSame('de', $i->getLocale());
    }

    public function testGetTranslationReturnsResolvedValue(): void
    {
        $i = $this->make([
            'initialCache' => [
                'Hello world' => 'Hola mundo',
                'Bye' => ['formal' => 'Adios'],
            ],
        ]);

        self::assertSame('Hola mundo', $i->getTranslation('Hello world'));
        self::assertSame(['formal' => 'Adios'], $i->getTranslation('Bye'));
        self::assertNull($i->getTranslation('Never seen'));
    }

    public function testGetTranslationHonoursExplicitLocale(): void
    {
        $i = $this->make();
        $i->setCache('fr', ['Hello world' => 'Bonjour le monde']);

        self::assertSame('Bonjour le monde', $i->getTranslation('Hello world', 'fr'));
        self::assertNull($i->getTranslation('Hello world'));
    }

    public function testAttributeWithNoTranslatableContentIsNotReported(): void
    {
        $reported = [];
        $i = $this->make([
            'onMissingTranslation' => function (array $items) use (&$reported): array {
                foreach ($items as $item) {
                    $reported[] = $item->masked;
                }

                return [];
            },
        ]);

        $out = $i->translateHtml('<img alt="12345" src="/x.png">');

        self::assertSame([], $reported);
        self::assertStringContainsString('alt="12345"', $out);
    }

    public function testAttributeAlreadyReportedIsSkippedOnASecondPass(): void
    {
        $calls = 0;
        $i = $this->make([
            'onMissingTranslation' => function (array $items) use (&$calls): array {
                $calls++;

                return [];
            },
        ]);

        $i->translateHtml('<img alt="A photo of a cat" src="/x.png">');
        self::assertSame(1, $calls);

        // Second pass: the key is marked reported, so nothing is batched at all
        // and onMissingTranslation is not called again.
        $out = $i->translateHtml('<img alt="A photo of a cat" src="/x.png">');
        self::assertSame(1, $calls);
        self::assertStringContainsString('alt="A photo of a cat"', $out);
    }

    public function testTextAlreadyReportedIsSkippedOnASecondPass(): void
    {
        $calls = 0;
        $i = $this->make([
            'onMissingTranslation' => function (array $items) use (&$calls): array {
                $calls++;

                return [];
            },
        ]);

        $i->translateHtml('<p>Hello world</p>');
        $out = $i->translateHtml('<p>Hello world</p>');

        self::assertSame(1, $calls);
        self::assertStringContainsString('Hello world', $out);
    }

    public function testNonArrayCallbackResultIsTreatedAsNoTranslations(): void
    {
        $i = $this->make([
            // Deliberately violates the documented contract.
            'onMissingTranslation' => static fn (array $items, string $locale) => 'not an array',
        ]);

        $out = $i->translateHtml('<p>Hello world</p>');

        self::assertStringContainsString('Hello world', $out);
        self::assertNull($i->getTranslation('Hello world'));
    }

    public function testPendingWriteBackSkipsScopeKeyedValueThatDoesNotMatchTheScope(): void
    {
        $i = $this->make([
            'onMissingTranslation' => static fn (array $items, string $locale): array => [
                'Hello world' => ['formal' => 'Hola mundo'],
            ],
        ]);

        // No data-i18n-scope on the element, so Resolver::resolve() returns null for
        // the scope-keyed value and the pending node is left untouched.
        $out = $i->translateHtml('<p>Hello world</p>');

        self::assertStringContainsString('Hello world', $out);
        self::assertStringNotContainsString('Hola mundo', $out);
        // The entry is still stored, and applies where the scope does match.
        self::assertSame(['formal' => 'Hola mundo'], $i->getTranslation('Hello world'));
        $scoped = $i->translateHtml('<p data-i18n-scope="formal">Hello world</p>');
        self::assertStringContainsString('Hola mundo', $scoped);
    }

    public function testAttributePendingWriteBackApplies(): void
    {
        $i = $this->make([
            'onMissingTranslation' => static fn (array $items, string $locale): array => [
                'A photo of a cat' => 'Una foto de un gato',
            ],
        ]);

        $out = $i->translateHtml('<img alt="A photo of a cat" src="/x.png">');

        self::assertStringContainsString('alt="Una foto de un gato"', $out);
    }

    public function testDebugPayloadDescribesTheReportedElement(): void
    {
        /** @var TranslationItem[] $items */
        $items = [];
        $i = $this->make([
            'debug' => true,
            'onMissingTranslation' => function (array $batch) use (&$items): array {
                $items = $batch;

                return [];
            },
        ]);

        $i->translateHtml(
            '<p class="lead" id="hero" title="A helpful tip">Hello <b class="strong">there</b> friend</p>',
        );

        self::assertCount(2, $items);

        $byKey = [];
        foreach ($items as $item) {
            $byKey[$item->masked] = $item;
        }

        $attrItem = $byKey['A helpful tip'];
        self::assertIsArray($attrItem->debug);
        self::assertSame('attribute:title', $attrItem->debug['source']);
        self::assertStringContainsString('<p', (string) $attrItem->debug['elementOpenTag']);
        self::assertStringContainsString('class="lead"', (string) $attrItem->debug['elementOpenTag']);
        self::assertStringNotContainsString('</p>', (string) $attrItem->debug['elementOpenTag']);

        $textItem = $byKey['Hello <b0>there</b0> friend'];
        self::assertIsArray($textItem->debug);
        self::assertSame('text', $textItem->debug['source']);
        self::assertSame(
            [['tag' => 'B', 'classes' => 'strong']],
            $textItem->debug['childElements'],
        );
    }

    public function testDebugIsNullWhenDisabled(): void
    {
        /** @var TranslationItem[] $items */
        $items = [];
        $i = $this->make([
            'onMissingTranslation' => function (array $batch) use (&$items): array {
                $items = $batch;

                return [];
            },
        ]);

        $i->translateHtml('<p>Hello world</p>');

        self::assertCount(1, $items);
        self::assertNull($items[0]->debug);
    }
}
