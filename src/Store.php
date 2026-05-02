<?php

declare(strict_types=1);

namespace AutoHtmlI18n;

final class Store
{
    /** @var array<string,array<string,StoreEntry>> */
    private array $data = [];

    public function get(string $locale, string $key): ?StoreEntry
    {
        return $this->data[$locale][$key] ?? null;
    }

    /**
     * @param string|array<string,string> $value
     */
    public function set(string $locale, string $key, string|array $value): void
    {
        $this->data[$locale][$key] = new StoreEntry($value, EntryStatus::Resolved);
    }

    public function markReported(string $locale, string $key): void
    {
        $existing = $this->data[$locale][$key] ?? null;
        if ($existing === null) {
            $this->data[$locale][$key] = new StoreEntry(null, EntryStatus::Reported);
        } elseif ($existing->status !== EntryStatus::Resolved) {
            $existing->status = EntryStatus::Reported;
        }
    }

    public function isResolved(string $locale, string $key): bool
    {
        return ($this->data[$locale][$key] ?? null)?->status === EntryStatus::Resolved;
    }

    public function has(string $locale, string $key): bool
    {
        return isset($this->data[$locale][$key]);
    }

    /**
     * @return array<string,string|array<string,string>>
     */
    public function getCache(string $locale): array
    {
        $out = [];
        foreach ($this->data[$locale] ?? [] as $key => $entry) {
            if ($entry->status === EntryStatus::Resolved && $entry->value !== null) {
                $out[$key] = $entry->value;
            }
        }
        return $out;
    }

    public function clearCache(?string $locale = null): void
    {
        if ($locale === null) {
            $this->data = [];
        } else {
            unset($this->data[$locale]);
        }
    }

    /**
     * @param array<string,string|array<string,string>> $data
     */
    public function loadBulk(string $locale, array $data): void
    {
        foreach ($data as $key => $value) {
            $this->set($locale, $key, $value);
        }
    }
}
