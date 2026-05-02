<?php

declare(strict_types=1);

namespace AutoHtmlI18n;

final class MaskResult
{
    /**
     * @param VariableInfo[] $variables
     * @param array<string,array<string,string>> $tagAttributes Map of tagKey ("a0") to attribute name/value pairs.
     */
    public function __construct(
        public readonly string $masked,
        public readonly array $variables,
        public readonly array $tagAttributes,
        public readonly CasePattern $casePattern,
        public readonly string $leadingWhitespace,
        public readonly string $trailingWhitespace,
    ) {
    }
}
