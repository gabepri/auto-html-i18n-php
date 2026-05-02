<?php

declare(strict_types=1);

namespace AutoDomI18n;

final class TranslationItem
{
    /**
     * @param VariableInfo[] $variables
     * @param array<string,mixed>|null $debug
     */
    public function __construct(
        public readonly string $masked,
        public readonly string $original,
        public readonly array $variables,
        public readonly ?string $scope = null,
        public readonly ?array $debug = null,
    ) {
    }

    /**
     * @return array{masked:string,original:string,variables:array<int,array<string,mixed>>,scope?:string,debug?:array<string,mixed>}
     */
    public function toArray(): array
    {
        $out = [
            'masked' => $this->masked,
            'original' => $this->original,
            'variables' => array_map(static fn(VariableInfo $v) => $v->toArray(), $this->variables),
        ];
        if ($this->scope !== null) {
            $out['scope'] = $this->scope;
        }
        if ($this->debug !== null) {
            $out['debug'] = $this->debug;
        }
        return $out;
    }
}
