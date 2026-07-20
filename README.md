# auto-html-i18n (PHP)

Server-side automatic translation for PHP-rendered HTML. Walks markup, masks dynamic values (numbers, dates, names, URLs, emails, symbols, inline tags) into stable cache keys, looks them up in a translation cache, and falls back to a user-supplied backend for cache misses. Returns translated HTML in a single synchronous pass — no async bookkeeping, no client-side JS required.

This is the PHP sibling of [`packages/js`](../js). Both packages share the same masking algorithm and a corpus of cross-port test fixtures so behavior stays identical.

## Install

```bash
composer require gabepri/auto-html-i18n
```

Requires PHP 8.1+, the `intl` and `mbstring` extensions.

## Quickstart

```php
use AutoHtmlI18n\I18nTranslator;
use AutoHtmlI18n\TranslationItem;

$i18n = new I18nTranslator([
    'locale' => 'es',
    'onMissingTranslation' => function (array $items, string $locale): array {
        // $items: TranslationItem[] — one entry per unique missing key
        // Return: ['masked_key' => 'translation', ...]
        // Either look these up in your DB or hand them off to a translation service.
        return MyTranslationService::fetch($items, $locale);
    },
]);

$html = $i18n->translateHtml('<p>You have 5 apples</p>');
// → '<p>Tienes 5 manzanas</p>'
```

The `onMissingTranslation` callback is invoked **once per `translateHtml()` call** with the full deduplicated batch of unknown keys. Translations are cached in memory for the lifetime of the `I18nTranslator` instance — pass `initialCache` to seed it from your persistent store.

## How masking works

Before lookup, each translatable string is normalized into a stable key with dynamic values replaced by `{{N}}` placeholders:

| Input | Cache key | Variables |
|---|---|---|
| `You have 5 apples` | `You have {{0}} apples` | `[{value: "5", type: "number"}]` |
| `Visit https://acme.com` | `Visit {{0}}` | `[{value: "https://acme.com", type: "url"}]` |
| `Email mary@acme.com` | `Email {{0}}` | `[{value: "mary@acme.com", type: "email"}]` |
| `Click <a href="/x">here</a>` | `Click <a0>here</a0>` | `[]` (tag attrs preserved separately) |
| `See <svg id="x9">…</svg> now` | `See {{0}}…{{1}} now` | `[{value: "<svg id=\"x9\">", type: "markup"}, …]` |
| `HELLO WORLD` | `hello world` | (case is restored on output) |

This means **you only translate the abstract sentence shape once** — `You have {{0}} apples` works for any number — and your translations don't have to know about specific values.

Recognized value types, matched in priority order, are `ignoreWord`, `url`, `email`, `date`, `number`, and `symbol` — identical to the JS port. URLs and emails are matched ahead of dates and numbers so they mask as a single unit rather than fragmenting. Strings containing no Unicode letter are skipped outright rather than masked. None of these ever reach your translation backend, so **`ignoreAttribute` is unnecessary for them**; reserve it for letter-bearing content the masker cannot recognize by shape, such as personal names or user-authored prose.

Tags outside `allowedInlineTags` (an `<input>`, `<svg>`, `<div>`, …) are captured as opaque `markup` variables instead of being left in the key — so their volatile attributes (random ids, gradient refs) never destabilize the cache key, and the original markup is restored verbatim on output. Nested same-name inline tags (`<span><span>…</span></span>`) are matched opener-to-closer by a stack, so their indices never cross.

An element's markup is aggregated into a single translatable unit only when its **entire descendant subtree** is inline-allowed **and it has direct interleaved text of its own**. A pure container of inline elements with no direct text — a nav menu, link list, or button group — is treated as structural, so each child is translated on its own and keeps its own cache key rather than collapsing the whole container into one key.

## API

### `new I18nTranslator(array $config)`

| Key | Type | Default | Notes |
|---|---|---|---|
| `locale` | `string` | — | **Required.** Initial active locale. |
| `onMissingTranslation` | `callable` | — | **Required.** `(TranslationItem[] $items, string $locale): array<string, string\|array>` |
| `allowedInlineTags` | `string[]` | `['a','b','i','u','strong','em','span','small','mark','del','sup','sub']` | Tags that may appear inside translatable text and round-trip through translation. |
| `translatableAttributes` | `string[]` | `['title','placeholder','alt','aria-label']` | Attributes to translate on every element. |
| `ignoreSelectors` | `string[]` | `['script','style','code']` | Skip subtrees matching these. Tag names or `[attr]` form. |
| `ignoreWords` | `array` | `[]` | Words preserved verbatim during masking. Plain strings or `['word' => 'X', 'meta' => [...]]`. |
| `initialCache` | `array<string,string\|array>` | `[]` | Pre-populated translations for the active locale. |
| `originalAttribute` | `string` | `'data-i18n-original'` | Reserved (currently unused server-side). |
| `pendingAttribute` | `string` | `'data-i18n-pending'` | Reserved (currently unused server-side). |
| `keyAttribute` | `string` | `'data-i18n-key'` | When set on an element, overrides the masked key for that element's content. |
| `ignoreAttribute` | `string` | `'data-i18n-ignore'` | Setting this attribute on an element skips its subtree. |
| `scopeAttribute` | `string` | `'data-i18n-scope'` | Names a scope that scope-keyed translations resolve against. |
| `skipUnrenderedValues` | `bool` | `true` | Never report strings a component painted before its data arrived (`"Level undefined"`, `"about NaN minutes"`, `"results for ''"`). See [Half-rendered values](#half-rendered-values). |
| `isUnrenderedValue` | `callable` | built-in | `(string $masked, string $original): bool`. Overrides the half-rendered detection. Ignored when `skipUnrenderedValues` is `false`. |
| `debug` | `bool` | `false` | When true, each `TranslationItem` includes a `debug` payload (DOM context). |

### Methods

```php
// Walk an HTML fragment, translate everything translatable, return HTML.
$i18n->translateHtml(string $html): string

// Imperative: look up a pre-masked key and substitute positional variables.
// (No masking, no ICU — just {{N}} substitution.)
$i18n->translate(string $text, array $variables = [], ?string $scope = null): string

// Dry-run validation: check a translation string will render, the same way
// translateHtml() consumes it. See "Validating translations" below.
$i18n->validateIcu(string $translated, array $variables = [], ?string $locale = null): IcuValidationResult
$i18n->validateTranslation(string $original, string $translated, ?string $locale = null): IcuValidationResult

// Locale management
$i18n->getLocale(): string
$i18n->setLocale(string $locale): void

// Writing direction of a locale ('ltr'|'rtl' backed enum), defaulting to the
// instance locale. See "RTL support" below.
$i18n->getDirection(?string $locale = null): TextDirection

// Cache management
$i18n->setCache(string $locale, array $entries): void
$i18n->getCache(?string $locale = null): array
$i18n->clearCache(?string $locale = null): void
$i18n->getTranslation(string $key, ?string $locale = null): string|array|null

// IgnoreWords management
$i18n->getIgnoreWords(): array
$i18n->addIgnoreWords(array $words): void
$i18n->removeIgnoreWords(array $words): void
$i18n->setIgnoreWords(array $words): void
```

## Half-rendered values

Markup rendered before its data arrived carries the *stringified absence* of the value — `Level undefined`, `Read time about NaN minutes`, `No results found for ''`. Those tokens are not numbers or dates, so masking bakes the broken value into the key as literal text (`"Level undefined"`, not `"Level {{0}}"`).

By default such text is **rendered untranslated but never reported** — `onMissingTranslation` never sees it, and nothing about the skip is cached, so the correct mask reports normally on the next render. A translation you *have* cached for such a key still applies; the gate is on reporting, not on lookup.

A mask counts as half-rendered when it contains `undefined`, `null` or `NaN` as a standalone word, or an empty quote pair (`''`, `""`, `«»`, `‘’`, `“”`). Word boundaries are respected, so `Annulled contracts` reports as usual.

```php
// Turn the gate off entirely.
$i18n = new I18nTranslator([..., 'skipUnrenderedValues' => false]);

// Or keep it and supply your own predicate — here copy may legitimately say
// "null", but "undefined" is always a rendering artifact.
$i18n = new I18nTranslator([
    ...,
    'isUnrenderedValue' => fn(string $masked, string $original): bool
        => preg_match('/\bundefined\b/', $masked) === 1,
]);
```

## Scoped translations

A translation entry can be either a plain string or a scope-keyed array. The walker resolves the active scope by walking up the DOM tree from the element containing the text and reading the `data-i18n-scope` attribute.

```php
// Backend response
[
    'greeting' => [
        'formal' => 'Bienvenido',
        'casual' => '¡Hola!',
    ],
]

// HTML
'<div data-i18n-scope="formal"><p data-i18n-key="greeting">Hello</p></div>'
// → '<div data-i18n-scope="formal"><p data-i18n-key="greeting">Bienvenido</p></div>'
```

## ICU MessageFormat

Translations may use [ICU MessageFormat](https://unicode-org.github.io/icu/userguide/format_parse/messages/) for plurals, gender, and conditional structure. PHP's built-in `MessageFormatter` (the `intl` extension) handles evaluation.

```php
// Backend returns
['You have {{0}} apples' => '{0, plural, one {Tienes # manzana} other {Tienes # manzanas}}']

// "You have 1 apples" → "Tienes 1 manzana"
// "You have 5 apples" → "Tienes 5 manzanas"
```

When an ignoreWord carries metadata (e.g. `['word' => 'Mary', 'meta' => ['gender' => 'female']]`), that metadata is exposed to ICU as `{N_key}` arguments — letting translations branch on `{0_gender, select, female {...} other {...}}`.

If an ICU pattern fails to parse or evaluate — including when it references a variable index or metadata key that doesn't exist (PHP's `MessageFormatter` would otherwise render a literal `{1}` and report success) — the affected text falls back to its original untranslated source. Neither the raw pattern nor unfilled placeholders are ever rendered into the output HTML.

### Validating translations

To catch bad patterns before they reach your cache or backend responses, dry-run them the same way `translateHtml()` consumes them. Both methods return an `IcuValidationResult` (`->valid`, `->format`, `->error`, `->output`):

```php
use AutoHtmlI18n\VariableInfo;
use AutoHtmlI18n\VariableType;

$r = $i18n->validateIcu(
    '{0, plural, one {# oveja} other {# ovejas}}',
    [new VariableInfo('5', VariableType::Number)],
);
// $r->valid === true, $r->format === TranslationFormat::Icu, $r->output === '5 ovejas'

// Or derive the variables from a source string, using the instance's ignoreWords config:
$r = $i18n->validateTranslation('John has 3 cats', '{{0}} tiene {{1}} gatos');
```

`format` reports how the string will be consumed — `icu` (single-brace `{0}`), `simple` (double-brace `{{0}}` substitution), or `plain` — check it matches your intent. Invalid results carry an engine-specific `error` (malformed pattern, unfilled arguments, out-of-range `{{N}}` index).

## RTL support

Right-to-left locales (Hebrew, Arabic, Persian, Urdu, …) work out of the box.

**Document direction.** The server owns the markup, so set `dir`/`lang` yourself using `getDirection()`. Direction is resolved from the locale tag: the language subtag decides (`he`, `ar`, `fa`, `ur`, …) and an explicit script subtag overrides it (`az-Arab` → rtl, `ar-Latn` → ltr). `TextDirection::forLocale()` is also available statically.

```php
$i18n = new I18nTranslator(['locale' => 'he-IL', /* ... */]);

echo '<html dir="' . $i18n->getDirection()->value . '" lang="' . $i18n->getLocale() . '">';
// <html dir="rtl" lang="he-IL">
```

**Bidi isolation of variables.** When the target locale is RTL, values re-injected into `{{N}}` placeholders — numbers, dates, URLs, emails, and ignoreWords (typically Latin brand names) — are wrapped in Unicode *first-strong isolate* characters (U+2068…U+2069). Without this, the bidi algorithm can visually scramble LTR fragments inside an RTL sentence (e.g. a date like `12/31/2024` rendering reversed). The isolates are invisible, carried into the output HTML, and stripped again during masking, so cache keys stay stable.

Isolation applies to simple `{{N}}` substitution (and `validateIcu`/`validateTranslation` output). ICU patterns are rendered by the ICU engine as-is — add directional marks inside the pattern if a specific argument needs them.

## What's deliberately different from the JS package

| | JS (browser) | PHP (server) |
|---|---|---|
| Walks | Live DOM via `MutationObserver` | One HTML string per call |
| `onMissingTranslation` | Async, possibly many calls (debounced/batched) | Sync, exactly one call per `translateHtml()` |
| Pending state | Yes — text is replaced when async callback resolves | No — translation always completes before serialization |
| Re-render on locale change | Re-walks the DOM in place | Caller re-runs `translateHtml()` on cached source |
| ICU locale handling | `Intl` strictly validates BCP 47 tags; ill-formed locales degrade stepwise (`es-41` → `es` → `und`) so the translation still renders. `und` resolves to the runtime's default locale. | ICU accepts any locale id natively (`es-41`, `es_419`, even garbage) and resolves through its own fallback chain, ending at ICU's **root** locale. Both ports always render; only the plural rules chosen for a *wholly* invalid locale can differ (runtime default vs. root). |
| Output-side bookkeeping attributes | `data-i18n-original` / `data-i18n-pending` written to elements | Not used (single-pass output) |
| Document direction (RTL) | Optional `manageDirection` config keeps `dir`/`lang` on the live document in sync | Caller embeds `getDirection()` into the markup it renders |

Both ports honor the same `data-i18n-*` input attributes (`-key`, `-scope`, `-ignore`).

## Development

```bash
composer install
composer test           # PHPUnit
composer test:coverage  # PHPUnit with a text coverage report
composer analyse        # PHPStan static analysis (src/ + tests/)
composer lint           # PHP-CS-Fixer style check (no writes)
composer lint:fix       # PHP-CS-Fixer, applying fixes
```

`composer test:coverage` sets `XDEBUG_MODE=coverage` for you. Running `vendor/bin/phpunit --coverage-text`
directly reports nothing under Xdebug 3, which defaults to `develop` mode.

Tests include a fixture-driven suite (`tests/FixtureTest.php`) that runs the same Masker assertions as the JS package against the shared corpus in [`fixtures/masker/`](../../fixtures/masker/). Adding a fixture there exercises both ports immediately.

### Static analysis level

[`phpstan.neon.dist`](phpstan.neon.dist) pins **level 9** — the highest level that passes with zero errors
and zero suppressions. There is deliberately no baseline: a baseline would let a higher number hide the
same findings.

Four things make level 9 reachable:

- **`treatPhpDocTypesAsCertain: false`.** Several guards in `src/` defend against values that a *vendor's
  PHPDoc* claims are impossible but that the runtime can still produce — `Masterminds\HTML5::loadHTMLFragment()`
  returning a non-fragment (`HtmlWalker`, `I18nTranslator`) and `MessageFormatter::create()` returning `null`
  (`Masker`). An annotation is not a runtime guarantee, so the guards stay and PHPStan is told not to trust
  the annotation.
- **Removing guards that were dead by this code's own logic** — the `DOMElement` loop condition and trailing
  `return null` in `HtmlWalker::findAggregationTarget()`, the `ownerDocument === null` check in
  `HtmlWalker::replaceTextNode()` (mutually exclusive with the `parentNode === null` check above it), and the
  empty-capture-group skip in `Masker::parseAttributes()` (group 1 of that regex always matches ≥1 char).
- **A declared shape for the constructor config.** `I18nTranslator` used to take `array<string,mixed>`, so
  every value read out of it was `mixed` and level 9 rejected all ten downstream constructor calls. The keys
  are now spelled out as a PHPStan array shape — which is worth more than the level number: consumers who
  run PHPStan on their own code get key-name typo detection and per-key value types at the call site. Two
  aliases are exported and importable with
  `@phpstan-import-type I18nTranslatorConfig from \AutoHtmlI18n\I18nTranslator`:

  | Alias | Meaning |
  | --- | --- |
  | `I18nTranslatorConfig` | What the constructor takes: `locale` and `onMissingTranslation` required, everything else optional. |
  | `I18nTranslatorOptions` | The same keys with *all* of them optional — for a partial config you merge into a base one. |

  `onMissingTranslation` is deliberately typed as a bare `callable` rather than with its full signature. It is
  the one config value whose *return* crosses back from consumer code, and annotating that return would let
  the analyser treat it as proven and delete the runtime guards that exist precisely because an annotation is
  not a runtime guarantee. The signature it is called with is documented in the class docblock and in the
  [config table](#new-i18ntranslatorarray-config) above.
- **A typed decoder in `tests/FixtureTest.php`.** `json_decode()` returns `mixed`, which accounted for 34 of
  the 44 level-9 findings. The shared JSON now crosses into typed territory in exactly one place
  (`decodeCases()`), behind a handful of small accessors (`str()`, `map()`, `objectList()`, …) rather than
  scattered casts — a malformed fixture fails loudly at the decode site instead of surfacing as a confusing
  type error deep inside a test.

Level 10 (`checkImplicitMixed`) is not enabled. It reports 53 findings, all but one of them the same thing:
the `array $items` parameter of the ~25 `onMissingTranslation` test closures has an implicit `mixed` element
type, so every `$item->masked` in the tests is an error. Because the config shape types the callback as a
bare `callable` (see above), PHPStan cannot infer the element type and each closure would need its own
`@param list<TranslationItem>` docblock. The remaining finding is a genuine variance nit in
`TranslationItem::toArray()`. Nothing in `src/` blocks it structurally.

### Code style

[`.php-cs-fixer.dist.php`](.php-cs-fixer.dist.php) applies `@PSR12` plus `declare_strict_types` (every
file in `src/` and `tests/` already declares it). The codebase satisfies it — `composer lint` reports
0 of 27 files — and CI enforces it. Run `composer lint:fix` to normalize anything new.

## License

MIT
