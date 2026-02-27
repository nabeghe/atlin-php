<?php

declare(strict_types=1);

namespace Nabeghe\Atlin\Tests\Cache;

use Nabeghe\Atlin\Cache\FileCache;
use Nabeghe\Atlin\Exception\CacheException;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $dir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/atlin_fc_' . uniqid('', true);
        $this->cache = new FileCache($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testIsAvailable(): void
    {
        $this->assertTrue($this->cache->isAvailable());
    }

    public function testSetAndGet(): void
    {
        $this->cache->set('mykey', ['foo' => 'bar']);
        $this->assertSame(['foo' => 'bar'], $this->cache->get('mykey'));
    }

    public function testGetReturnsNullOnMiss(): void
    {
        $this->assertNull($this->cache->get('nonexistent'));
    }

    public function testDelete(): void
    {
        $this->cache->set('del_key', ['x' => 'y']);
        $this->cache->delete('del_key');
        $this->assertNull($this->cache->get('del_key'));
    }

    public function testFlush(): void
    {
        $this->cache->set('k1', ['a' => '1']);
        $this->cache->set('k2', ['b' => '2']);
        $this->cache->flush();
        $this->assertNull($this->cache->get('k1'));
        $this->assertNull($this->cache->get('k2'));
    }

    public function testTtlExpiry(): void
    {
        $this->cache->set('ttl_key', ['data' => 'value'], 1);
        $this->assertNotNull($this->cache->get('ttl_key'));
        sleep(2);
        $this->assertNull($this->cache->get('ttl_key'));
    }

    public function testNonWritableDirectoryThrows(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Non-writable directory test is not reliable on Windows.');
        }

        if (posix_getuid() === 0) {
            $this->markTestSkipped('Running as root — cannot test non-writable directory.');
        }

        // Create a real directory and make it non-writable
        $lockedDir = sys_get_temp_dir() . '/atlin_locked_' . uniqid('', true);
        mkdir($lockedDir, 0555); // read+execute only, no write

        try {
            $this->expectException(CacheException::class);
            new FileCache($lockedDir . '/subdir');
        } finally {
            // Restore permissions so tearDown can clean up
            chmod($lockedDir, 0755);
            @rmdir($lockedDir);
        }
    }

    public function testCacheFileIsValidPhp(): void
    {
        $this->cache->set('php_check', ['hello' => 'world']);
        $files = glob($this->dir . '/*.php') ?: [];
        $this->assertNotEmpty($files);
        $content = file_get_contents($files[0]);
        $this->assertStringStartsWith('<?php', $content);
        $this->assertStringContainsString('return ', $content);
    }
}
