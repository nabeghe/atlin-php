<?php

declare(strict_types=1);

namespace Nabeghe\Atlin\Tests\Cache;

use Nabeghe\Atlin\Cache\ApcuCache;
use PHPUnit\Framework\TestCase;

/**
 * Tests are skipped automatically when APCu is unavailable.
 */
final class ApcuCacheTest extends TestCase
{
    private ApcuCache $cache;

    protected function setUp(): void
    {
        if (!function_exists('apcu_store') || !ini_get('apc.enabled')) {
            $this->markTestSkipped('APCu is not available or not enabled.');
        }

        $this->cache = new ApcuCache('test_atlin:');
        $this->cache->flush();
    }

    public function testIsAvailable(): void
    {
        $this->assertTrue($this->cache->isAvailable());
    }

    public function testSetAndGet(): void
    {
        $this->cache->set('akey', ['hello' => 'world']);
        $this->assertSame(['hello' => 'world'], $this->cache->get('akey'));
    }

    public function testGetReturnsNullOnMiss(): void
    {
        $this->assertNull($this->cache->get('no_such_key_xyz'));
    }

    public function testDelete(): void
    {
        $this->cache->set('del', ['v' => '1']);
        $this->cache->delete('del');
        $this->assertNull($this->cache->get('del'));
    }

    public function testFlushRemovesOnlyPrefixedEntries(): void
    {
        $this->cache->set('k1', ['a' => '1']);
        apcu_store('other_app:key', 'other_data');

        $this->cache->flush();

        $this->assertNull($this->cache->get('k1'));
        $this->assertSame('other_data', apcu_fetch('other_app:key'));
        apcu_delete('other_app:key');
    }
}
