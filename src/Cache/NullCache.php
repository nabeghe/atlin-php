<?php declare(strict_types=1);

namespace Nabeghe\Atlin\Cache;

/**
 * No-op cache driver — disables caching entirely.
 */
final class NullCache implements CacheInterface
{
    public function get(string $key): ?array        { return null; }
    public function set(string $key, array $data, int $ttl = 0): void {}
    public function delete(string $key): void        {}
    public function flush(): void                    {}
    public function isAvailable(): bool              { return true; }
}
