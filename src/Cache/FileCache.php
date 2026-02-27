<?php declare(strict_types=1);

namespace Nabeghe\Atlin\Cache;

use Nabeghe\Atlin\Exception\CacheException;

/**
 * File-based PHP opcode cache driver.
 *
 * Serializes the parsed array as a plain PHP `return` statement so the file
 * can be loaded with `include` (benefiting from OPcache) instead of
 * JSON/igbinary deserialization — making cold reads extremely fast.
 */
final class FileCache implements CacheInterface
{
    private string $directory;
    private string $prefix;

    /**
     * @param string $directory Absolute path to the cache directory.
     * @param string $prefix    Optional filename prefix.
     *
     * @throws CacheException When the directory cannot be created or written to.
     */
    public function __construct(string $directory, string $prefix = 'atlin_')
    {
        $this->prefix    = $prefix;
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);

        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
                throw new CacheException("Cache directory could not be created: {$this->directory}");
            }
        }

        if (!is_writable($this->directory)) {
            throw new CacheException("Cache directory is not writable: {$this->directory}");
        }
    }

    public function get(string $key): ?array
    {
        $file = $this->filePath($key);

        if (!is_file($file)) {
            return null;
        }

        // Check expiry stored in a companion .meta file
        $meta = $this->metaPath($key);
        if (is_file($meta)) {
            $expiry = (int) file_get_contents($meta);
            if ($expiry > 0 && time() > $expiry) {
                $this->deleteFiles($file, $meta);
                return null;
            }
        }

        $data = include $file;

        return is_array($data) ? $data : null;
    }

    public function set(string $key, array $data, int $ttl = 0): void
    {
        $file    = $this->filePath($key);
        $meta    = $this->metaPath($key);
        $content = '<?php return ' . var_export($data, true) . ";\n";

        if (file_put_contents($file, $content, LOCK_EX) === false) {
            throw new CacheException("FileCache failed to write: {$file}");
        }

        if ($ttl > 0) {
            file_put_contents($meta, (string)(time() + $ttl), LOCK_EX);
        } elseif (is_file($meta)) {
            unlink($meta);
        }
    }

    public function delete(string $key): void
    {
        $this->deleteFiles($this->filePath($key), $this->metaPath($key));
    }

    public function flush(): void
    {
        $files = glob($this->directory . DIRECTORY_SEPARATOR . $this->prefix . '*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function isAvailable(): bool
    {
        return is_dir($this->directory) && is_writable($this->directory);
    }

    private function filePath(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->prefix . md5($key) . '.php';
    }

    private function metaPath(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->prefix . md5($key) . '.meta';
    }

    private function deleteFiles(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
