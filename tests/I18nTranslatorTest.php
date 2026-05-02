<?php

declare(strict_types=1);

namespace AutoHtmlI18n\Tests;

use AutoHtmlI18n\I18nTranslator;
use AutoHtmlI18n\TranslationItem;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class I18nTranslatorTest extends TestCase
{
    /**
     * @param array<string,mixed> $extra
     */
    private function make(array $extra = []): I18nTranslator
    {
        return new I18nTranslator(array_merge([
            'locale' => 'es',
            'onMissingTranslation' => fn(array $items, string $locale): array => [],
        ], $extra));
    }

    public function testRequiresLocale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new I18nTranslator(['onMissingTranslation' => fn() => []]);
    }

    public function testRequiresOnMissingTranslation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new I18nTranslator(['locale' => 'es']);
    }

    public function testTranslateLooksUpPreMaskedKey(): void
    {
        $i = $this->make(['initialCache' => ['Hello world' => 'Hola mundo']]);
        self::assertSame('Hola mundo', $i->translate('Hello world'));
    }

    public function testTranslateSubstitutesVariables(): void
    {
        $i = $this->make(['initialCache' => ['Hello {{0}}' => 'Hola {{0}}']]);
        self::assertSame('Hola Mary', $i->translate('Hello {{0}}', ['Mary']));
    }

    public function testTranslateFallsBackToInputWhenMissing(): void
    {
        $i = $this->make();
        self::assertSame('No translation', $i->translate('No translation'));
    }

    public function testTranslateScopedEntry(): void
    {
        $i = $this->make([
            'initialCache' => ['greeting' => ['formal' => 'Bienvenido', 'casual' => 'Hola']],
        ]);
        self::assertSame('Bienvenido', $i->translate('greeting', [], 'formal'));
        self::assertSame('Hola', $i->translate('greeting', [], 'casual'));
    }

    public function testTranslateHtmlSimplePassThrough(): void
    {
        $captured = [];
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = $items;
                return ['hello world' => 'hola mundo'];
            },
        ]);
        $out = $i->translateHtml('<p>hello world</p>');
        self::assertStringContainsString('hola mundo', $out);
        self::assertCount(1, $captured);
        self::assertInstanceOf(TranslationItem::class, $captured[0]);
        self::assertSame('hello world', $captured[0]->masked);
    }

    public function testTranslateHtmlBatchesAllUnknowns(): void
    {
        $captured = [];
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = $items;
                return [];
            },
        ]);
        $i->translateHtml('<p>hello world</p><p>good morning</p><p>see you later</p>');
        self::assertCount(3, $captured);
        $masks = array_map(fn(TranslationItem $i) => $i->masked, $captured);
        self::assertContains('hello world', $masks);
        self::assertContains('good morning', $masks);
        self::assertContains('see you later', $masks);
    }

    public function testTranslateHtmlDeduplicatesIdenticalKeys(): void
    {
        $callCount = 0;
        $captured = [];
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$callCount, &$captured): array {
                $callCount++;
                $captured = $items;
                return ['hello world' => 'hola mundo'];
            },
        ]);
        $out = $i->translateHtml('<p>hello world</p><p>hello world</p>');
        self::assertSame(1, $callCount);
        self::assertCount(1, $captured);
        self::assertSame(2, substr_count($out, 'hola mundo'));
    }

    public function testTranslateHtmlPreservesUnknownStrings(): void
    {
        $i = $this->make([
            'onMissingTranslation' => fn(array $items, string $locale): array => [],
        ]);
        $out = $i->translateHtml('<p>untranslated text</p>');
        self::assertStringContainsString('untranslated text', $out);
    }

    public function testTranslateHtmlMasksAndUnmasksVariables(): void
    {
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale): array {
                // "You have 5 apples" is mixed-case → masked key preserves case verbatim
                self::assertSame('You have {{0}} apples', $items[0]->masked);
                return ['You have {{0}} apples' => 'Tienes {{0}} manzanas'];
            },
        ]);
        $out = $i->translateHtml('<p>You have 5 apples</p>');
        self::assertStringContainsString('Tienes 5 manzanas', $out);
    }

    public function testTranslateHtmlPreservesAllCaps(): void
    {
        $i = $this->make([
            'onMissingTranslation' => fn(array $items, string $locale): array => [
                'click here' => 'haz clic aquí',
            ],
        ]);
        $out = $i->translateHtml('<button>CLICK HERE</button>');
        self::assertStringContainsString('HAZ CLIC AQUÍ', $out);
    }

    public function testTranslateHtmlAggregatesInlineMarkup(): void
    {
        $captured = [];
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = $items;
                return ['Click <a0>here</a0> to login' => 'Haz clic <a0>aquí</a0> para iniciar sesión'];
            },
        ]);
        $out = $i->translateHtml('<p>Click <a href="/login">here</a> to login</p>');
        self::assertCount(1, $captured);
        self::assertSame('Click <a0>here</a0> to login', $captured[0]->masked);
        self::assertStringContainsString('Haz clic <a href="/login">aquí</a> para iniciar sesión', $out);
    }

    public function testTranslateHtmlTranslatesAttributes(): void
    {
        $captured = [];
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = $items;
                return ['Submit form' => 'Enviar formulario'];
            },
        ]);
        $out = $i->translateHtml('<button title="Submit form"></button>');
        self::assertCount(1, $captured);
        self::assertSame('Submit form', $captured[0]->masked);
        self::assertStringContainsString('title="Enviar formulario"', $out);
    }

    public function testTranslateHtmlSkipsIgnoreSelectors(): void
    {
        $captured = [];
        $i = $this->make([
            'ignoreSelectors' => ['code'],
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = $items;
                return [];
            },
        ]);
        $i->translateHtml('<p>hello world <code>do not translate</code></p>');
        $masks = array_map(fn(TranslationItem $i) => $i->masked, $captured);
        // The <code> contents should be skipped; only the aggregated <p> innerHTML is offered
        foreach ($masks as $m) {
            self::assertStringNotContainsString('do not translate', $m);
        }
    }

    public function testTranslateHtmlSkipsDataI18nIgnoreAttribute(): void
    {
        $captured = [];
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = $items;
                return [];
            },
        ]);
        $i->translateHtml('<p>hello</p><p data-i18n-ignore>do not translate</p>');
        $masks = array_map(fn(TranslationItem $i) => $i->masked, $captured);
        self::assertContains('hello', $masks);
        self::assertNotContains('do not translate', $masks);
    }

    public function testTranslateHtmlMarksMissingItemsAsReportedToAvoidRetry(): void
    {
        $callCount = 0;
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$callCount): array {
                $callCount++;
                return []; // backend returns nothing
            },
        ]);
        $i->translateHtml('<p>hello world</p>');
        self::assertSame(1, $callCount);
        // Second call with the same key should NOT trigger another backend call
        $i->translateHtml('<p>hello world</p>');
        self::assertSame(1, $callCount);
    }

    public function testInitialCacheBypassesBackendForKnownKeys(): void
    {
        $callCount = 0;
        $i = $this->make([
            'initialCache' => ['hello world' => 'hola mundo'],
            'onMissingTranslation' => function (array $items, string $locale) use (&$callCount): array {
                $callCount++;
                return [];
            },
        ]);
        $out = $i->translateHtml('<p>hello world</p>');
        self::assertSame(0, $callCount);
        self::assertStringContainsString('hola mundo', $out);
    }

    public function testSetLocaleSwitchesCacheLookups(): void
    {
        $i = $this->make([
            'onMissingTranslation' => fn(array $items, string $locale): array => match ($locale) {
                'es' => ['hello world' => 'hola mundo'],
                'fr' => ['hello world' => 'bonjour le monde'],
                default => [],
            },
        ]);
        $i->setLocale('es');
        self::assertStringContainsString('hola mundo', $i->translateHtml('<p>hello world</p>'));
        $i->setLocale('fr');
        self::assertStringContainsString('bonjour le monde', $i->translateHtml('<p>hello world</p>'));
    }

    public function testCacheGetSetClear(): void
    {
        $i = $this->make();
        $i->setCache('es', ['a' => 'A', 'b' => 'B']);
        self::assertSame(['a' => 'A', 'b' => 'B'], $i->getCache('es'));
        $i->clearCache('es');
        self::assertSame([], $i->getCache('es'));
    }

    public function testGetTranslationReturnsNullForMissing(): void
    {
        $i = $this->make();
        self::assertNull($i->getTranslation('missing'));
    }

    public function testIgnoreWordsApi(): void
    {
        $i = $this->make([
            'ignoreWords' => ['Mary'],
            'onMissingTranslation' => fn(array $items, string $locale): array => [],
        ]);
        self::assertSame(['Mary'], $i->getIgnoreWords());
        $i->addIgnoreWords(['Bob']);
        $words = $i->getIgnoreWords();
        self::assertContains('Mary', $words);
        self::assertContains('Bob', $words);
        $i->removeIgnoreWords(['Mary']);
        self::assertSame(['Bob'], $i->getIgnoreWords());
        $i->setIgnoreWords(['Charlie']);
        self::assertSame(['Charlie'], $i->getIgnoreWords());
    }

    public function testDisallowedTagsAreEscapedInTranslatedOutput(): void
    {
        $i = $this->make([
            'onMissingTranslation' => fn(array $items, string $locale): array => [
                'hello' => 'hola <script>alert(1)</script>',
            ],
        ]);
        $out = $i->translateHtml('<p>hello</p>');
        self::assertStringContainsString('&lt;script&gt;', $out);
        self::assertStringNotContainsString('<script>', $out);
    }

    public function testWhitespaceIsPreservedInTranslatedText(): void
    {
        $i = $this->make([
            'onMissingTranslation' => fn(array $items, string $locale): array => [
                'hello world' => 'hola mundo',
            ],
        ]);
        // The walker emits the whole text including leading/trailing whitespace
        $out = $i->translateHtml('<p>  hello world  </p>');
        self::assertStringContainsString('  hola mundo  ', $out);
    }

    public function testEmptyHtmlReturnedAsIs(): void
    {
        $i = $this->make();
        self::assertSame('', $i->translateHtml(''));
        self::assertSame('   ', $i->translateHtml('   '));
    }

    public function testScopedTranslationResolvedFromAncestor(): void
    {
        $i = $this->make([
            'onMissingTranslation' => fn(array $items, string $locale): array => [
                'greeting' => ['formal' => 'Bienvenido', 'casual' => 'Hola'],
            ],
        ]);
        // Scope walks UP from the translated element. The keyAttribute is read off the
        // element directly containing the text — typically the same element wearing the scope.
        $out = $i->translateHtml('<div data-i18n-scope="formal"><p data-i18n-key="greeting">Hello</p></div>');
        self::assertStringContainsString('Bienvenido', $out);
    }
}
