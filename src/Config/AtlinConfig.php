<?php

declare(strict_types=1);

namespace Nabeghe\Atlin\Config;

use Nabeghe\Atlin\Cache\CacheInterface;
use Nabeghe\Atlin\Cache\NullCache;

/**
 * Immutable value-object that controls Atlin parser behaviour.
 */
final class AtlinConfig
{
    /** Cache driver (NullCache = disabled). */
    public CacheInterface $cache;

    /**
     * TTL in seconds for cache entries.
     * 0 means store indefinitely (until explicit flush).
     */
    public int $cacheTtl;

    /**
     * When true, a unique hash of the source content is appended to the
     * cache key so that stale entries are automatically invalidated when
     * the source file changes.
     */
    public bool $contentHashKey;

    /**
     * One or more single characters that mark the start of a key line.
     * Defaults to ['@']. Each character in this array is treated as a
     * key marker — a line starting with any of them declares a key.
     *
     * The escape mechanism applies to all markers equally:
     * a backslash before a marker character at line start escapes it.
     *
     * Example: ['@', '#', '!']
     *
     * @var string[]
     */
    public array $markers;

    /**
     * @param  string[]  $markers
     */
    public function __construct(
        ?CacheInterface $cache = null,
        int $cacheTtl = 0,
        bool $contentHashKey = true,
        array $markers = ['@']
    ) {
        $this->cache = $cache ?? new NullCache();
        $this->cacheTtl = $cacheTtl;
        $this->contentHashKey = $contentHashKey;
        $this->markers = $markers ?: ['@'];
    }
}
