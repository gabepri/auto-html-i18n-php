<?php

declare(strict_types=1);

namespace AutoDomI18n;

final class Resolver
{
    /**
     * Resolve a translation entry to a concrete string given an optional scope.
     * - String entry: returned as-is.
     * - Scope-keyed array: returns the value for the given scope, or null if absent.
     *
     * @param string|array<string,string> $entry
     */
    public static function resolve(string|array $entry, ?string $scope): ?string
    {
        if (is_string($entry)) {
            return $entry;
        }
        if ($scope !== null && isset($entry[$scope])) {
            return $entry[$scope];
        }
        return null;
    }
}
