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
     * When true, lines whose first character is `#` are treated as comments
     * and silently discarded during parsing. A line with a leading space before
     * `#` is NOT a comment — it is a regular value line.
     *
     * Default: true (comments enabled).
     */
    public bool $comments;

    public function __construct(
        ?CacheInterface $cache          = null,
        int             $cacheTtl       = 0,
        bool            $contentHashKey = true,
        bool            $comments       = true
    ) {
        $this->cache          = $cache ?? new NullCache();
        $this->cacheTtl       = $cacheTtl;
        $this->contentHashKey = $contentHashKey;
        $this->comments       = $comments;
    }
}
