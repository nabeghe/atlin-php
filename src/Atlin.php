<?php declare(strict_types=1);

namespace Nabeghe\Atlin;

use Nabeghe\Atlin\Config\AtlinConfig;

/**
 * Atlin — A lightweight key-value format parser/serializer.
 *
 * ## Format rules
 *
 * - A line starting with `@` defines a key; everything after `@` on that line is the key name.
 * - The content on the *next* line(s) that do NOT start with `@` is the value for that key.
 * - A blank line before a `@`-key line is ignored (acts as visual separator).
 * - Text appearing before any key is assigned to the empty-string key `""`.
 * - An `@` with nothing after it produces the empty-string key `""`.
 * - Duplicate keys cause their values to be **concatenated** (joined with a newline).
 * - To use a literal `@` at the start of a line, escape it: `\@`.
 *
 * ## Performance
 *
 * - The parser is a single O(n) pass with no regex.
 * - Caching (APCu / Redis / File) is opt-in via {@see AtlinConfig}.
 *
 * @package Nabeghe\Atlin
 */
class Atlin
{
    private AtlinConfig $config;

    public function __construct(?AtlinConfig $config = null)
    {
        $this->config = $config ?? new AtlinConfig();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Parse an Atlin-formatted string into an associative array.
     *
     * @param  string  $content  Raw Atlin text.
     * @param  string  $cacheKey  Optional logical key used for cache look-up.
     *                         When empty, caching is skipped.
     *
     * @return array<string, string>
     */
    public function parse(string $content, string $cacheKey = ''): array
    {
        if ($cacheKey !== '') {
            $resolvedKey = $this->resolveKey($cacheKey, $content);
            $cached = $this->config->cache->get($resolvedKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $result = $this->doParse($content);

        if ($cacheKey !== '') {
            $this->config->cache->set($resolvedKey, $result, $this->config->cacheTtl);
        }

        return $result;
    }

    /**
     * Parse an Atlin-formatted file into an associative array.
     *
     * @param  string  $filePath  Absolute or relative path to the `.atlin` file.
     * @param  bool  $useCache  Whether to cache using the file path as key.
     *
     * @return array<string, string>
     * @throws \RuntimeException When the file cannot be read.
     */
    public function parseFile(string $filePath, bool $useCache = true): array
    {
        if (!is_readable($filePath)) {
            throw new \RuntimeException("Atlin: cannot read file '$filePath'.");
        }

        $content = file_get_contents($filePath);
        $cacheKey = $useCache ? $filePath : '';

        return $this->parse((string) $content, $cacheKey);
    }

    /**
     * Serialize an associative array into Atlin format.
     *
     * @param  array<string, string>  $data  The key-value pairs to serialize.
     * @param  bool  $blankLines  Insert a blank line between entries.
     *
     * @return string
     */
    public function serialize(array $data, bool $blankLines = true): string
    {
        if (empty($data)) {
            return '';
        }

        $parts = [];

        foreach ($data as $key => $value) {
            $parts[] = '@'.$key."\n".$value;
        }

        $separator = $blankLines ? "\n\n" : "\n";

        return implode($separator, $parts);
    }

    /**
     * Remove a cached entry by its logical key.
     *
     * @param  string  $cacheKey  The logical cache key.
     * @param  string  $content  Supply the same content when contentHashKey is enabled.
     */
    public function invalidate(string $cacheKey, string $content = ''): void
    {
        $this->config->cache->delete($this->resolveKey($cacheKey, $content));
    }

    /**
     * Flush all Atlin cache entries managed by the current driver.
     */
    public function flushCache(): void
    {
        $this->config->cache->flush();
    }

    /**
     * Return the cache driver currently in use.
     */
    public function getCache(): \Nabeghe\Atlin\Cache\CacheInterface
    {
        return $this->config->cache;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Core parser  (single O(n) line scan, zero regex)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function doParse(string $content): array
    {
        /** @var array<string, string> $result */
        $result = [];

        $lines     = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $lineCount = count($lines);

        $currentKey    = null;
        $valueBuffer   = '';
        $hasValue      = false;
        $pendingBlanks = 0; // count of consecutive blank lines not yet committed

        $flush = static function () use (&$result, &$currentKey, &$valueBuffer, &$hasValue, &$pendingBlanks): void {
            if ($currentKey === null && !$hasValue) {
                $pendingBlanks = 0;
                return;
            }

            $key   = $currentKey ?? '';
            $value = $valueBuffer;

            if (isset($result[$key])) {
                if ($result[$key] !== '' && $value !== '') {
                    $result[$key] .= "\n" . $value;
                } else {
                    $result[$key] .= $value;
                }
            } else {
                $result[$key] = $value;
            }

            $valueBuffer   = '';
            $hasValue      = false;
            $pendingBlanks = 0;
        };

        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];

            // ── Key line ──────────────────────────────────────────────────────────
            if (isset($line[0]) && $line[0] === '@') {
                // Discard exactly ONE pending blank (the separator),
                // emit the rest into the value buffer BEFORE flushing.
                $blanksToEmit = max(0, $pendingBlanks - 1);
                if ($blanksToEmit > 0 && ($hasValue || $currentKey !== null)) {
                    $valueBuffer  .= str_repeat("\n", $blanksToEmit);
                }
                $pendingBlanks = 0;

                $flush();
                $currentKey = substr($line, 1);
                continue;
            }

            // ── Escaped @ at start ────────────────────────────────────────────────
            if (isset($line[0], $line[1]) && $line[0] === '\\' && $line[1] === '@') {
                $line = substr($line, 1);
            }

            // ── Blank line ────────────────────────────────────────────────────────
            if (trim($line) === '') {
                // Don't commit yet — wait to see what comes next
                if ($hasValue || $currentKey !== null) {
                    $pendingBlanks++;
                }
                // If we haven't started any key/value yet, discard blank lines
                continue;
            }

            // ── Value line ────────────────────────────────────────────────────────
            // Commit all pending blanks first (next line is NOT a key, so they're all value)
            if ($pendingBlanks > 0) {
                $valueBuffer  .= str_repeat("\n", $pendingBlanks);
                $pendingBlanks = 0;
            }

            if (!$hasValue) {
                $valueBuffer = $line;
                $hasValue    = true;
            } else {
                $valueBuffer .= "\n" . $line;
            }
        }

        // At EOF: discard all trailing blank lines (treated as file ending whitespace)
        $pendingBlanks = 0;

        $flush();

        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Cache key resolution
    // ──────────────────────────────────────────────────────────────────────────

    private function resolveKey(string $key, string $content): string
    {
        if ($this->config->contentHashKey && $content !== '') {
            return $key.':'.hash('xxh32', $content);
        }

        return $key;
    }
}
