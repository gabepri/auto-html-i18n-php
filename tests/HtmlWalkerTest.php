<?php

declare(strict_types=1);

namespace AutoHtmlI18n\Tests;

use AutoHtmlI18n\HtmlWalker;
use DOMDocument;
use DOMDocumentFragment;
use DOMElement;
use DOMText;
use Masterminds\HTML5;
use PHPUnit\Framework\TestCase;

/**
 * Direct unit coverage for HtmlWalker's public surface — notably walk(), which the
 * I18nTranslator facade bypasses (it re-implements collect/dispatch so it can mutate
 * the DOM itself), and the DOM write helpers used by the apply path.
 */
final class HtmlWalkerTest extends TestCase
{
    /**
     * @param array<string> $allowedInlineTags
     * @param array<string> $ignoreSelectors
     * @param array<string> $translatableAttributes
     */
    private function make(
        array $allowedInlineTags = ['a', 'b', 'i', 'em', 'strong', 'span'],
        array $ignoreSelectors = ['script', 'style', 'code'],
        array $translatableAttributes = ['title', 'placeholder', 'alt', 'aria-label'],
    ): HtmlWalker {
        return new HtmlWalker(
            $allowedInlineTags,
            $ignoreSelectors,
            $translatableAttributes,
            'data-i18n-ignore',
            'data-i18n-scope',
            'data-i18n-key',
        );
    }

    /**
     * Every fragment this test parses is owned by one document, held for the
     * lifetime of the test case. A DOMElement does not keep its owner document
     * alive on its own, so dropping this reference frees the document out from
     * under the nodes still under test ("Couldn't fetch DOMElement").
     */
    private ?DOMDocument $doc = null;

    /** Container each parsed fragment is grafted into, so the nodes stay reachable. */
    private ?DOMElement $root = null;

    /** First element of a parsed fragment. */
    private function parse(string $html): DOMElement
    {
        if ($this->doc === null || $this->root === null) {
            $this->doc = new DOMDocument();
            $this->root = $this->doc->createElement('body');
            $this->doc->appendChild($this->root);
        }
        $holder = $this->doc->createElement('div');
        $this->root->appendChild($holder);

        $parser = new HTML5();
        $fragment = $parser->loadHTMLFragment($html, ['target_document' => $this->doc]);
        self::assertInstanceOf(DOMDocumentFragment::class, $fragment);
        $holder->appendChild($fragment);
        $el = $holder->firstChild;
        self::assertInstanceOf(DOMElement::class, $el);

        return $el;
    }

    // ---------------------------------------------------------------- walk()

    public function testWalkDispatchesAttributesThenTextAndSerializes(): void
    {
        $walker = $this->make();
        $order = [];

        $out = $walker->walk(
            '<p title="Tip">Hello world</p>',
            function (DOMElement $el, string $text, ?string $scope, ?string $key, bool $isHtml, ?DOMText $node = null) use (&$order): void {
                $order[] = "text:$text";
                self::assertFalse($isHtml);
                self::assertInstanceOf(DOMText::class, $node);
                $node->nodeValue = 'Hola mundo';
            },
            function (DOMElement $el, string $attr, string $value, ?string $scope) use (&$order): void {
                $order[] = "attr:$attr=$value";
                $el->setAttribute($attr, 'Consejo');
            },
        );

        self::assertSame(['attr:title=Tip', 'text:Hello world'], $order);
        self::assertStringContainsString('Hola mundo', $out);
        self::assertStringContainsString('Consejo', $out);
    }

    public function testWalkPassesScopeKeyOverrideAndAggregatedInnerHtml(): void
    {
        $walker = $this->make();
        $seen = [];

        $walker->walk(
            '<div data-i18n-scope="marketing"><p data-i18n-key="greeting">Hello <b>there</b> friend</p></div>',
            function (DOMElement $el, string $text, ?string $scope, ?string $key, bool $isHtml) use (&$seen): void {
                $seen[] = [$text, $scope, $key, $isHtml];
            },
            function (): void {
            },
        );

        self::assertSame([['Hello <b>there</b> friend', 'marketing', 'greeting', true]], $seen);
    }

    public function testWalkSkipsWhitespaceOnlyTextAndBlankAttributes(): void
    {
        $walker = $this->make();
        $texts = [];
        $attrs = [];

        $walker->walk(
            "<p title=\"   \">\n   \n</p><p>Real text</p>",
            function (DOMElement $el, string $text) use (&$texts): void {
                $texts[] = $text;
            },
            function (DOMElement $el, string $attr, string $value) use (&$attrs): void {
                $attrs[] = $value;
            },
        );

        self::assertSame(['Real text'], $texts);
        self::assertSame([], $attrs);
    }

    public function testWalkSkipsTextWhoseParentIsNotAnElement(): void
    {
        $walker = $this->make();
        $texts = [];

        $out = $walker->walk(
            'bare top-level text<p>Inside</p>',
            function (DOMElement $el, string $text) use (&$texts): void {
                $texts[] = $text;
            },
            function (): void {
            },
        );

        // The bare text node's parent is the DOMDocumentFragment, not an element.
        self::assertSame(['Inside'], $texts);
        self::assertStringContainsString('bare top-level text', $out);
    }

    public function testWalkSkipsIgnoredSubtrees(): void
    {
        $walker = $this->make();
        $texts = [];

        $walker->walk(
            '<script>var greeting = "Hello";</script><p data-i18n-ignore>Skip me</p><p>Keep me</p>',
            function (DOMElement $el, string $text) use (&$texts): void {
                $texts[] = $text;
            },
            function (): void {
            },
        );

        self::assertSame(['Keep me'], $texts);
    }

    // -------------------------------------------------------------- collect()

    public function testCollectReturnsNothingWhenRootItselfIsIgnored(): void
    {
        $walker = $this->make();
        $el = $this->parse('<div data-i18n-ignore><p>Hidden</p></div>');

        $textItems = [];
        $attrItems = [];
        $walker->collect($el, $textItems, $attrItems);

        self::assertSame([], $textItems);
        self::assertSame([], $attrItems);
    }

    public function testCollectReturnsNothingWhenAnAncestorOfRootIsIgnored(): void
    {
        $walker = $this->make();
        $outer = $this->parse('<div data-i18n-ignore><section><p title="Tip">Hidden</p></section></div>');
        $inner = $outer->getElementsByTagName('p')->item(0);
        self::assertInstanceOf(DOMElement::class, $inner);

        $textItems = [];
        $attrItems = [];
        $walker->collect($inner, $textItems, $attrItems);

        self::assertSame([], $textItems);
        self::assertSame([], $attrItems);
    }

    public function testInlineElementAtFragmentRootIsNotAnAggregationTarget(): void
    {
        $walker = $this->make();
        $seen = [];

        // <b>'s parent is the fragment, so the climb for an aggregation target
        // runs out of elements and the text is collected as its own unit.
        $walker->walk(
            '<b>Bold text</b>',
            function (DOMElement $el, string $text, ?string $scope, ?string $key, bool $isHtml) use (&$seen): void {
                $seen[] = [$el->nodeName, $text, $isHtml];
            },
            function (): void {
            },
        );

        self::assertSame([['b', 'Bold text', false]], $seen);
    }

    public function testAggregateBracketsDeeplyNestedIgnoredDescendant(): void
    {
        $walker = $this->make();
        $seen = [];

        $walker->walk(
            '<p>Hi <span><b data-i18n-ignore>USER</b></span> end</p>',
            function (DOMElement $el, string $text, ?string $scope, ?string $key, bool $isHtml) use (&$seen): void {
                $seen[] = [$text, $isHtml];
            },
            function (): void {
            },
        );

        self::assertCount(1, $seen);
        self::assertTrue($seen[0][1]);
        self::assertStringContainsString(\AutoHtmlI18n\Masker::IGNORE_OPEN, $seen[0][0]);
        self::assertStringContainsString(\AutoHtmlI18n\Masker::IGNORE_CLOSE, $seen[0][0]);
        self::assertStringContainsString('USER', $seen[0][0]);
    }

    public function testEmptySelectorNeverMatches(): void
    {
        $walker = $this->make(ignoreSelectors: ['', '   ']);
        $texts = [];

        $walker->walk(
            '<p>Still translated</p>',
            function (DOMElement $el, string $text) use (&$texts): void {
                $texts[] = $text;
            },
            function (): void {
            },
        );

        self::assertSame(['Still translated'], $texts);
    }

    /**
     * The inline-ness memos are a collect()-scoped cache (built on entry, torn down in
     * the finally). Both predicates must still answer correctly with no memo in place —
     * that's the contract that lets collect() drop the memo unconditionally.
     */
    public function testInlinePredicatesWorkWithNoMemoInPlace(): void
    {
        $walker = $this->make();
        $root = $this->parse('<div><p>Hi <b>there</b> friend</p><p><span>only a wrapper</span></p></div>');
        $paras = $root->getElementsByTagName('p');
        $sentence = $paras->item(0);
        $wrapper = $paras->item(1);
        self::assertInstanceOf(DOMElement::class, $sentence);
        self::assertInstanceOf(DOMElement::class, $wrapper);

        $hasInline = new \ReflectionMethod($walker, 'hasInlineChildElements');
        $fullyInline = new \ReflectionMethod($walker, 'isFullyInline');

        // A fresh walker is outside collect(), so both memos are null.
        self::assertTrue($hasInline->invoke($walker, $sentence));
        self::assertFalse($hasInline->invoke($walker, $wrapper));
        $bold = $sentence->getElementsByTagName('b')->item(0);
        self::assertInstanceOf(DOMElement::class, $bold);
        self::assertTrue($fullyInline->invoke($walker, $bold));
        self::assertFalse($fullyInline->invoke($walker, $sentence));
    }

    // --------------------------------------------------------- DOM helpers

    public function testGetInnerHtmlReturnsEmptyForDetachedElement(): void
    {
        $walker = $this->make();
        self::assertSame('', $walker->getInnerHtml(new DOMElement('p')));
    }

    public function testSetInnerHtmlWithEmptyStringClearsChildren(): void
    {
        $walker = $this->make();
        $el = $this->parse('<p>Hello <b>world</b></p>');

        $walker->setInnerHtml($el, '');

        self::assertFalse($el->hasChildNodes());
    }

    public function testSetInnerHtmlIsANoOpOnDetachedElement(): void
    {
        $walker = $this->make();
        $el = new DOMElement('p');

        $walker->setInnerHtml($el, '<b>x</b>');

        self::assertFalse($el->hasChildNodes());
    }

    public function testReplaceTextNodeIsANoOpWhenNodeHasNoParent(): void
    {
        $walker = $this->make();
        $node = new DOMText('orphan');

        $walker->replaceTextNode($node, 'replacement');

        self::assertSame('orphan', $node->textContent);
    }

    public function testReplaceTextNodeWithEmptyStringRemovesTheNode(): void
    {
        $walker = $this->make();
        $el = $this->parse('<p>Hello<b>keep</b></p>');
        $first = $el->firstChild;
        self::assertInstanceOf(DOMText::class, $first);

        $walker->replaceTextNode($first, '');

        self::assertSame('<b>keep</b>', $walker->getInnerHtml($el));
    }

    public function testSetTextContentReplacesChildrenWithPlainText(): void
    {
        $walker = $this->make();
        $el = $this->parse('<p>Hello <b>world</b></p>');

        $walker->setTextContent($el, '<not> parsed');

        self::assertSame('<not> parsed', $el->textContent);
        self::assertSame(1, $el->childNodes->length);
    }

    public function testSetTextContentWithEmptyStringLeavesNoChildren(): void
    {
        $walker = $this->make();
        $el = $this->parse('<p>Hello <b>world</b></p>');

        $walker->setTextContent($el, '');

        self::assertFalse($el->hasChildNodes());
    }

    public function testSetTextContentIsANoOpOnDetachedElement(): void
    {
        $walker = $this->make();
        $el = new DOMElement('p');

        $walker->setTextContent($el, 'text');

        self::assertFalse($el->hasChildNodes());
    }
}
