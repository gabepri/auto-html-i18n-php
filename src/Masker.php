<?php

declare(strict_types=1);

namespace AutoHtmlI18n;

use MessageFormatter;

final class Masker
{
    /**
     * Invisible bidi formatting characters (marks, embeddings, isolates).
     * Stripped during masking so a key stays stable when already-translated
     * (isolate-wrapped) content is re-masked.
     */
    private const BIDI_CONTROLS = '/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    private const FSI = "\u{2068}"; // FIRST STRONG ISOLATE
    private const PDI = "\u{2069}"; // POP DIRECTIONAL ISOLATE

    /** @var IgnoreWordEntry[] */
    private array $ignoreWords;

    /** @var array<string,true> */
    private array $allowedInlineTags;

    private string $variableRegex;

    /** @var VariableType[] */
    private array $groupTypeMap = [];

    /**
     * @param array<string|array{word:string,meta?:array<string,string>}|IgnoreWordEntry> $ignoreWords
     * @param string[] $allowedInlineTags
     */
    public function __construct(array $ignoreWords, array $allowedInlineTags)
    {
        $this->ignoreWords = self::sortLongestFirst(IgnoreWordEntry::normalize($ignoreWords));
        $this->allowedInlineTags = [];
        foreach ($allowedInlineTags as $t) {
            $this->allowedInlineTags[strtolower($t)] = true;
        }
        $this->variableRegex = $this->buildVariableRegex();
    }

    public function mask(string $text): MaskResult
    {
        $text = preg_replace(self::BIDI_CONTROLS, '', $text) ?? $text;
        if ($text === '') {
            return new MaskResult('', [], [], CasePattern::Lower, '', '');
        }

        // Phase 1: Normalize inline tags (strip attributes, assign indices)
        /** @var array<string,array<string,string>> $tagAttributes */
        $tagAttributes = [];
        /** @var array<string,int> $tagCounters */
        $tagCounters = [];

        // Opening tags
        $tagProcessed = preg_replace_callback(
            '/<(\w+)(\s[^>]*)?\s*>/',
            function (array $m) use (&$tagAttributes, &$tagCounters): string {
                $tagName = $m[1];
                $attrString = $m[2] ?? '';
                $lower = strtolower($tagName);
                if (!isset($this->allowedInlineTags[$lower])) {
                    return $m[0];
                }
                $count = $tagCounters[$lower] ?? 0;
                $tagCounters[$lower] = $count + 1;
                $tagKey = $lower . $count;

                $attrs = self::parseAttributes($attrString);
                $tagAttributes[$tagKey] = $attrs;
                return '<' . $tagKey . '>';
            },
            $text,
        );
        if (!is_string($tagProcessed)) {
            $tagProcessed = $text;
        }

        // Closing tags
        /** @var array<string,int> $closingTagCounters */
        $closingTagCounters = [];
        $tagProcessed = preg_replace_callback(
            '#</(\w+)\s*>#',
            function (array $m) use (&$closingTagCounters): string {
                $lower = strtolower($m[1]);
                if (!isset($this->allowedInlineTags[$lower])) {
                    return $m[0];
                }
                $count = $closingTagCounters[$lower] ?? 0;
                $closingTagCounters[$lower] = $count + 1;
                return '</' . $lower . $count . '>';
            },
            $tagProcessed,
        );
        if (!is_string($tagProcessed)) {
            $tagProcessed = $text;
        }

        // Phase 2: Mask variables. Use byte-by-byte walk so we can skip tag interiors
        // and detect <!-- comments --> the same way the JS version does.
        /** @var VariableInfo[] $variables */
        $variables = [];
        $masked = '';
        $i = 0;
        $len = strlen($tagProcessed);

        while ($i < $len) {
            // HTML comment masked as a variable
            if (substr($tagProcessed, $i, 4) === '<!--') {
                $closeIdx = strpos($tagProcessed, '-->', $i + 4);
                if ($closeIdx !== false) {
                    $comment = substr($tagProcessed, $i, $closeIdx + 3 - $i);
                    $idx = count($variables);
                    $variables[] = new VariableInfo($comment, VariableType::Comment);
                    $masked .= '{{' . $idx . '}}';
                    $i = $closeIdx + 3;
                    continue;
                }
            }

            // Skip past tag contents — copy verbatim
            if ($tagProcessed[$i] === '<') {
                $closeIdx = strpos($tagProcessed, '>', $i);
                if ($closeIdx !== false) {
                    $masked .= substr($tagProcessed, $i, $closeIdx + 1 - $i);
                    $i = $closeIdx + 1;
                    continue;
                }
            }

            // Try variable regex anchored at $i
            // PHP doesn't have a sticky flag like JS — use \G + offset
            $haystack = substr($tagProcessed, $i);
            if (preg_match($this->variableRegex, $haystack, $m, 0, 0) === 1 && isset($m[0]) && $m[0] !== '') {
                // Match must be anchored at position 0 of the substring.
                // preg_match without anchor returns the leftmost match which may be after offset 0;
                // we need to verify it began exactly at offset 0.
                if (strpos($haystack, $m[0]) === 0) {
                    $idx = count($variables);
                    $variables[] = $this->buildVariableInfo($m);
                    $masked .= '{{' . $idx . '}}';
                    $i += strlen($m[0]);
                    continue;
                }
            }

            // Default: copy one byte
            $masked .= $tagProcessed[$i];
            $i++;
        }

        $casePattern = self::detectCasePattern($masked);
        if ($casePattern === CasePattern::Upper) {
            $masked = mb_strtolower($masked, 'UTF-8');
        }

        // Trim leading/trailing whitespace from the key, preserving it for restoration
        $leading = '';
        if (preg_match('/^\s+/u', $masked, $m)) {
            $leading = $m[0];
        }
        $trailing = '';
        if (preg_match('/\s+$/u', $masked, $m)) {
            $trailing = $m[0];
        }
        if ($leading !== '' || $trailing !== '') {
            $masked = substr($masked, strlen($leading), strlen($masked) - strlen($leading) - strlen($trailing));
        }

        return new MaskResult($masked, $variables, $tagAttributes, $casePattern, $leading, $trailing);
    }

    public function applyCasePattern(string $text, CasePattern $casePattern): string
    {
        if ($casePattern !== CasePattern::Upper) {
            return $text;
        }

        $result = '';
        $inTag = false;
        // Iterate by code point so multi-byte chars (e.g. í) aren't split mid-byte.
        foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
            if ($ch === '<') {
                $inTag = true;
            }
            $result .= $inTag ? $ch : mb_strtoupper($ch, 'UTF-8');
            if ($ch === '>') {
                $inTag = false;
            }
        }
        return $result;
    }

    /**
     * @param VariableInfo[] $variables
     * @param array<string,array<string,string>> $tagAttributes
     */
    public function unmask(string $translated, array $variables, array $tagAttributes, ?string $locale = null, ?string $original = null): string
    {
        if ($translated === '') {
            return '';
        }

        $format = $this->detectFormat($translated);

        if ($format === TranslationFormat::Icu && $locale !== null) {
            $result = $this->evaluateICU($translated, $variables, $locale);
            if ($result === null) {
                if ($original !== null) {
                    // Fall back to the untranslated source text. It needs no tag
                    // restoration or sanitizing, but is trimmed so callers can
                    // re-apply the edge whitespace they extracted at mask time.
                    $stripped = preg_replace('/^\s+/u', '', $original) ?? $original;
                    return preg_replace('/\s+$/u', '', $stripped) ?? $stripped;
                }
                // No original available — fall back to the raw pattern
                $result = $translated;
            }
        } else {
            $isolate = $locale !== null && TextDirection::forLocale($locale) === TextDirection::Rtl;
            $result = preg_replace_callback(
                '/\{\{(\d+)\}\}/',
                function (array $m) use ($variables, $isolate): string {
                    $variable = $variables[(int) $m[1]] ?? null;
                    if ($variable === null) {
                        return '{{' . $m[1] . '}}';
                    }
                    return $isolate && self::isBidiIsolated($variable->type)
                        ? self::FSI . $variable->value . self::PDI
                        : $variable->value;
                },
                $translated,
            );
            $result = is_string($result) ? $result : $translated;
        }

        $result = $this->restoreTagAttributes($result, $tagAttributes);

        // Sanitize: escape any HTML tags not in the allowlist
        return $this->sanitizeTags($result);
    }

    /**
     * Dry-runs a translation string exactly as consumption would: detects the
     * format ({{N}} simple, {N ICU, or plain), evaluates it against the given
     * variables, and reports the rendered output or the failure reason.
     *
     * @param VariableInfo[] $variables
     * @param array<string,array<string,string>> $tagAttributes
     */
    public function validateIcu(string $translated, array $variables, string $locale, array $tagAttributes = []): IcuValidationResult
    {
        $format = $this->detectFormat($translated);

        if ($format === TranslationFormat::Icu) {
            [$output, $error] = $this->evaluateICUDetailed($translated, $variables, $locale);
            if ($output === null) {
                return new IcuValidationResult(false, $format, $error ?? 'ICU evaluation failed');
            }
        } elseif ($format === TranslationFormat::Simple) {
            $missing = [];
            $isolate = TextDirection::forLocale($locale) === TextDirection::Rtl;
            $output = preg_replace_callback(
                '/\{\{(\d+)\}\}/',
                function (array $m) use ($variables, $isolate, &$missing): string {
                    $idx = (int) $m[1];
                    if (!isset($variables[$idx])) {
                        $missing[$m[0]] = true;
                        return $m[0];
                    }
                    $variable = $variables[$idx];
                    return $isolate && self::isBidiIsolated($variable->type)
                        ? self::FSI . $variable->value . self::PDI
                        : $variable->value;
                },
                $translated,
            );
            $output = is_string($output) ? $output : $translated;
            if ($missing !== []) {
                return new IcuValidationResult(
                    false,
                    $format,
                    'substitution references ' . implode(', ', array_keys($missing))
                        . ' but only ' . count($variables) . ' variable(s) were provided',
                );
            }
        } else {
            $output = $translated;
        }

        if ($tagAttributes !== []) {
            $output = $this->restoreTagAttributes($output, $tagAttributes);
        }
        $output = $this->sanitizeTags($output);

        return new IcuValidationResult(true, $format, null, $output);
    }

    /**
     * Masks $original to derive the variables and tag attributes consumption
     * would see, then validates $translated against them — including the case
     * pattern and edge whitespace the rendered output would carry.
     */
    public function validateTranslation(string $original, string $translated, string $locale): IcuValidationResult
    {
        $maskResult = $this->mask($original);
        $result = $this->validateIcu($translated, $maskResult->variables, $locale, $maskResult->tagAttributes);
        if (!$result->valid || $result->output === null) {
            return $result;
        }
        $output = $maskResult->leadingWhitespace
            . $this->applyCasePattern($result->output, $maskResult->casePattern)
            . $maskResult->trailingWhitespace;
        return new IcuValidationResult(true, $result->format, null, $output);
    }

    /**
     * Variable types wrapped in FSI…PDI when substituted into RTL output.
     * Comments are markup and symbols are direction-neutral, so neither is isolated.
     */
    private static function isBidiIsolated(VariableType $type): bool
    {
        return $type !== VariableType::Symbol && $type !== VariableType::Comment;
    }

    /**
     * How a translation string will be consumed: {{N}} = simple substitution
     * (our format), {N} or {N, plural/select, ...} = ICU, otherwise plain text.
     * Single source of truth for both unmask() and validateIcu().
     */
    private function detectFormat(string $translated): TranslationFormat
    {
        if (preg_match('/\{\{\d+\}\}/', $translated) === 1) {
            return TranslationFormat::Simple;
        }
        if (preg_match('/\{\d+/', $translated) === 1) {
            return TranslationFormat::Icu;
        }
        return TranslationFormat::Plain;
    }

    /**
     * Restores opening tags (<tagN> -> <tag attrs...>) and closing tags (</tagN> -> </tag>).
     *
     * @param array<string,array<string,string>> $tagAttributes
     */
    private function restoreTagAttributes(string $text, array $tagAttributes): string
    {
        $result = preg_replace_callback(
            '/<(\w+?)(\d+)>/',
            function (array $m) use ($tagAttributes): string {
                $tagKey = $m[1] . $m[2];
                if (!isset($tagAttributes[$tagKey])) {
                    return '<' . $m[1] . $m[2] . '>';
                }
                $attrs = $tagAttributes[$tagKey];
                $parts = [];
                foreach ($attrs as $name => $value) {
                    if (str_starts_with(strtolower($name), 'on')) {
                        continue; // strip event handlers
                    }
                    $parts[] = $name . '="' . $value . '"';
                }
                $safeAttrs = implode(' ', $parts);
                return $safeAttrs === '' ? '<' . $m[1] . '>' : '<' . $m[1] . ' ' . $safeAttrs . '>';
            },
            $text,
        ) ?? $text;

        return preg_replace_callback(
            '#</(\w+?)(\d+)>#',
            function (array $m) use ($tagAttributes): string {
                $tagKey = $m[1] . $m[2];
                return isset($tagAttributes[$tagKey]) ? '</' . $m[1] . '>' : '</' . $m[1] . $m[2] . '>';
            },
            $result,
        ) ?? $result;
    }

    /**
     * @return array<string|array{word:string,meta:array<string,string>}>
     */
    public function getIgnoreWords(): array
    {
        $out = [];
        foreach ($this->ignoreWords as $w) {
            $out[] = $w->meta !== null ? ['word' => $w->word, 'meta' => $w->meta] : $w->word;
        }
        return $out;
    }

    /**
     * @param array<string|array{word:string,meta?:array<string,string>}|IgnoreWordEntry> $words
     */
    public function addIgnoreWords(array $words): void
    {
        $normalized = IgnoreWordEntry::normalize($words);
        $existing = [];
        foreach ($this->ignoreWords as $w) {
            $existing[$w->word] = true;
        }
        $changed = false;
        foreach ($normalized as $entry) {
            if (!isset($existing[$entry->word])) {
                $existing[$entry->word] = true;
                $this->ignoreWords[] = $entry;
                $changed = true;
            }
        }
        if ($changed) {
            $this->ignoreWords = self::sortLongestFirst($this->ignoreWords);
            $this->variableRegex = $this->buildVariableRegex();
        }
    }

    /**
     * @param array<string|array{word:string,meta?:array<string,string>}|IgnoreWordEntry> $words
     */
    public function removeIgnoreWords(array $words): void
    {
        $toRemove = [];
        foreach (IgnoreWordEntry::normalize($words) as $e) {
            $toRemove[$e->word] = true;
        }
        $newList = [];
        foreach ($this->ignoreWords as $w) {
            if (!isset($toRemove[$w->word])) {
                $newList[] = $w;
            }
        }
        if (count($newList) !== count($this->ignoreWords)) {
            $this->ignoreWords = $newList;
            $this->variableRegex = $this->buildVariableRegex();
        }
    }

    /**
     * @param array<string|array{word:string,meta?:array<string,string>}|IgnoreWordEntry> $words
     */
    public function setIgnoreWords(array $words): void
    {
        $this->ignoreWords = self::sortLongestFirst(IgnoreWordEntry::normalize($words));
        $this->variableRegex = $this->buildVariableRegex();
    }

    /**
     * @param array<int|string,string|null> $match
     */
    private function buildVariableInfo(array $match): VariableInfo
    {
        // Determine which capturing group matched to infer the variable type
        for ($g = 0, $n = count($this->groupTypeMap); $g < $n; $g++) {
            $val = $match[$g + 1] ?? null;
            if ($val !== null && $val !== '') {
                $type = $this->groupTypeMap[$g];
                if ($type === VariableType::IgnoreWord) {
                    foreach ($this->ignoreWords as $w) {
                        if ($w->word === $match[0]) {
                            if ($w->meta !== null) {
                                return new VariableInfo($match[0], $type, $w->meta);
                            }
                            break;
                        }
                    }
                }
                return new VariableInfo($match[0], $type);
            }
        }
        return new VariableInfo($match[0], VariableType::Symbol);
    }

    /**
     * @param VariableInfo[] $variables
     */
    private function evaluateICU(string $pattern, array $variables, string $locale): ?string
    {
        return $this->evaluateICUDetailed($pattern, $variables, $locale)[0];
    }

    /**
     * @param VariableInfo[] $variables
     * @return array{0:?string,1:?string} [output, error] — exactly one is non-null
     */
    private function evaluateICUDetailed(string $pattern, array $variables, string $locale): array
    {
        // Temporarily replace HTML tags so they don't confuse the ICU parser.
        $tagPlaceholders = [];
        $icuPattern = preg_replace_callback(
            '#</?[^>]+>#',
            function (array $m) use (&$tagPlaceholders): string {
                $placeholder = "\u{FFFD}" . count($tagPlaceholders) . "\u{FFFD}";
                $tagPlaceholders[] = [$placeholder, $m[0]];
                return $placeholder;
            },
            $pattern,
        );
        if (!is_string($icuPattern)) {
            return [null, 'failed to preprocess pattern'];
        }

        $args = [];
        foreach ($variables as $i => $vi) {
            // ICU plural/select rules need numeric values for proper evaluation
            $args[(string) $i] = $vi->type === VariableType::Number ? (float) $vi->value : $vi->value;
            if ($vi->meta !== null) {
                foreach ($vi->meta as $k => $v) {
                    $args[$i . '_' . $k] = $v;
                }
            }
        }

        $fmt = MessageFormatter::create($locale, $icuPattern);
        if ($fmt === null) {
            $error = intl_get_error_message();
            return [null, $error !== '' ? $error : 'invalid ICU pattern'];
        }
        $result = $fmt->format($args);
        if ($result === false) {
            return [null, $fmt->getErrorMessage()];
        }
        // ICU renders missing arguments as literal {N}/{N_key} placeholders and
        // still reports success — treat any survivor as an evaluation failure.
        // Checked before tag restoration so tag content cannot false-positive.
        if (preg_match_all('/\{\d+(?:_\w+)?\}/', $result, $m) > 0) {
            return [null, 'unfilled ICU argument(s): ' . implode(', ', array_unique($m[0]))];
        }
        foreach ($tagPlaceholders as [$ph, $orig]) {
            $result = str_replace($ph, $orig, $result);
        }
        return [$result, null];
    }

    private function sanitizeTags(string $html): string
    {
        $out = preg_replace_callback(
            '#</?(\w+)(\s[^>]*)?\s*>#',
            function (array $m): string {
                $tag = strtolower($m[1]);
                if (isset($this->allowedInlineTags[$tag])) {
                    return $m[0];
                }
                return str_replace(['<', '>'], ['&lt;', '&gt;'], $m[0]);
            },
            $html,
        );
        return is_string($out) ? $out : $html;
    }

    private function buildVariableRegex(): string
    {
        $groups = [];
        $this->groupTypeMap = [];

        // 1. IgnoreWords (longest first, alternation)
        if (count($this->ignoreWords) > 0) {
            $alts = [];
            foreach ($this->ignoreWords as $w) {
                $alts[] = preg_quote($w->word, '/');
            }
            $groups[] = '(' . implode('|', $alts) . ')';
            $this->groupTypeMap[] = VariableType::IgnoreWord;
        }

        // 2. URLs
        $groups[] = '(https?:\/\/[^\s<>]+)';
        $this->groupTypeMap[] = VariableType::Url;

        // 3. Emails
        $groups[] = '([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})';
        $this->groupTypeMap[] = VariableType::Email;

        // 4. Dates (MM/DD/YYYY, YYYY-MM-DD, DD.MM.YYYY)
        $groups[] = '(\d{1,4}[\/.\-]\d{1,2}[\/.\-]\d{1,4})';
        $this->groupTypeMap[] = VariableType::Date;

        // 5. Numbers (negative, decimals)
        $groups[] = '(-?\d+(?:\.\d+)?)';
        $this->groupTypeMap[] = VariableType::Number;

        // 6. Standalone symbols
        $groups[] = '([©®™$€£¥¢₹₽§¶†‡•°±¤%])';
        $this->groupTypeMap[] = VariableType::Symbol;

        return '/' . implode('|', $groups) . '/u';
    }

    /**
     * @param IgnoreWordEntry[] $entries
     * @return IgnoreWordEntry[]
     */
    private static function sortLongestFirst(array $entries): array
    {
        usort($entries, static fn(IgnoreWordEntry $a, IgnoreWordEntry $b): int => strlen($b->word) - strlen($a->word));
        return $entries;
    }

    /**
     * @return array<string,string>
     */
    private static function parseAttributes(string $attrString): array
    {
        if ($attrString === '') {
            return [];
        }
        $attrs = [];
        if (preg_match_all(
            '/(\w[\w-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|(\S+)))?/',
            $attrString,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $m) {
                if (!isset($m[1]) || $m[1] === '') {
                    continue;
                }
                $attrs[$m[1]] = $m[2] ?? $m[3] ?? $m[4] ?? '';
            }
        }
        return $attrs;
    }

    private static function detectCasePattern(string $masked): CasePattern
    {
        // Strip placeholders and HTML tags
        $textOnly = preg_replace('/\{\{\d+\}\}/', '', $masked) ?? $masked;
        $textOnly = preg_replace('/<[^>]*>/', '', $textOnly) ?? $textOnly;

        // Keep only Unicode letters
        $letters = preg_replace('/[^\p{L}]/u', '', $textOnly) ?? '';
        if ($letters === '') {
            return CasePattern::Lower;
        }

        $upper = mb_strtoupper($letters, 'UTF-8');
        $lower = mb_strtolower($letters, 'UTF-8');

        // Caseless scripts (CJK, Arabic) — no case distinction
        if ($upper === $lower) {
            return CasePattern::Lower;
        }

        if ($letters === $upper) {
            return CasePattern::Upper;
        }
        if ($letters === $lower) {
            return CasePattern::Lower;
        }
        return CasePattern::Mixed;
    }
}
