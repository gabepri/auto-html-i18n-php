<?php

declare(strict_types=1);

namespace AutoHtmlI18n\Tests;

use AutoHtmlI18n\CasePattern;
use AutoHtmlI18n\Masker;
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
        $ignoreWords = $config['ignoreWords'] ?? [];
        $allowedTags = $config['allowedInlineTags'] ?? self::DEFAULT_ALLOWED_TAGS;

        $masker = new Masker($ignoreWords, $allowedTags);
        $result = $masker->mask($input);

        self::assertSame($expected['masked'], $result->masked, "masked mismatch for: $name");

        $expectedVars = $expected['variables'] ?? [];
        $actualVars = array_map(static fn($v) => $v->toArray(), $result->variables);
        self::assertSame(self::normalizeVars($expectedVars), $actualVars, "variables mismatch for: $name");

        $expectedTagAttrs = $expected['tagAttributes'] ?? [];
        // PHP json_decode with assoc=true gives empty objects as []. The shared schema uses {} for empty.
        // Both decode to [] in PHP, which matches our internal map representation.
        self::assertEquals($expectedTagAttrs, $result->tagAttributes, "tagAttributes mismatch for: $name");

        $expectedCase = CasePattern::from($expected['casePattern'] ?? 'lower');
        self::assertSame($expectedCase, $result->casePattern, "casePattern mismatch for: $name");

        self::assertSame($expected['leadingWhitespace'] ?? '', $result->leadingWhitespace, "leadingWhitespace mismatch for: $name");
        self::assertSame($expected['trailingWhitespace'] ?? '', $result->trailingWhitespace, "trailingWhitespace mismatch for: $name");
    }

    /**
     * @return iterable<string,array{0:string,1:string,2:array<string,mixed>,3:array<string,mixed>}>
     */
    public static function fixtureProvider(): iterable
    {
        $dir = realpath(__DIR__ . '/../../../fixtures/masker');
        if ($dir === false) {
            throw new \RuntimeException('fixtures/masker directory not found');
        }
        $files = glob($dir . '/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $base = basename($file);
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }
            /** @var array<int,array<string,mixed>> $cases */
            $cases = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            foreach ($cases as $case) {
                $name = $base . ': ' . ($case['name'] ?? '(unnamed)');
                yield $name => [
                    $name,
                    (string) ($case['input'] ?? ''),
                    (array) ($case['config'] ?? []),
                    (array) ($case['expected'] ?? []),
                ];
            }
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
        $ignoreWords = $config['ignoreWords'] ?? [];
        $allowedTags = $config['allowedInlineTags'] ?? self::DEFAULT_ALLOWED_TAGS;

        $masker = new Masker($ignoreWords, $allowedTags);
        $vars = array_map(
            static fn(array $v): VariableInfo => new VariableInfo(
                (string) $v['value'],
                VariableType::from((string) $v['type']),
                $v['meta'] ?? null,
            ),
            $variables,
        );

        self::assertSame(
            $expected,
            $masker->unmask($translated, $vars, $tagAttributes, $locale, $original),
            "unmask mismatch for: $name",
        );
    }

    /**
     * @return iterable<string,array{0:string,1:string,2:array<int,array<string,mixed>>,3:array<string,array<string,string>>,4:?string,5:?string,6:array<string,mixed>,7:string}>
     */
    public static function unmaskFixtureProvider(): iterable
    {
        $dir = realpath(__DIR__ . '/../../../fixtures/unmask');
        if ($dir === false) {
            throw new \RuntimeException('fixtures/unmask directory not found');
        }
        $files = glob($dir . '/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $base = basename($file);
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }
            /** @var array<int,array<string,mixed>> $cases */
            $cases = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            foreach ($cases as $case) {
                $name = $base . ': ' . ($case['name'] ?? '(unnamed)');
                yield $name => [
                    $name,
                    (string) ($case['translated'] ?? ''),
                    (array) ($case['variables'] ?? []),
                    (array) ($case['tagAttributes'] ?? []),
                    isset($case['locale']) ? (string) $case['locale'] : null,
                    isset($case['original']) ? (string) $case['original'] : null,
                    (array) ($case['config'] ?? []),
                    (string) ($case['expected'] ?? ''),
                ];
            }
        }
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
            $entry = ['value' => (string) $v['value'], 'type' => (string) $v['type']];
            if (isset($v['meta']) && is_array($v['meta']) && $v['meta'] !== []) {
                /** @var array<string,string> $meta */
                $meta = $v['meta'];
                $entry['meta'] = $meta;
            }
            $out[] = $entry;
        }
        return $out;
    }
}
