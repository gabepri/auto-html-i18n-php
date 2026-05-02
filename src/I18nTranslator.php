<?php

declare(strict_types=1);

namespace AutoDomI18n;

use Closure;
use DOMElement;
use InvalidArgumentException;

/**
 * Server-side facade. Single-pass synchronous transform of HTML strings or pre-masked keys.
 *
 * Configuration keys (passed as an associative array to the constructor):
 *   locale (required, string)
 *   onMissingTranslation (required, callable(TranslationItem[], string $locale): array<string,string|array<string,string>>)
 *   allowedInlineTags (string[], default: a/b/i/u/strong/em/span/small/mark/del)
 *   translatableAttributes (string[], default: title/placeholder/alt/aria-label)
 *   ignoreSelectors (string[], default: script/style/code) — bare tag names or [attr] form
 *   ignoreWords (array, default: [])
 *   initialCache (array<string,string|array<string,string>>, default: [])
 *   originalAttribute / pendingAttribute / keyAttribute / ignoreAttribute / scopeAttribute (strings)
 *   debug (bool, default: false)
 */
final class I18nTranslator
{
    private const DEFAULTS = [
        'allowedInlineTags' => ['a', 'b', 'i', 'u', 'strong', 'em', 'span', 'small', 'mark', 'del'],
        'translatableAttributes' => ['title', 'placeholder', 'alt', 'aria-label'],
        'ignoreSelectors' => ['script', 'style', 'code'],
        'ignoreWords' => [],
        'initialCache' => [],
        'originalAttribute' => 'data-i18n-original',
        'pendingAttribute' => 'data-i18n-pending',
        'keyAttribute' => 'data-i18n-key',
        'ignoreAttribute' => 'data-i18n-ignore',
        'scopeAttribute' => 'data-i18n-scope',
        'debug' => false,
    ];

    private string $locale;

    private readonly Closure $onMissingTranslation;

    private readonly Store $store;
    private readonly Masker $masker;
    private readonly HtmlWalker $walker;
    private readonly bool $debug;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config)
    {
        if (!isset($config['locale']) || !is_string($config['locale'])) {
            throw new InvalidArgumentException('I18nTranslator: "locale" is required and must be a string.');
        }
        if (!isset($config['onMissingTranslation']) || !is_callable($config['onMissingTranslation'])) {
            throw new InvalidArgumentException('I18nTranslator: "onMissingTranslation" is required and must be callable.');
        }

        $merged = array_replace(self::DEFAULTS, $config);

        $this->locale = $merged['locale'];
        $this->onMissingTranslation = Closure::fromCallable($merged['onMissingTranslation']);
        $this->debug = (bool) $merged['debug'];

        $this->store = new Store();
        if (is_array($merged['initialCache']) && $merged['initialCache'] !== []) {
            $this->store->loadBulk($this->locale, $merged['initialCache']);
        }

        $this->masker = new Masker($merged['ignoreWords'], $merged['allowedInlineTags']);

        $this->walker = new HtmlWalker(
            $merged['allowedInlineTags'],
            $merged['ignoreSelectors'],
            $merged['translatableAttributes'],
            $merged['ignoreAttribute'],
            $merged['scopeAttribute'],
            $merged['keyAttribute'],
        );
    }

    /**
     * Translate every translatable text node and attribute in the given HTML fragment.
     * Returns the translated HTML. Calls onMissingTranslation once with the full batch of unknowns.
     */
    public function translateHtml(string $html): string
    {
        if ($html === '' || trim($html) === '') {
            return $html;
        }

        /** @var array<string,array<int,array{0:DOMElement,1:MaskResult,2:string,3:bool,4:bool,5:?string,6:?string}>> $pending */
        $pending = [];
        /** @var array<string,TranslationItem> $missingItems */
        $missingItems = [];

        $onText = function (DOMElement $element, string $text, ?string $scope, ?string $keyOverride, bool $isInnerHtml) use (&$pending, &$missingItems): void {
            $isHtml = $isInnerHtml || preg_match('/<[^>]+>/', $text) === 1;
            $maskResult = $this->masker->mask($text);

            if ($keyOverride === null && !self::hasTranslatableContent($maskResult->masked)) {
                return;
            }

            $cacheKey = $keyOverride ?? $maskResult->masked;
            $entry = $this->store->get($this->locale, $cacheKey);

            if ($entry !== null && $entry->status === EntryStatus::Resolved && $entry->value !== null) {
                $resolved = Resolver::resolve($entry->value, $scope);
                if ($resolved !== null) {
                    $this->applyTextTranslation($element, $resolved, $maskResult, $isHtml);
                    return;
                }
            }

            // Already reported as missing — skip
            if ($entry !== null && $entry->status === EntryStatus::Reported) {
                return;
            }

            // Track pending and add to missing batch (deduplicated by masked key)
            $pending[$cacheKey][] = [$element, $maskResult, $text, $isHtml, false, null, $scope];
            if (!isset($missingItems[$cacheKey])) {
                $missingItems[$cacheKey] = new TranslationItem(
                    $cacheKey,
                    $text,
                    $maskResult->variables,
                    $scope,
                    $this->debug ? self::collectDebug($element, 'text') : null,
                );
            }
        };

        $onAttribute = function (DOMElement $element, string $attr, string $value, ?string $scope) use (&$pending, &$missingItems): void {
            $maskResult = $this->masker->mask($value);
            if (!self::hasTranslatableContent($maskResult->masked)) {
                return;
            }
            $cacheKey = $maskResult->masked;
            $entry = $this->store->get($this->locale, $cacheKey);

            if ($entry !== null && $entry->status === EntryStatus::Resolved && $entry->value !== null) {
                $resolved = Resolver::resolve($entry->value, $scope);
                if ($resolved !== null) {
                    $this->applyAttributeTranslation($element, $attr, $resolved, $maskResult);
                    return;
                }
            }

            if ($entry !== null && $entry->status === EntryStatus::Reported) {
                return;
            }

            $pending[$cacheKey][] = [$element, $maskResult, $value, false, true, $attr, $scope];
            if (!isset($missingItems[$cacheKey])) {
                $missingItems[$cacheKey] = new TranslationItem(
                    $cacheKey,
                    $value,
                    $maskResult->variables,
                    $scope,
                    $this->debug ? self::collectDebug($element, 'attribute:' . $attr) : null,
                );
            }
        };

        // Walk-and-mutate: pass 1 collects items, applies known translations inline,
        // and registers unknowns in $pending. The walker returns serialized HTML — but we
        // need to apply pending translations BEFORE serialization. Since pass 1 mutates the
        // DOM in place, we can't use the walker's return value as-is for unknowns.
        // Instead, we call the walker twice: once to collect (no mutation; mutation happens inside callbacks for known cases), then apply pending, then re-serialize.

        // Strategy: parse once, walk-and-mutate via the callbacks, then serialize after pending writeback.
        $parser = new \Masterminds\HTML5();
        $fragment = $parser->loadHTMLFragment($html);
        if (!$fragment instanceof \DOMDocumentFragment) {
            return $html;
        }

        // Collect all items first using the walker's collect logic via reflection-free reuse:
        // we re-implement the collect/dispatch loop here to keep mutation in our hands.
        $this->walkFragment($fragment, $onText, $onAttribute);

        // Resolve unknowns via callback (single batched call)
        if ($missingItems !== []) {
            $items = array_values($missingItems);
            $callback = $this->onMissingTranslation;
            $result = $callback($items, $this->locale);
            if (!is_array($result)) {
                $result = [];
            }

            foreach ($items as $item) {
                if (array_key_exists($item->masked, $result)) {
                    $value = $result[$item->masked];
                    if (is_string($value) || is_array($value)) {
                        $this->store->set($this->locale, $item->masked, $value);
                    }
                } else {
                    $this->store->markReported($this->locale, $item->masked);
                }
            }

            // Write back pending nodes for newly-resolved keys
            foreach ($pending as $cacheKey => $nodes) {
                $entry = $this->store->get($this->locale, $cacheKey);
                if ($entry === null || $entry->status !== EntryStatus::Resolved || $entry->value === null) {
                    continue;
                }
                foreach ($nodes as [$element, $maskResult, , $isHtml, $isAttribute, $attrName, $scope]) {
                    $resolved = Resolver::resolve($entry->value, $scope);
                    if ($resolved === null) {
                        continue;
                    }
                    if ($isAttribute && $attrName !== null) {
                        $this->applyAttributeTranslation($element, $attrName, $resolved, $maskResult);
                    } else {
                        $this->applyTextTranslation($element, $resolved, $maskResult, $isHtml);
                    }
                }
            }
        }

        return $parser->saveHTML($fragment);
    }

    /**
     * Translate a pre-masked key with positional variable substitution.
     * Mirrors the JS imperative API: no masking, no ICU — just `{{N}}` substitution.
     *
     * @param array<int,string> $variables
     */
    public function translate(string $text, array $variables = [], ?string $scope = null): string
    {
        $entry = $this->store->get($this->locale, $text);
        if ($entry !== null && $entry->status === EntryStatus::Resolved && $entry->value !== null) {
            $translated = is_string($entry->value)
                ? $entry->value
                : ($scope !== null ? ($entry->value[$scope] ?? $text) : $text);
        } else {
            $translated = $text;
        }

        if ($variables === []) {
            return $translated;
        }
        $out = preg_replace_callback(
            '/\{\{(\d+)\}\}/',
            static fn(array $m): string => $variables[(int) $m[1]] ?? '{{' . $m[1] . '}}',
            $translated,
        );
        return is_string($out) ? $out : $translated;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * @param array<string,string|array<string,string>> $data
     */
    public function setCache(string $locale, array $data): void
    {
        $this->store->loadBulk($locale, $data);
    }

    /**
     * @return array<string,string|array<string,string>>
     */
    public function getCache(?string $locale = null): array
    {
        return $this->store->getCache($locale ?? $this->locale);
    }

    public function clearCache(?string $locale = null): void
    {
        $this->store->clearCache($locale);
    }

    /**
     * @return string|array<string,string>|null
     */
    public function getTranslation(string $key, ?string $locale = null): string|array|null
    {
        $entry = $this->store->get($locale ?? $this->locale, $key);
        if ($entry !== null && $entry->status === EntryStatus::Resolved) {
            return $entry->value;
        }
        return null;
    }

    /**
     * @return array<string|array{word:string,meta:array<string,string>}>
     */
    public function getIgnoreWords(): array
    {
        return $this->masker->getIgnoreWords();
    }

    /**
     * @param array<string|array{word:string,meta?:array<string,string>}|IgnoreWordEntry> $words
     */
    public function addIgnoreWords(array $words): void
    {
        $this->masker->addIgnoreWords($words);
    }

    /**
     * @param array<string|array{word:string,meta?:array<string,string>}|IgnoreWordEntry> $words
     */
    public function removeIgnoreWords(array $words): void
    {
        $this->masker->removeIgnoreWords($words);
    }

    /**
     * @param array<string|array{word:string,meta?:array<string,string>}|IgnoreWordEntry> $words
     */
    public function setIgnoreWords(array $words): void
    {
        $this->masker->setIgnoreWords($words);
    }

    private function applyTextTranslation(DOMElement $element, string $resolved, MaskResult $maskResult, bool $isHtml): void
    {
        $unmasked = $this->masker->unmask($resolved, $maskResult->variables, $maskResult->tagAttributes, $this->locale);
        $output = $maskResult->leadingWhitespace
            . $this->masker->applyCasePattern($unmasked, $maskResult->casePattern)
            . $maskResult->trailingWhitespace;

        // unmask() may emit HTML entities like &lt; (sanitized output) that must be
        // parsed-then-reserialized rather than treated as literal text — otherwise the
        // serializer double-escapes the &.
        $this->walker->setInnerHtml($element, $output);
    }

    private function applyAttributeTranslation(DOMElement $element, string $attr, string $resolved, MaskResult $maskResult): void
    {
        $unmasked = $this->masker->unmask($resolved, $maskResult->variables, $maskResult->tagAttributes, $this->locale);
        $output = $maskResult->leadingWhitespace
            . $this->masker->applyCasePattern($unmasked, $maskResult->casePattern)
            . $maskResult->trailingWhitespace;
        $element->setAttribute($attr, $output);
    }

    /**
     * Walk a parsed HTML fragment and dispatch text/attribute callbacks. Mutation by callbacks is allowed.
     *
     * @param callable(DOMElement, string, ?string, ?string, bool):void $onText
     * @param callable(DOMElement, string, string, ?string):void $onAttribute
     */
    private function walkFragment(\DOMDocumentFragment $fragment, callable $onText, callable $onAttribute): void
    {
        /** @var array<int,array{0:DOMElement,1:string,2:?string,3:?string,4:bool}> $textItems */
        $textItems = [];
        /** @var array<int,array{0:DOMElement,1:string,2:string,3:?string}> $attrItems */
        $attrItems = [];
        $this->walker->collect($fragment, $textItems, $attrItems);

        foreach ($attrItems as [$element, $attr, $value, $scope]) {
            $onAttribute($element, $attr, $value, $scope);
        }
        foreach ($textItems as [$element, $text, $scope, $keyOverride, $isInnerHtml]) {
            $onText($element, $text, $scope, $keyOverride, $isInnerHtml);
        }
    }

    private static function hasTranslatableContent(string $masked): bool
    {
        $stripped = preg_replace('/\{\{\d+\}\}/', '', $masked) ?? $masked;
        $stripped = preg_replace('/<[^>]*>/', '', $stripped) ?? $stripped;
        return preg_match('/\p{L}/u', $stripped) === 1;
    }

    /**
     * @return array<string,mixed>
     */
    private static function collectDebug(DOMElement $element, string $source): array
    {
        $childElements = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $childElements[] = [
                    'tag' => strtoupper($child->nodeName),
                    'classes' => $child->getAttribute('class'),
                ];
            }
        }
        // Best-effort opening tag extraction
        $doc = $element->ownerDocument;
        $openTag = '';
        if ($doc !== null) {
            $clone = $element->cloneNode(false);
            $openTag = $doc->saveHTML($clone) ?: '';
            // saveHTML on a self-closing clone may produce "<el></el>"; trim the closing
            if (preg_match('/^(<[^>]+>)/', $openTag, $m) === 1) {
                $openTag = $m[1];
            }
        }
        return [
            'elementOpenTag' => $openTag,
            'childElements' => $childElements,
            'source' => $source,
        ];
    }
}
