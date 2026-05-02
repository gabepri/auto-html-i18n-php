<?php

declare(strict_types=1);

namespace AutoHtmlI18n;

final class IgnoreWordEntry
{
    /**
     * @param array<string,string>|null $meta
     */
    public function __construct(
        public readonly string $word,
        public readonly ?array $meta = null,
    ) {
    }

    /**
     * Accepts a plain string or an associative array shaped like
     * ['word' => '...', 'meta' => [...]].
     *
     * @param string|array{word:string,meta?:array<string,string>}|self $input
     */
    public static function from(string|array|self $input): self
    {
        if ($input instanceof self) {
            return $input;
        }
        if (is_string($input)) {
            return new self($input);
        }
        $word = $input['word'] ?? '';
        if (!is_string($word) || $word === '') {
            return new self('');
        }
        $meta = $input['meta'] ?? null;
        if ($meta !== null && !is_array($meta)) {
            $meta = null;
        }
        return new self($word, $meta);
    }

    /**
     * @param array<string|array{word:string,meta?:array<string,string>}|self> $entries
     * @return self[]
     */
    public static function normalize(array $entries): array
    {
        $out = [];
        foreach ($entries as $e) {
            $entry = self::from($e);
            if ($entry->word !== '') {
                $out[] = $entry;
            }
        }
        return $out;
    }
}
