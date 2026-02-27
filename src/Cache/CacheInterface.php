<?php declare(strict_types=1);

namespace Nabeghe\Atlin\Cache;

/**
 * Contract for all Atlin cache drivers.
 */
interface CacheInterface
{
    /**
     * Retrieve a cached value.
     *
     * @param string $key Cache key.
     * @return array<string,string>|null Null when not found.
     */
    public function get(string $key): ?array;

    /**
     * Store a value in the cache.
     *
     * @param string               $key  Cache key.
     * @param array<string,string> $data The parsed translation array.
     * @param int                  $ttl  Time-to-live in seconds (0 = forever).
     */
    public function set(string $key, array $data, int $ttl = 0): void;

    /**
     * Remove a single cache entry.
     *
     * @param string $key Cache key.
     */
    public function delete(string $key): void;

    /**
     * Remove all entries managed by this driver (optionally scoped by prefix).
     */
    public function flush(): void;

    /**
     * Check whether the cache driver is available in the current environment.
     */
    public function isAvailable(): bool;
}
