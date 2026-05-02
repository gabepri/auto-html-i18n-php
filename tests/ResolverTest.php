<?php

declare(strict_types=1);

namespace AutoDomI18n\Tests;

use AutoDomI18n\Resolver;
use PHPUnit\Framework\TestCase;

final class ResolverTest extends TestCase
{
    public function testStringEntryReturnedAsIs(): void
    {
        self::assertSame('hello', Resolver::resolve('hello', null));
        self::assertSame('hello', Resolver::resolve('hello', 'any-scope'));
    }

    public function testScopedEntryWithMatchingScope(): void
    {
        $entry = ['formal' => 'Greetings', 'casual' => 'Hey'];
        self::assertSame('Greetings', Resolver::resolve($entry, 'formal'));
        self::assertSame('Hey', Resolver::resolve($entry, 'casual'));
    }

    public function testScopedEntryWithMissingScopeReturnsNull(): void
    {
        $entry = ['formal' => 'Greetings'];
        self::assertNull(Resolver::resolve($entry, 'casual'));
    }

    public function testScopedEntryWithNullScopeReturnsNull(): void
    {
        $entry = ['formal' => 'Greetings'];
        self::assertNull(Resolver::resolve($entry, null));
    }
}
