<?php

declare(strict_types=1);

namespace AutoHtmlI18n\Tests;

use AutoHtmlI18n\I18nTranslator;
use AutoHtmlI18n\TextDirection;
use AutoHtmlI18n\TranslationItem;
use AutoHtmlI18n\VariableInfo;
use AutoHtmlI18n\VariableType;
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

    public function testGetDirectionUsesInstanceLocaleByDefault(): void
    {
        $i = $this->make(['locale' => 'he-IL']);
        self::assertSame(TextDirection::Rtl, $i->getDirection());
        self::assertSame(TextDirection::Ltr, $i->getDirection('en-US'));
        self::assertSame(TextDirection::Rtl, $i->getDirection('ar'));
    }

    public function testGetDirectionFollowsSetLocale(): void
    {
        $i = $this->make(['locale' => 'es']);
        self::assertSame(TextDirection::Ltr, $i->getDirection());
        $i->setLocale('he-IL');
        self::assertSame(TextDirection::Rtl, $i->getDirection());
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

    /**
     * A parent that can't aggregate (a non-inline child disqualifies it) still holds one
     * translation unit per direct text node. Each must land on its own DOMText node:
     * writing them all through the parent's innerHTML makes the last one win and destroys
     * both the earlier text and the intervening element.
     */
    public function testTranslateHtmlTranslatesEachTextNodeAroundABr(): void
    {
        $i = $this->make([
            'initialCache' => ['hello there' => 'hola alli', 'goodbye now' => 'adios ahora'],
        ]);
        $out = $i->translateHtml('<p>hello there<br>goodbye now</p>');
        self::assertSame('<p>hola alli<br>adios ahora</p>', $out);
    }

    public function testTranslateHtmlTranslatesEachTextNodeAroundAFormControl(): void
    {
        $i = $this->make([
            'initialCache' => ['hello there' => 'hola alli', 'goodbye now' => 'adios ahora'],
        ]);
        $out = $i->translateHtml('<label>hello there<input>goodbye now</label>');
        self::assertStringContainsString('hola alli', $out);
        self::assertStringContainsString('adios ahora', $out);
        self::assertStringContainsString('<input', $out);
    }

    public function testTranslateHtmlTranslatesEachTextNodeViaThePendingPath(): void
    {
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale): array {
                return ['hello there' => 'hola alli', 'goodbye now' => 'adios ahora'];
            },
        ]);
        $out = $i->translateHtml('<p>hello there<br>goodbye now</p>');
        self::assertSame('<p>hola alli<br>adios ahora</p>', $out);
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

    public function testTranslateHtmlMasksIgnoredDescendantAsVariable(): void
    {
        $captured = null;
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = $items[0];
                return ['Submitted By: {{0}}' => 'Enviado por: {{0}}'];
            },
        ]);
        $out = $i->translateHtml(
            '<div>Submitted By: <span class="font-bold" data-i18n-ignore>Kailey Booth</span></div>',
        );

        // The ignored child's user data never enters the key; it's an opaque variable.
        self::assertNotNull($captured);
        self::assertSame('Submitted By: {{0}}', $captured->masked);
        self::assertStringNotContainsString('Kailey', $captured->masked);
        self::assertSame(VariableType::Ignored, $captured->variables[0]->type);
        // Reported original is clean markup — no aggregation sentinels leak out.
        self::assertStringNotContainsString("\u{E000}", $captured->original);

        // Translated sentence with the ignored subtree restored verbatim.
        self::assertStringContainsString('Enviado por:', $out);
        self::assertStringContainsString('data-i18n-ignore', $out);
        self::assertStringContainsString('Kailey Booth', $out);
    }

    public function testTranslateHtmlKeepsTranslatableInlineChildWhileIgnoringDescendant(): void
    {
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale): array {
                self::assertSame('Please <b0>read</b0> this {{0}}', $items[0]->masked);
                return ['Please <b0>read</b0> this {{0}}' => 'Por favor <b0>lea</b0> esto {{0}}'];
            },
        ]);
        $out = $i->translateHtml(
            '<div>Please <b>read</b> this <span data-i18n-ignore>Kailey</span></div>',
        );
        self::assertStringContainsString('<b>lea</b>', $out);
        self::assertStringContainsString('Kailey', $out);
        self::assertStringContainsString('data-i18n-ignore', $out);
    }

    public function testTranslateHtmlMasksSelectorIgnoredDescendant(): void
    {
        $i = $this->make([
            'ignoreSelectors' => ['[data-secret]'],
            'onMissingTranslation' => function (array $items, string $locale): array {
                self::assertSame('Signed by {{0}}', $items[0]->masked);
                self::assertSame(VariableType::Ignored, $items[0]->variables[0]->type);
                return ['Signed by {{0}}' => 'Firmado por {{0}}'];
            },
        ]);
        $out = $i->translateHtml('<div>Signed by <span data-secret>jbooth</span></div>');
        self::assertStringContainsString('Firmado por', $out);
        self::assertStringContainsString('jbooth', $out);
    }

    public function testTranslateHtmlDoesNotReportSentenceOnlyPunctuationOnceIgnored(): void
    {
        $called = false;
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$called): array {
                $called = true;
                return [];
            },
        ]);
        $out = $i->translateHtml('<div>: <span data-i18n-ignore>Kailey Booth</span></div>');
        self::assertFalse($called);
        // Untouched, so the ignored user data is still present verbatim.
        self::assertStringContainsString('Kailey Booth', $out);
    }

    public function testTranslateHtmlGivesMultipleIgnoredDescendantsOrderedSlots(): void
    {
        $captured = null;
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = $items[0];
                return ['From {{0}} to {{1}}' => 'De {{0}} a {{1}}'];
            },
        ]);
        $out = $i->translateHtml('<div>From <span data-i18n-ignore>Alice</span> to <span data-i18n-ignore>Bob</span></div>');

        self::assertNotNull($captured);
        self::assertSame('From {{0}} to {{1}}', $captured->masked);
        self::assertCount(2, $captured->variables);
        // Each slot restored in its own place, in order.
        self::assertMatchesRegularExpression('/De\s+<span[^>]*>Alice<\/span>\s+a\s+<span[^>]*>Bob<\/span>/', $out);
    }

    public function testTranslateHtmlTreatsNestedIgnoredSubtreeAsOneSlot(): void
    {
        $captured = null;
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = $items[0];
                return ['Owner: {{0}}' => 'Propietario: {{0}}'];
            },
        ]);
        // The masker does not descend into the ignored subtree: one variable, and
        // the nested <b> is not a separate structural marker.
        $out = $i->translateHtml('<div>Owner: <span data-i18n-ignore>Name <b>Booth</b> Jr.</span></div>');

        self::assertNotNull($captured);
        self::assertSame('Owner: {{0}}', $captured->masked);
        self::assertCount(1, $captured->variables);
        self::assertStringContainsString('<b>Booth</b>', $captured->variables[0]->value);
        self::assertStringContainsString('Propietario:', $out);
        self::assertStringContainsString('<b>Booth</b>', $out);
    }

    public function testTranslateHtmlFallsBackToOriginalTextWhenIcuEvaluationFails(): void
    {
        $i = $this->make([
            'initialCache' => ['You have {{0}} apples' => '{0, plural, {broken}'],
        ]);
        $out = $i->translateHtml('<p>You have 5 apples</p>');
        self::assertStringContainsString('You have 5 apples', $out);
        self::assertStringNotContainsString('plural', $out);
    }

    public function testTranslateHtmlFallsBackToOriginalAttributeWhenIcuEvaluationFails(): void
    {
        $i = $this->make([
            'initialCache' => ['{{0}} results found' => '{0, plural, {broken}'],
        ]);
        $out = $i->translateHtml('<button title="10 results found"></button>');
        self::assertStringContainsString('title="10 results found"', $out);
        self::assertStringNotContainsString('plural', $out);
    }

    public function testValidateIcuUsesInstanceLocaleByDefault(): void
    {
        $i = $this->make(); // locale: es
        $r = $i->validateIcu(
            '{0, plural, one {# oveja} other {# ovejas}}',
            [new VariableInfo('5', VariableType::Number)],
        );
        self::assertTrue($r->valid);
        self::assertSame('5 ovejas', $r->output);
    }

    public function testValidateIcuAcceptsExplicitLocale(): void
    {
        $i = $this->make();
        $r = $i->validateIcu(
            '{0, plural, one {# item} other {# items}}',
            [new VariableInfo('1', VariableType::Number)],
            'en',
        );
        self::assertTrue($r->valid);
        self::assertSame('1 item', $r->output);
    }

    public function testValidateIcuReportsInvalidPattern(): void
    {
        $i = $this->make();
        $r = $i->validateIcu('{0, plural, {broken}', [new VariableInfo('5', VariableType::Number)]);
        self::assertFalse($r->valid);
        self::assertNotNull($r->error);
    }

    public function testValidateTranslationUsesInstanceConfig(): void
    {
        $i = $this->make([
            'ignoreWords' => [['word' => 'Mary', 'meta' => ['gender' => 'female']]],
        ]);
        $r = $i->validateTranslation(
            'Mary bought 5 sheep',
            '{0_gender, select, female {{0} compró} other {{0} compró}} {1, plural, one {# oveja} other {# ovejas}}',
        );
        self::assertTrue($r->valid);
        self::assertSame('Mary compró 5 ovejas', $r->output);
    }

    public function testValidateTranslationRejectsUnrenderableTranslation(): void
    {
        $i = $this->make();
        $r = $i->validateTranslation('5 sheep', '{2, plural, one {# oveja} other {# ovejas}}');
        self::assertFalse($r->valid);
        self::assertNotNull($r->error);
    }

    public function testTranslateHtmlFallsBackWhenIcuReferencesMissingVariable(): void
    {
        // Backend returned a pattern indexing a variable that doesn't exist ({2});
        // ICU would render a literal "{2}" — the original text must win instead
        $i = $this->make([
            'initialCache' => ['{{0}} has {{1}} cats' => '{0} tiene {2} gatos'],
            'ignoreWords' => ['John'],
        ]);
        $out = $i->translateHtml('<p>John has 3 cats</p>');
        self::assertStringContainsString('John has 3 cats', $out);
        self::assertStringNotContainsString('{2}', $out);
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

    public function testTranslateHtmlDoesNotAggregateWhenDeepDescendantIsNonInline(): void
    {
        // FormKit checkbox option: the wrapper's direct children are both <span>
        // (inline-allowed), but one span contains an <input>/<svg> subtree. The
        // clean label must be offered on its own, never the svg/input blob.
        $captured = [];
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = $items;
                return [];
            },
        ]);
        $i->translateHtml(
            '<label class="fk-wrapper"><span class="fk-inner">'
            . '<input type="checkbox" value="Environmental">'
            . '<span class="fk-decorator"><span class="fk-icon"><svg viewBox="0 0 24 24"><path d="m10 14"></path></svg></span></span>'
            . '</span><span class="fk-label">Environmental</span></label>'
        );
        $masks = array_map(fn(TranslationItem $it) => $it->masked, $captured);
        self::assertContains('Environmental', $masks);
        foreach ($masks as $m) {
            self::assertStringNotContainsString('<svg', $m);
            self::assertStringNotContainsString('<input', $m);
        }
    }

    public function testTranslateHtmlDoesNotAggregatePureInlineContainer(): void
    {
        // A container whose only children are inline links (a nav menu / dropdown)
        // has no direct text of its own — each link must be offered as its own
        // unit, not collapsed into a single aggregated innerHTML key.
        $captured = [];
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = $items;
                return [];
            },
        ]);
        $i->translateHtml('<nav><a href="/a">Home</a><a href="/b">About</a></nav>');
        $masks = array_map(fn(TranslationItem $it) => $it->masked, $captured);
        self::assertCount(2, $masks);
        self::assertContains('Home', $masks);
        self::assertContains('About', $masks);
        foreach ($masks as $m) {
            self::assertStringNotContainsString('<a0>', $m);
        }
    }

    public function testTranslateHtmlDoesNotAggregateAdjacentInlineChildrenWithoutText(): void
    {
        // Parity with the JS Observer: adjacent inline children with no connective
        // text are structural, translated per-child rather than as one unit.
        $captured = [];
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = $items;
                return [];
            },
        ]);
        $i->translateHtml('<p><b>Hello</b><i>World</i></p>');
        $masks = array_map(fn(TranslationItem $it) => $it->masked, $captured);
        self::assertCount(2, $masks);
        self::assertContains('Hello', $masks);
        self::assertContains('World', $masks);
    }

    public function testTranslateHtmlTranslatesEachMenuLinkPreservingAnchor(): void
    {
        // Each link's text is translated in place and its <a href> preserved.
        $i = $this->make([
            'onMissingTranslation' => fn(array $items, string $locale): array => ['Home' => 'Inicio'],
        ]);
        $out = $i->translateHtml('<nav><a href="/a">Home</a></nav>');
        self::assertStringContainsString('<a href="/a">Inicio</a>', $out);
    }

    public function testTranslateHtmlStillAggregatesLinksWithVisibleSeparator(): void
    {
        // Direct text " / " makes this a formatted run — it stays one aggregated
        // unit (Layer 1 only excludes containers with no direct text of their own).
        $captured = [];
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = $items;
                return [];
            },
        ]);
        $i->translateHtml('<nav><a href="/">Home</a> / <a href="/p">Products</a></nav>');
        $masks = array_map(fn(TranslationItem $it) => $it->masked, $captured);
        self::assertCount(1, $masks);
        self::assertStringContainsString('<a0>Home</a0>', $masks[0]);
        self::assertStringContainsString('<a1>Products</a1>', $masks[0]);
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

    public function testHalfRenderedValuesAreNotReported(): void
    {
        $captured = [];
        $i = $this->make([
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = array_map(static fn(TranslationItem $item): string => $item->masked, $items);
                return [];
            },
        ]);

        $out = $i->translateHtml(
            '<p>Level undefined</p>'
            . '<p>Read time about NaN minutes</p>'
            . '<p>Owner is null</p>'
            . "<p>No Encyclopedia results found for ''</p>"
            . '<img alt="Photo of undefined">'
            . '<p>Only real copy here</p>',
        );

        self::assertSame(['Only real copy here'], $captured);
        // Nothing to translate to — the half-rendered text stays exactly as it came in.
        self::assertStringContainsString('<p>Level undefined</p>', $out);
    }

    public function testHalfRenderedValuesReportedWhenSkipDisabled(): void
    {
        $captured = [];
        $i = $this->make([
            'skipUnrenderedValues' => false,
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = array_map(static fn(TranslationItem $item): string => $item->masked, $items);
                return [];
            },
        ]);

        $i->translateHtml('<p>Level undefined</p>');
        self::assertSame(['Level undefined'], $captured);
    }

    public function testCustomIsUnrenderedValuePredicate(): void
    {
        $captured = [];
        $i = $this->make([
            // A corpus whose copy legitimately says "null": only "undefined" is an artifact.
            'isUnrenderedValue' => static fn(string $masked, string $original): bool
                => preg_match('/\bundefined\b/', $masked) === 1,
            'onMissingTranslation' => function (array $items, string $locale) use (&$captured): array {
                $captured = array_map(static fn(TranslationItem $item): string => $item->masked, $items);
                return [];
            },
        ]);

        $i->translateHtml('<p>Owner is null</p><p>Level undefined</p>');
        self::assertSame(['Owner is null'], $captured);
    }

    public function testCachedTranslationForHalfRenderedKeyStillApplies(): void
    {
        $called = false;
        $i = $this->make([
            'initialCache' => ['Level undefined' => 'Nivel desconocido'],
            'onMissingTranslation' => function (array $items, string $locale) use (&$called): array {
                $called = true;
                return [];
            },
        ]);

        $out = $i->translateHtml('<p>Level undefined</p>');
        self::assertStringContainsString('Nivel desconocido', $out);
        self::assertFalse($called);
    }
}
