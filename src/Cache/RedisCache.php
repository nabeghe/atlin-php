<?php declare(strict_types=1);

namespace Nabeghe\Atlin\Cache;

use Nabeghe\Atlin\Exception\CacheException;

/**
 * Redis cache driver.
 *
 * Supports both the phpredis extension (\Redis) and the Predis client.
 * Suitable for distributed / multi-server environments.
 */
final class RedisCache implements CacheInterface
{
    /** @var \Redis|object */
    private object $client;

    private string $prefix;

    /**
     * @param \Redis|object $client A connected phpredis \Redis instance or a Predis client.
     * @param string        $prefix Key prefix to namespace entries.
     */
    public function __construct(object $client, string $prefix = 'atlin:')
    {
        $this->client = $client;
        $this->prefix = $prefix;
    }

    public function get(string $key): ?array
    {
        $raw = $this->client->get($this->prefix . $key);

        if ($raw === false || $raw === null) {
            return null;
        }

        $data = unserialize((string) $raw, ['allowed_classes' => false]);

        return is_array($data) ? $data : null;
    }

    public function set(string $key, array $data, int $ttl = 0): void
    {
        $serialized = serialize($data);
        $fullKey    = $this->prefix . $key;

        $result = $ttl > 0
            ? $this->client->setex($fullKey, $ttl, $serialized)
            : $this->client->set($fullKey, $serialized);

        if ($result === false) {
            throw new CacheException("Redis failed to store key: {$key}");
        }
    }

    public function delete(string $key): void
    {
        $this->client->del($this->prefix . $key);
    }

    public function flush(): void
    {
        $cursor  = null;
        $pattern = $this->prefix . '*';

        if (method_exists($this->client, 'scan')) {
            // phpredis — non-blocking SCAN
            $this->client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
            while (($keys = $this->client->scan($cursor, $pattern, 100)) !== false) {
                if (!empty($keys)) {
                    $this->client->del($keys);
                }
            }
        } else {
            // Predis
            $keys = $this->client->keys($pattern);
            if (!empty($keys)) {
                $this->client->del($keys);
            }
        }
    }

    public function isAvailable(): bool
    {
        return class_exists('\Redis') || class_exists('\Predis\Client');
    }
}
