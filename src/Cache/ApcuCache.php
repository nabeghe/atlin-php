<?php declare(strict_types=1);

namespace Nabeghe\Atlin\Cache;

use Nabeghe\Atlin\Exception\CacheException;

/**
 * APCu in-memory cache driver.
 *
 * Best suited for single-server deployments requiring sub-millisecond latency.
 */
final class ApcuCache implements CacheInterface
{
    private string $prefix;

    public function __construct(string $prefix = 'atlin:')
    {
        $this->prefix = $prefix;
    }

    public function get(string $key): ?array
    {
        $value = apcu_fetch($this->prefix . $key, $success);

        return $success && is_array($value) ? $value : null;
    }

    public function set(string $key, array $data, int $ttl = 0): void
    {
        if (!apcu_store($this->prefix . $key, $data, $ttl)) {
            throw new CacheException("APCu failed to store key: {$key}");
        }
    }

    public function delete(string $key): void
    {
        apcu_delete($this->prefix . $key);
    }

    public function flush(): void
    {
        $info = apcu_cache_info(false);
        if (!isset($info['cache_list'])) {
            return;
        }

        foreach ($info['cache_list'] as $entry) {
            $entryKey = $entry['info'] ?? $entry['key'] ?? '';
            if (str_starts_with($entryKey, $this->prefix)) {
                apcu_delete($entryKey);
            }
        }
    }

    public function isAvailable(): bool
    {
        return function_exists('apcu_store') && ini_get('apc.enabled');
    }
}
