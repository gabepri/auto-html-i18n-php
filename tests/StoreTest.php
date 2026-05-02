<?php

declare(strict_types=1);

namespace AutoHtmlI18n\Tests;

use AutoHtmlI18n\EntryStatus;
use AutoHtmlI18n\Store;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    public function testGetReturnsNullForMissing(): void
    {
        $s = new Store();
        self::assertNull($s->get('en', 'missing'));
    }

    public function testSetThenGet(): void
    {
        $s = new Store();
        $s->set('en', 'hello', 'world');
        $entry = $s->get('en', 'hello');
        self::assertNotNull($entry);
        self::assertSame('world', $entry->value);
        self::assertSame(EntryStatus::Resolved, $entry->status);
    }

    public function testSetWithScopedValue(): void
    {
        $s = new Store();
        $s->set('en', 'greeting', ['formal' => 'Greetings', 'casual' => 'Hey']);
        $entry = $s->get('en', 'greeting');
        self::assertNotNull($entry);
        self::assertSame(['formal' => 'Greetings', 'casual' => 'Hey'], $entry->value);
    }

    public function testIsResolved(): void
    {
        $s = new Store();
        $s->set('en', 'k', 'v');
        self::assertTrue($s->isResolved('en', 'k'));
        self::assertFalse($s->isResolved('en', 'missing'));
        self::assertFalse($s->isResolved('fr', 'k'));
    }

    public function testMarkReportedOnExisting(): void
    {
        $s = new Store();
        $s->markReported('en', 'k');
        $entry = $s->get('en', 'k');
        self::assertNotNull($entry);
        self::assertSame(EntryStatus::Reported, $entry->status);
        self::assertNull($entry->value);
    }

    public function testMarkReportedDoesNotDowngradeResolved(): void
    {
        $s = new Store();
        $s->set('en', 'k', 'v');
        $s->markReported('en', 'k');
        self::assertTrue($s->isResolved('en', 'k'));
    }

    public function testGetCacheReturnsOnlyResolved(): void
    {
        $s = new Store();
        $s->set('en', 'a', 'A');
        $s->markReported('en', 'b');
        self::assertSame(['a' => 'A'], $s->getCache('en'));
    }

    public function testGetCacheForUnknownLocale(): void
    {
        $s = new Store();
        self::assertSame([], $s->getCache('xx'));
    }

    public function testClearCacheAll(): void
    {
        $s = new Store();
        $s->set('en', 'k', 'v');
        $s->set('fr', 'k', 'v');
        $s->clearCache();
        self::assertSame([], $s->getCache('en'));
        self::assertSame([], $s->getCache('fr'));
    }

    public function testClearCacheLocale(): void
    {
        $s = new Store();
        $s->set('en', 'k', 'v');
        $s->set('fr', 'k', 'v');
        $s->clearCache('en');
        self::assertSame([], $s->getCache('en'));
        self::assertSame(['k' => 'v'], $s->getCache('fr'));
    }

    public function testLoadBulk(): void
    {
        $s = new Store();
        $s->loadBulk('en', ['a' => 'A', 'b' => 'B']);
        self::assertSame(['a' => 'A', 'b' => 'B'], $s->getCache('en'));
    }
}
