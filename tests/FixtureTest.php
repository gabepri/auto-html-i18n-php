<?php

declare(strict_types=1);

namespace AutoHtmlI18n\Tests;

use AutoHtmlI18n\CasePattern;
use AutoHtmlI18n\Masker;
use AutoHtmlI18n\TextDirection;
use AutoHtmlI18n\Unrendered;
use AutoHtmlI18n\VariableInfo;
use AutoHtmlI18n\VariableType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixtureTest extends TestCase
{
    private const DEFAULT_ALLOWED_TAGS = ['a', 'b', 'i', 'u', 'strong', 'em', 'span', 'small', 'mark', 'del'];

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $expected
     */
    #[DataProvider('fixtureProvider')]
    public function testFixture(string $name, string $input, array $config, array $expected): void
    {
        $masker = self::maskerFor($config);
        $result = $masker->mask($input);

        self::assertSame(self::str($expected, 'masked'), $result->masked, "masked mismatch for: $name");

        $expectedVars = self::objectList($expected, 'variables');
        $actualVars = array_map(static fn (VariableInfo $v): array => $v->toArray(), $result->variables);
        self::assertSame(self::normalizeVars($expectedVars), $actualVars, "variables mismatch for: $name");

        $expectedTagAttrs = self::stringMapMap($expected, 'tagAttributes');
        // PHP json_decode with assoc=true gives empty objects as []. The shared schema uses {} for empty.
        // Both decode to [] in PHP, which matches our internal map representation.
        self::assertEquals($expectedTagAttrs, $result->tagAttributes, "tagAttributes mismatch for: $name");

        $expectedCase = CasePattern::from(self::str($expected, 'casePattern', 'lower'));
        self::assertSame($expectedCase, $result->casePattern, "casePattern mismatch for: $name");

        self::assertSame(self::str($expected, 'leadingWhitespace'), $result->leadingWhitespace, "leadingWhitespace mismatch for: $name");
        self::assertSame(self::str($expected, 'trailingWhitespace'), $result->trailingWhitespace, "trailingWhitespace mismatch for: $name");
    }

    /**
     * @return iterable<string,array{0:string,1:string,2:array<string,mixed>,3:array<string,mixed>}>
     */
    public static function fixtureProvider(): iterable
    {
        foreach (self::cases('masker') as $name => $case) {
            yield $name => [
                $name,
                self::str($case, 'input'),
                self::map($case, 'config'),
                self::map($case, 'expected'),
            ];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $variables
     * @param array<string,array<string,string>> $tagAttributes
     * @param array<string,mixed> $config
     */
    #[DataProvider('unmaskFixtureProvider')]
    public function testUnmaskFixture(
        string $name,
        string $translated,
        array $variables,
        array $tagAttributes,
        ?string $locale,
        ?string $original,
        array $config,
        string $expected,
    ): void {
        $masker = self::maskerFor($config);

        self::assertSame(
            $expected,
            $masker->unmask($translated, self::toVariableInfos($variables), $tagAttributes, $locale, $original),
            "unmask mismatch for: $name",
        );
    }

    /**
     * @return iterable<string,array{0:string,1:string,2:array<int,array<string,mixed>>,3:array<string,array<string,string>>,4:?string,5:?string,6:array<string,mixed>,7:string}>
     */
    public static function unmaskFixtureProvider(): iterable
    {
        foreach (self::cases('unmask') as $name => $case) {
            yield $name => [
                $name,
                self::str($case, 'translated'),
                self::objectList($case, 'variables'),
                self::stringMapMap($case, 'tagAttributes'),
                self::nullableStr($case, 'locale'),
                self::nullableStr($case, 'original'),
                self::map($case, 'config'),
                self::str($case, 'expected'),
            ];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $variables
     * @param array<string,mixed> $config
     * @param array<string,mixed> $expected
     */
    #[DataProvider('icuValidateFixtureProvider')]
    public function testIcuValidateFixture(
        string $name,
        string $translated,
        array $variables,
        string $locale,
        array $config,
        array $expected,
    ): void {
        $masker = self::maskerFor($config);

        $result = $masker->validateIcu($translated, self::toVariableInfos($variables), $locale);

        self::assertSame(self::bool($expected, 'valid'), $result->valid, "valid mismatch for: $name");
        self::assertSame(self::str($expected, 'format'), $result->format->value, "format mismatch for: $name");
        if (array_key_exists('output', $expected)) {
            self::assertSame($expected['output'], $result->output, "output mismatch for: $name");
        }
        if (self::bool($expected, 'valid') === false) {
            // Error text is engine-specific; only its presence is part of the contract
            self::assertNotNull($result->error, "error expected for: $name");
        }
    }

    /**
     * @return iterable<string,array{0:string,1:string,2:array<int,array<string,mixed>>,3:string,4:array<string,mixed>,5:array<string,mixed>}>
     */
    public static function icuValidateFixtureProvider(): iterable
    {
        foreach (self::cases('icu-validate') as $name => $case) {
            yield $name => [
                $name,
                self::str($case, 'translated'),
                self::objectList($case, 'variables'),
                self::str($case, 'locale', 'en'),
                self::map($case, 'config'),
                self::map($case, 'expected'),
            ];
        }
    }

    #[DataProvider('directionFixtureProvider')]
    public function testDirectionFixture(string $name, string $locale, string $expected): void
    {
        self::assertSame($expected, TextDirection::forLocale($locale)->value, "direction mismatch for: $name");
    }

    /**
     * @return iterable<string,array{0:string,1:string,2:string}>
     */
    public static function directionFixtureProvider(): iterable
    {
        foreach (self::cases('direction') as $name => $case) {
            yield $name => [$name, self::str($case, 'locale'), self::str($case, 'expected')];
        }
    }

    #[DataProvider('unrenderedFixtureProvider')]
    public function testUnrenderedFixture(string $name, string $masked, bool $expected): void
    {
        self::assertSame($expected, Unrendered::isUnrenderedValue($masked), "unrendered mismatch for: $name");
    }

    /**
     * @return iterable<string,array{0:string,1:string,2:bool}>
     */
    public static function unrenderedFixtureProvider(): iterable
    {
        foreach (self::cases('unrendered') as $name => $case) {
            yield $name => [$name, self::str($case, 'masked'), self::bool($case, 'expected')];
        }
    }

    /**
     * Every case in `fixtures/<group>/*.json`, keyed by "<file>: <case name>".
     *
     * @return iterable<string,array<string,mixed>>
     */
    private static function cases(string $group): iterable
    {
        $dir = realpath(__DIR__ . '/../../../fixtures/' . $group);
        if ($dir === false) {
            throw new \RuntimeException("fixtures/$group directory not found");
        }
        $files = glob($dir . '/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $base = basename($file);
            foreach (self::decodeCases($file) as $case) {
                yield $base . ': ' . self::str($case, 'name', '(unnamed)') => $case;
            }
        }
    }

    /**
     * Decode one fixture file into its list of case objects.
     *
     * `json_decode()` returns `mixed`, so this is the single place the shared JSON crosses into
     * typed territory: a malformed fixture fails loudly here rather than surfacing as a confusing
     * type error deep inside a test.
     *
     * @return list<array<string,mixed>>
     */
    private static function decodeCases(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException("fixture file unreadable: $file");
        }
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException("fixture file is not a list of cases: $file");
        }
        $cases = [];
        foreach ($decoded as $case) {
            if (!is_array($case)) {
                throw new \RuntimeException("fixture case is not an object: $file");
            }
            $normalized = [];
            foreach ($case as $key => $value) {
                $normalized[(string) $key] = $value;
            }
            $cases[] = $normalized;
        }
        return $cases;
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function maskerFor(array $config): Masker
    {
        return new Masker(self::ignoreWords($config), self::stringList($config, 'allowedInlineTags', self::DEFAULT_ALLOWED_TAGS));
    }

    /**
     * @param array<int,array<string,mixed>> $variables
     * @return list<VariableInfo>
     */
    private static function toVariableInfos(array $variables): array
    {
        return array_map(
            static fn (array $v): VariableInfo => new VariableInfo(
                self::str($v, 'value'),
                VariableType::from(self::str($v, 'type')),
                self::meta($v),
            ),
            array_values($variables),
        );
    }

    /**
     * Normalize JSON-decoded variables so the test compares apples to apples.
     * The fixture format omits `meta` when absent; VariableInfo::toArray() does the same.
     *
     * @param array<int,array<string,mixed>> $vars
     * @return array<int,array{value:string,type:string,meta?:array<string,string>}>
     */
    private static function normalizeVars(array $vars): array
    {
        $out = [];
        foreach ($vars as $v) {
            $entry = ['value' => self::str($v, 'value'), 'type' => self::str($v, 'type')];
            $meta = self::meta($v);
            if ($meta !== null && $meta !== []) {
                $entry['meta'] = $meta;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function str(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function nullableStr(array $data, string $key): ?string
    {
        return isset($data[$key]) ? self::str($data, $key) : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function bool(array $data, string $key): bool
    {
        return (bool) ($data[$key] ?? false);
    }

    /**
     * A nested JSON object, string-keyed.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function map(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    /**
     * A JSON array of objects (e.g. the `variables` list).
     *
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private static function objectList(array $data, string $key): array
    {
        $out = [];
        foreach (self::map($data, $key) as $item) {
            if (is_array($item)) {
                $entry = [];
                foreach ($item as $k => $v) {
                    $entry[(string) $k] = $v;
                }
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * A JSON array of strings, or the given default when absent.
     *
     * @param array<string,mixed> $data
     * @param list<string> $default
     * @return list<string>
     */
    private static function stringList(array $data, string $key, array $default = []): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return $default;
        }
        $out = [];
        foreach ($data[$key] as $item) {
            if (is_scalar($item)) {
                $out[] = (string) $item;
            }
        }
        return $out;
    }

    /**
     * A JSON object of objects of strings (e.g. `tagAttributes`).
     *
     * @param array<string,mixed> $data
     * @return array<string,array<string,string>>
     */
    private static function stringMapMap(array $data, string $key): array
    {
        $out = [];
        foreach (self::map($data, $key) as $outerKey => $inner) {
            $out[$outerKey] = self::stringMap($inner);
        }
        return $out;
    }

    /**
     * The optional `meta` object on a fixture variable.
     *
     * @param array<string,mixed> $data
     * @return array<string,string>|null
     */
    private static function meta(array $data): ?array
    {
        return isset($data['meta']) ? self::stringMap($data['meta']) : null;
    }

    /**
     * @return array<string,string>
     */
    private static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_scalar($v)) {
                $out[(string) $k] = (string) $v;
            }
        }
        return $out;
    }

    /**
     * The `ignoreWords` config entry: plain strings, or `{ "word": ..., "meta": {...} }` objects.
     *
     * @param array<string,mixed> $config
     * @return list<string|array{word:string,meta?:array<string,string>}>
     */
    private static function ignoreWords(array $config): array
    {
        $out = [];
        foreach (self::map($config, 'ignoreWords') as $entry) {
            if (is_scalar($entry)) {
                $out[] = (string) $entry;
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            $word = ['word' => self::stringMap($entry)['word'] ?? ''];
            $meta = self::stringMap($entry['meta'] ?? null);
            $out[] = $meta === [] ? $word : $word + ['meta' => $meta];
        }
        return $out;
    }
}
