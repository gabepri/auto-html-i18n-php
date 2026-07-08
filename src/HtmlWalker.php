<?php

declare(strict_types=1);

namespace AutoHtmlI18n;

use DOMDocument;
use DOMDocumentFragment;
use DOMElement;
use DOMNode;
use DOMText;
use Masterminds\HTML5;

final class HtmlWalker
{
    /** @var array<string,true> */
    private readonly array $allowedInlineTags;

    /** @var string[] */
    private readonly array $ignoreSelectors;

    /** @var string[] */
    private readonly array $translatableAttributes;

    /**
     * @param string[] $allowedInlineTags
     * @param string[] $ignoreSelectors
     * @param string[] $translatableAttributes
     */
    public function __construct(
        array $allowedInlineTags,
        array $ignoreSelectors,
        array $translatableAttributes,
        private readonly string $ignoreAttribute,
        private readonly string $scopeAttribute,
        private readonly string $keyAttribute,
    ) {
        $tags = [];
        foreach ($allowedInlineTags as $t) {
            $tags[strtolower($t)] = true;
        }
        $this->allowedInlineTags = $tags;
        $this->ignoreSelectors = $ignoreSelectors;
        $this->translatableAttributes = $translatableAttributes;
    }

    /**
     * Parse an HTML fragment, walk it, invoke callbacks for each translatable text/attribute, then serialize.
     *
     * Both callbacks receive the DOM node (so the caller can write back) and the relevant data:
     *   onText(DOMElement $element, string $text, ?string $scope, ?string $keyOverride, bool $isInnerHtml)
     *   onAttribute(DOMElement $element, string $attrName, string $value, ?string $scope)
     *
     * @param callable(DOMElement, string, ?string, ?string, bool):void $onText
     * @param callable(DOMElement, string, string, ?string):void $onAttribute
     */
    public function walk(string $html, callable $onText, callable $onAttribute): string
    {
        $parser = new HTML5();
        $fragment = $parser->loadHTMLFragment($html);
        if (!$fragment instanceof DOMDocumentFragment) {
            return $html;
        }

        // Pass 1: collect items, then dispatch (so DOM mutations don't disrupt traversal mid-walk)
        /** @var array<int,array{0:DOMElement,1:string,2:?string,3:?string,4:bool}> $textItems */
        $textItems = [];
        /** @var array<int,array{0:DOMElement,1:string,2:string,3:?string}> $attrItems */
        $attrItems = [];

        $this->collect($fragment, $textItems, $attrItems);

        // Dispatch attributes first, then text (matching JS observer order)
        foreach ($attrItems as [$element, $attr, $value, $scope]) {
            $onAttribute($element, $attr, $value, $scope);
        }
        foreach ($textItems as [$element, $text, $scope, $keyOverride, $isInnerHtml]) {
            $onText($element, $text, $scope, $keyOverride, $isInnerHtml);
        }

        return $parser->saveHTML($fragment);
    }

    /**
     * Collect translatable text and attribute items from a parsed DOM subtree.
     * Items are written into the provided arrays so the caller can dispatch them
     * after the traversal completes (avoiding mid-walk DOM mutation hazards).
     *
     * @param array<int,array{0:DOMElement,1:string,2:?string,3:?string,4:bool}> $textItems
     * @param array<int,array{0:DOMElement,1:string,2:string,3:?string}> $attrItems
     */
    public function collect(
        DOMNode $root,
        array &$textItems,
        array &$attrItems,
    ): void {
        /** @var array<int,DOMElement> $aggregatedParents */
        $aggregatedParents = [];

        $walk = function (DOMNode $node) use (&$walk, &$textItems, &$attrItems, &$aggregatedParents): void {
            if ($this->isIgnored($node)) {
                return;
            }

            if ($node instanceof DOMElement) {
                // Collect translatable attributes on this element
                foreach ($this->translatableAttributes as $attr) {
                    if (!$node->hasAttribute($attr)) {
                        continue;
                    }
                    $value = $node->getAttribute($attr);
                    if (trim($value) === '') {
                        continue;
                    }
                    $scope = $this->resolveScope($node);
                    $attrItems[] = [$node, $attr, $value, $scope];
                }
            }

            if ($node instanceof DOMText) {
                $text = $node->textContent ?? '';
                if (trim($text) === '') {
                    return;
                }
                $parent = $node->parentNode;
                if (!$parent instanceof DOMElement) {
                    return;
                }

                $aggregationTarget = $this->findAggregationTarget($parent);
                if ($aggregationTarget !== null) {
                    $hash = spl_object_id($aggregationTarget);
                    if (!isset($aggregatedParents[$hash])) {
                        $aggregatedParents[$hash] = $aggregationTarget;
                        $innerHtml = $this->serializeAggregate($aggregationTarget);
                        if (trim($innerHtml) !== '') {
                            $textItems[] = [
                                $aggregationTarget,
                                $innerHtml,
                                $this->resolveScope($aggregationTarget),
                                $aggregationTarget->getAttribute($this->keyAttribute) ?: null,
                                true,
                            ];
                        }
                    }
                    return;
                }

                $textItems[] = [
                    $parent,
                    $text,
                    $this->resolveScope($parent),
                    $parent->getAttribute($this->keyAttribute) ?: null,
                    false,
                ];
                return;
            }

            // Descend into children for elements/fragments
            if ($node->hasChildNodes()) {
                // Snapshot children before iterating: callers may mutate the tree.
                // For collection (no mutation here), iteration order is stable.
                foreach (iterator_to_array($node->childNodes, false) as $child) {
                    $walk($child);
                }
            }
        };

        $walk($root);
    }

    private function isIgnored(DOMNode $node): bool
    {
        $current = $node;
        while ($current !== null) {
            if ($current instanceof DOMElement && $this->isIgnoredElement($current)) {
                return true;
            }
            $current = $current->parentNode;
        }
        return false;
    }

    /**
     * True when $el itself is an ignore boundary — the per-element half of the
     * ignore decision, reused by {@see isIgnored} (ancestor walk) and by the
     * aggregation path, which must spot an ignored *descendant* of a target that
     * is not itself ignored.
     */
    private function isIgnoredElement(DOMElement $el): bool
    {
        if ($el->hasAttribute($this->ignoreAttribute)) {
            return true;
        }
        $tag = strtolower($el->nodeName);
        foreach ($this->ignoreSelectors as $sel) {
            if ($this->matchesSimpleSelector($el, $tag, $sel)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Minimal CSS selector matcher: bare tag name ("script") and attribute presence ("[attr]").
     * No classes, IDs, descendants, or pseudo-selectors — keeps PHP-side dep-free.
     * Aligns with the typical `ignoreSelectors` defaults (script, style, code, [data-i18n-ignore]).
     */
    private function matchesSimpleSelector(DOMElement $el, string $tag, string $selector): bool
    {
        $sel = trim($selector);
        if ($sel === '') {
            return false;
        }
        if (preg_match('/^\[([\w-]+)\]$/', $sel, $m) === 1) {
            return $el->hasAttribute($m[1]);
        }
        return strtolower($sel) === $tag;
    }

    private function resolveScope(DOMElement $element): ?string
    {
        $current = $element;
        while ($current !== null) {
            if ($current instanceof DOMElement) {
                $scope = $current->getAttribute($this->scopeAttribute);
                if ($scope !== '') {
                    return $scope;
                }
            }
            $current = $current->parentNode;
        }
        return null;
    }

    private function findAggregationTarget(DOMElement $element): ?DOMElement
    {
        $current = $element;
        while ($current instanceof DOMElement) {
            if ($this->hasInlineChildElements($current)) {
                return $current;
            }
            // If element is itself an inline tag, look at its parent for aggregation
            if (isset($this->allowedInlineTags[strtolower($current->nodeName)])) {
                $next = $current->parentNode;
                if (!$next instanceof DOMElement) {
                    return null;
                }
                $current = $next;
                continue;
            }
            return null;
        }
        return null;
    }

    private function hasInlineChildElements(DOMElement $element): bool
    {
        $childCount = 0;
        // Every child — and its entire subtree — must be inline-allowed. A non-inline
        // element anywhere below (e.g. an <input> or <svg> nested in an otherwise
        // inline <span>) means this isn't a formatted run of text; aggregating it
        // would drag non-translatable markup into the cache key.
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $childCount++;
                if (!$this->isFullyInline($child)) {
                    return false;
                }
            }
        }
        if ($childCount === 0) {
            return false;
        }
        // Aggregate only a genuine formatted sentence — one with its own direct,
        // interleaved text. A container whose children are all inline elements but
        // which has no direct text of its own (a nav menu, link list, or button
        // group) is structural, not a sentence; translate its children individually
        // so each keeps its own cache key. (This subsumes the single-inline-child
        // wrapper case.)
        if (!$this->hasDirectTextContent($element)) {
            return false;
        }
        return true;
    }

    /** True when $element and all of its descendant elements are allowed inline tags. */
    private function isFullyInline(DOMElement $element): bool
    {
        if (!isset($this->allowedInlineTags[strtolower($element->nodeName)])) {
            return false;
        }
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && !$this->isFullyInline($child)) {
                return false;
            }
        }
        return true;
    }

    private function hasDirectTextContent(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent ?? '') !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Inner HTML of an aggregation target, but with every topmost ignored
     * descendant subtree bracketed in U+E000…U+E001 sentinels so the Masker masks
     * it as one opaque variable (keeping its user-data text out of the cache key).
     * Detection runs on the live tree (so ancestor-aware selectors resolve) while
     * the brackets are inserted into a detached clone, read back as an accurate
     * serialization. Returns plain inner HTML when there's no ignored descendant.
     */
    private function serializeAggregate(DOMElement $target): string
    {
        if (!$this->hasIgnoredDescendant($target)) {
            return $this->getInnerHtml($target);
        }
        $clone = $target->cloneNode(true);
        if ($clone instanceof DOMElement) {
            $this->wrapIgnoredRegions($target, $clone);
            return $this->getInnerHtml($clone);
        }
        return $this->getInnerHtml($target);
    }

    private function hasIgnoredDescendant(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if ($this->isIgnoredElement($child)) {
                    return true;
                }
                if ($this->hasIgnoredDescendant($child)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Walk $live and $clone in lockstep; bracket each topmost ignored descendant
     * in the clone with sentinel text nodes (don't descend into it), recursing
     * into non-ignored elements to reach deeper ignored subtrees.
     */
    private function wrapIgnoredRegions(DOMElement $live, DOMElement $clone): void
    {
        $liveChildren = iterator_to_array($live->childNodes, false);
        $cloneChildren = iterator_to_array($clone->childNodes, false);
        $doc = $clone->ownerDocument;
        if ($doc === null) {
            return;
        }
        foreach ($liveChildren as $idx => $liveChild) {
            $cloneChild = $cloneChildren[$idx] ?? null;
            if (!$liveChild instanceof DOMElement || !$cloneChild instanceof DOMNode) {
                continue;
            }
            if ($this->isIgnoredElement($liveChild)) {
                $clone->insertBefore($doc->createTextNode(Masker::IGNORE_OPEN), $cloneChild);
                $after = $cloneChild->nextSibling;
                $closeNode = $doc->createTextNode(Masker::IGNORE_CLOSE);
                if ($after !== null) {
                    $clone->insertBefore($closeNode, $after);
                } else {
                    $clone->appendChild($closeNode);
                }
            } elseif ($cloneChild instanceof DOMElement) {
                $this->wrapIgnoredRegions($liveChild, $cloneChild);
            }
        }
    }

    public function getInnerHtml(DOMElement $element): string
    {
        $doc = $element->ownerDocument;
        if ($doc === null) {
            return '';
        }
        $html = '';
        $parser = new HTML5();
        foreach ($element->childNodes as $child) {
            $html .= $parser->saveHTML($child);
        }
        return $html;
    }

    /**
     * Replace an element's children with the given HTML string.
     */
    public function setInnerHtml(DOMElement $element, string $html): void
    {
        // Remove existing children
        while ($element->firstChild !== null) {
            $element->removeChild($element->firstChild);
        }
        if ($html === '') {
            return;
        }
        $doc = $element->ownerDocument;
        if ($doc === null) {
            return;
        }
        $parser = new HTML5();
        $fragment = $parser->loadHTMLFragment($html, ['target_document' => $doc]);
        if ($fragment instanceof DOMDocumentFragment) {
            $element->appendChild($fragment);
        }
    }

    /**
     * Replace an element's children with a plain-text node (no HTML parsing).
     */
    public function setTextContent(DOMElement $element, string $text): void
    {
        while ($element->firstChild !== null) {
            $element->removeChild($element->firstChild);
        }
        if ($text !== '') {
            $doc = $element->ownerDocument;
            if ($doc !== null) {
                $element->appendChild($doc->createTextNode($text));
            }
        }
    }
}
