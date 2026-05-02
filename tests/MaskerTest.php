<?php

declare(strict_types=1);

namespace AutoHtmlI18n\Tests;

use AutoHtmlI18n\CasePattern;
use AutoHtmlI18n\Masker;
use AutoHtmlI18n\VariableInfo;
use AutoHtmlI18n\VariableType;
use PHPUnit\Framework\TestCase;

final class MaskerTest extends TestCase
{
    private const ALLOWED = ['a', 'b', 'i', 'u', 'strong', 'em', 'span', 'small', 'mark', 'del'];

    private function masker(array $ignoreWords = []): Masker
    {
        return new Masker($ignoreWords, self::ALLOWED);
    }

    public function testUnmaskSubstitutesVariables(): void
    {
        $m = $this->masker();
        $vars = [new VariableInfo('Mary', VariableType::IgnoreWord)];
        self::assertSame('Hola Mary', $m->unmask('Hola {{0}}', $vars, []));
    }

    public function testUnmaskRestoresMultipleVariables(): void
    {
        $m = $this->masker();
        $vars = [
            new VariableInfo('John', VariableType::IgnoreWord),
            new VariableInfo('3', VariableType::Number),
        ];
        self::assertSame('John tiene 3 gatos', $m->unmask('{{0}} tiene {{1}} gatos', $vars, []));
    }

    public function testUnmaskRestoresTagAttributes(): void
    {
        $m = $this->masker();
        $attrs = ['a0' => ['href' => '/login']];
        self::assertSame('<a href="/login">aqui</a>', $m->unmask('<a0>aqui</a0>', [], $attrs));
    }

    public function testUnmaskWithEmptyAttributes(): void
    {
        $m = $this->masker();
        $attrs = ['b0' => []];
        self::assertSame('<b>text</b>', $m->unmask('<b0>text</b0>', [], $attrs));
    }

    public function testUnmaskStripsEventHandlerAttributes(): void
    {
        $m = $this->masker();
        $attrs = ['a0' => ['href' => '/ok', 'onclick' => 'alert(1)']];
        $out = $m->unmask('<a0>click</a0>', [], $attrs);
        self::assertStringContainsString('href="/ok"', $out);
        self::assertStringNotContainsString('onclick', $out);
    }

    public function testUnmaskEscapesDisallowedTags(): void
    {
        $m = $this->masker();
        $out = $m->unmask('Hello <script>alert(1)</script>', [], []);
        self::assertSame('Hello &lt;script&gt;alert(1)&lt;/script&gt;', $out);
    }

    public function testUnmaskEscapesIframeTags(): void
    {
        $m = $this->masker();
        $out = $m->unmask('See <iframe src="evil.com"></iframe>', [], []);
        self::assertSame('See &lt;iframe src="evil.com"&gt;&lt;/iframe&gt;', $out);
    }

    public function testUnmaskPreservesAllowedAndEscapesOthers(): void
    {
        $m = $this->masker();
        $attrs = ['b0' => []];
        $out = $m->unmask('<b0>bold</b0> <script>evil</script>', [], $attrs);
        self::assertSame('<b>bold</b> &lt;script&gt;evil&lt;/script&gt;', $out);
    }

    public function testUnmaskHandlesMissingVariableGracefully(): void
    {
        $m = $this->masker();
        $vars = [new VariableInfo('World', VariableType::IgnoreWord)];
        self::assertSame('Hello World {{1}}', $m->unmask('Hello {{0}} {{1}}', $vars, []));
    }

    public function testUnmaskEmptyInput(): void
    {
        $m = $this->masker();
        self::assertSame('', $m->unmask('', [], []));
    }

    public function testRoundTripWithNumbers(): void
    {
        $m = $this->masker();
        $original = 'You have 5 apples and 3 oranges';
        $r = $m->mask($original);
        self::assertSame($original, $m->unmask($r->masked, $r->variables, $r->tagAttributes));
    }

    public function testRoundTripWithInlineTagsAndIgnoreWord(): void
    {
        $m = $this->masker(['Mary']);
        $original = 'Welcome <b>Mary</b>, you have 5 items';
        $r = $m->mask($original);
        self::assertSame($original, $m->unmask($r->masked, $r->variables, $r->tagAttributes));
    }

    public function testRoundTripWithComments(): void
    {
        $m = $this->masker();
        $original = '<span class="x">text</span> <!--v-if-->';
        $r = $m->mask($original);
        self::assertSame($original, $m->unmask($r->masked, $r->variables, $r->tagAttributes));
    }

    public function testApplyCasePatternUpper(): void
    {
        $m = $this->masker();
        self::assertSame('HOLA MUNDO', $m->applyCasePattern('hola mundo', CasePattern::Upper));
    }

    public function testApplyCasePatternLowerIsNoOp(): void
    {
        $m = $this->masker();
        self::assertSame('hola mundo', $m->applyCasePattern('hola mundo', CasePattern::Lower));
    }

    public function testApplyCasePatternPreservesTagInternals(): void
    {
        $m = $this->masker();
        $out = $m->applyCasePattern('click <a href="/login">here</a> now', CasePattern::Upper);
        self::assertSame('CLICK <a href="/login">HERE</a> NOW', $out);
    }

    public function testIcuPlural(): void
    {
        $m = $this->masker();
        $out = $m->unmask(
            '{0, plural, one {# item} other {# items}}',
            [new VariableInfo('5', VariableType::Number)],
            [],
            'en',
        );
        self::assertSame('5 items', $out);
    }

    public function testIcuPluralSingular(): void
    {
        $m = $this->masker();
        $out = $m->unmask(
            '{0, plural, one {# item} other {# items}}',
            [new VariableInfo('1', VariableType::Number)],
            [],
            'en',
        );
        self::assertSame('1 item', $out);
    }

    public function testIcuMixedNameAndCount(): void
    {
        $m = $this->masker();
        $out = $m->unmask(
            '{0} bought {1, plural, one {# sheep} other {# sheep}}',
            [
                new VariableInfo('Mary', VariableType::IgnoreWord),
                new VariableInfo('5', VariableType::Number),
            ],
            [],
            'en',
        );
        self::assertSame('Mary bought 5 sheep', $out);
    }

    public function testIcuTagAttributesRestoredAfterEvaluation(): void
    {
        $m = $this->masker();
        $attrs = ['a0' => ['href' => '/items']];
        $out = $m->unmask(
            '<a0>{0, plural, one {# item} other {# items}}</a0>',
            [new VariableInfo('3', VariableType::Number)],
            $attrs,
            'en',
        );
        self::assertSame('<a href="/items">3 items</a>', $out);
    }

    public function testGetIgnoreWordsReturnsSortedLongestFirst(): void
    {
        $m = $this->masker(['Al', 'Alice', 'A']);
        self::assertSame(['Alice', 'Al', 'A'], $m->getIgnoreWords());
    }

    public function testGetIgnoreWordsPreservesMetadata(): void
    {
        $m = $this->masker([['word' => 'Mary', 'meta' => ['gender' => 'female']], 'Bob']);
        self::assertSame([
            ['word' => 'Mary', 'meta' => ['gender' => 'female']],
            'Bob',
        ], $m->getIgnoreWords());
    }

    public function testAddIgnoreWord(): void
    {
        $m = $this->masker();
        self::assertSame('Hello Mary', $m->mask('Hello Mary')->masked);

        $m->addIgnoreWords(['Mary']);
        $r = $m->mask('Hello Mary');
        self::assertSame('Hello {{0}}', $r->masked);
    }

    public function testAddIgnoreWordsDeduplicates(): void
    {
        $m = $this->masker(['Alice']);
        $m->addIgnoreWords(['Alice']);
        self::assertSame(['Alice'], $m->getIgnoreWords());
    }

    public function testAddIgnoreWordsSkipsEmpty(): void
    {
        $m = $this->masker();
        $m->addIgnoreWords(['', 'Alice', '']);
        self::assertSame(['Alice'], $m->getIgnoreWords());
    }

    public function testRemoveIgnoreWord(): void
    {
        $m = $this->masker(['Mary']);
        self::assertSame('Hello {{0}}', $m->mask('Hello Mary')->masked);

        $m->removeIgnoreWords(['Mary']);
        self::assertSame('Hello Mary', $m->mask('Hello Mary')->masked);
    }

    public function testSetIgnoreWordsReplacesList(): void
    {
        $m = $this->masker(['Alice']);
        $m->setIgnoreWords(['Bob', 'Charlie']);
        self::assertSame(['Charlie', 'Bob'], $m->getIgnoreWords());

        self::assertSame('Hello Alice', $m->mask('Hello Alice')->masked);
        self::assertSame('Hello {{0}}', $m->mask('Hello Bob')->masked);
    }

    public function testSetIgnoreWordsToEmpty(): void
    {
        $m = $this->masker(['Alice']);
        $m->setIgnoreWords([]);
        self::assertSame([], $m->getIgnoreWords());
        self::assertSame('Hello Alice', $m->mask('Hello Alice')->masked);
    }
}
