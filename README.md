# auto-html-i18n (PHP)

Server-side automatic translation for PHP-rendered HTML. Walks markup, masks dynamic values (numbers, dates, names, URLs, inline tags) into stable cache keys, looks them up in a translation cache, and falls back to a user-supplied backend for cache misses. Returns translated HTML in a single synchronous pass — no async bookkeeping, no client-side JS required.

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
| `Click <a href="/x">here</a>` | `Click <a0>here</a0>` | `[]` (tag attrs preserved separately) |
| `HELLO WORLD` | `hello world` | (case is restored on output) |

This means **you only translate the abstract sentence shape once** — `You have {{0}} apples` works for any number — and your translations don't have to know about specific values.

## API

### `new I18nTranslator(array $config)`

| Key | Type | Default | Notes |
|---|---|---|---|
| `locale` | `string` | — | **Required.** Initial active locale. |
| `onMissingTranslation` | `callable` | — | **Required.** `(TranslationItem[] $items, string $locale): array<string, string\|array>` |
| `allowedInlineTags` | `string[]` | `['a','b','i','u','strong','em','span','small','mark','del']` | Tags that may appear inside translatable text and round-trip through translation. |
| `translatableAttributes` | `string[]` | `['title','placeholder','alt','aria-label']` | Attributes to translate on every element. |
| `ignoreSelectors` | `string[]` | `['script','style','code']` | Skip subtrees matching these. Tag names or `[attr]` form. |
| `ignoreWords` | `array` | `[]` | Words preserved verbatim during masking. Plain strings or `['word' => 'X', 'meta' => [...]]`. |
| `initialCache` | `array<string,string\|array>` | `[]` | Pre-populated translations for the active locale. |
| `originalAttribute` | `string` | `'data-i18n-original'` | Reserved (currently unused server-side). |
| `keyAttribute` | `string` | `'data-i18n-key'` | When set on an element, overrides the masked key for that element's content. |
| `ignoreAttribute` | `string` | `'data-i18n-ignore'` | Setting this attribute on an element skips its subtree. |
| `scopeAttribute` | `string` | `'data-i18n-scope'` | Names a scope that scope-keyed translations resolve against. |
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

## What's deliberately different from the JS package

| | JS (browser) | PHP (server) |
|---|---|---|
| Walks | Live DOM via `MutationObserver` | One HTML string per call |
| `onMissingTranslation` | Async, possibly many calls (debounced/batched) | Sync, exactly one call per `translateHtml()` |
| Pending state | Yes — text is replaced when async callback resolves | No — translation always completes before serialization |
| Re-render on locale change | Re-walks the DOM in place | Caller re-runs `translateHtml()` on cached source |
| Output-side bookkeeping attributes | `data-i18n-original` / `data-i18n-pending` written to elements | Not used (single-pass output) |

Both ports honor the same `data-i18n-*` input attributes (`-key`, `-scope`, `-ignore`).

## Development

```bash
composer install
vendor/bin/phpunit
```

Tests include a fixture-driven suite (`tests/FixtureTest.php`) that runs the same Masker assertions as the JS package against the shared corpus in [`fixtures/masker/`](../../fixtures/masker/). Adding a fixture there exercises both ports immediately.

## License

MIT
