<?php

declare(strict_types=1);

namespace AutoDomI18n;

final class VariableInfo
{
    /**
     * @param array<string,string>|null $meta
     */
    public function __construct(
        public readonly string $value,
        public readonly VariableType $type,
        public readonly ?array $meta = null,
    ) {
    }

    /**
     * @return array{value:string,type:string,meta?:array<string,string>}
     */
    public function toArray(): array
    {
        $out = ['value' => $this->value, 'type' => $this->type->value];
        if ($this->meta !== null) {
            $out['meta'] = $this->meta;
        }
        return $out;
    }
}
