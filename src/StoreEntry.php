<?php

declare(strict_types=1);

namespace AutoDomI18n;

final class StoreEntry
{
    public function __construct(
        /** @var string|array<string,string>|null */
        public string|array|null $value,
        public EntryStatus $status,
    ) {
    }
}
