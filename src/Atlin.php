<?php

declare(strict_types=1);

namespace Nabeghe\Atlin;

use Nabeghe\Atlin\Cache\CacheInterface;
use Nabeghe\Atlin\Config\AtlinConfig;

/**
 * Atlin — A lightweight key-value format parser/serializer.
 *
 * ## Format rules
 *
 * - A line starting with a marker character (default `@`) defines a key;
 *   everything after the marker on that line is the key name.
 * - The content on the next line(s) that do NOT start with a marker is the value.
 * - Exactly one blank line before a key line is ignored (visual separator).
 * - More than one blank line before a key line: all except the last become part of the value.
 * - Trailing blank lines at EOF are always ignored.
 * - Text appearing before any key is assigned to the empty-string key "".
 * - A marker with nothing after it produces the empty-string key "".
 * - Duplicate keys cause their values to be concatenated (joined with a newline).
 * - To use a literal marker character at the start of a line, escape it with `\`.
 *
 * ## Performance
 *
 * - The parser is a single O(n) pass with no regex.
 * - Markers are stored in a lookup map for O(1) per-character checks.
 * - Caching (APCu / Redis / File) is opt-in via {@see AtlinConfig}.
 *
 * @package Nabeghe\Atlin
 */
class Atlin
{
    private AtlinConfig $config;

    /**
     * Marker characters indexed as a map for O(1) lookup: ['@' => true, ...].
     *
     * @var array<string, true>
     */
    private array $markerMap;

    /** The primary marker used when serializing. */
    private string $primaryMarker;

    public function __construct(?AtlinConfig $config = null)
    {
        $this->config = $config ?? new AtlinConfig();

        // Build O(1) lookup map from the markers list
        $this->markerMap = [];
        foreach ($this->config->markers as $m) {
            if (is_string($m) && $m !== '') {
                $this->markerMap[$m] = true;
            }
        }

        // Fallback: if no valid markers were supplied, use '@'
        if (empty($this->markerMap)) {
            $this->markerMap = ['@' => true];
        }

        // Primary marker = first valid entry in the original list
        $this->primaryMarker = array_key_first($this->markerMap);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Parse an Atlin-formatted string into an associative array.
     *
     * @param  string  $content   Raw Atlin text.
     * @param  string  $cacheKey  Optional logical key used for cache look-up.
     *                            When empty, caching is skipped.
     *
     * @return array<string, string>
     */
    public function parse(string $content, string $cacheKey = ''): array
    {
        if ($cacheKey !== '') {
            $resolvedKey = $this->resolveKey($cacheKey, $content);
            $cached      = $this->config->cache->get($resolvedKey);
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
     * @param  string  $filePath  Absolute or relative path to the file.
     * @param  bool    $useCache  Whether to cache using the file path as key.
     *
     * @return array<string, string>
     * @throws \RuntimeException When the file cannot be read.
     */
    public function parseFile(string $filePath, bool $useCache = true): array
    {
        if (!is_readable($filePath)) {
            throw new \RuntimeException("Atlin: cannot read file '$filePath'.");
        }

        $content  = file_get_contents($filePath);
        $cacheKey = $useCache ? $filePath : '';

        return $this->parse((string) $content, $cacheKey);
    }

    /**
     * Serialize an associative array into Atlin format.
     *
     * Uses the primary marker (first entry in {@see AtlinConfig::$markers}) for output.
     *
     * @param  array<string, string>  $data        The key-value pairs to serialize.
     * @param  bool                   $blankLines  Insert a blank line between entries.
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
            $parts[] = $this->primaryMarker . $key . "\n" . $value;
        }

        $separator = $blankLines ? "\n\n" : "\n";

        return implode($separator, $parts);
    }

    /**
     * Remove a cached entry by its logical key.
     *
     * @param  string  $cacheKey  The logical cache key.
     * @param  string  $content   Supply the same content when contentHashKey is enabled.
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
    public function getCache(): CacheInterface
    {
        return $this->config->cache;
    }

    /**
     * Return the active marker map (for inspection or debugging).
     *
     * @return array<string, true>
     */
    public function getMarkerMap(): array
    {
        return $this->markerMap;
    }

    /**
     * Return the primary marker character used for serialization.
     */
    public function getPrimaryMarker(): string
    {
        return $this->primaryMarker;
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

        // Normalize all line endings to LF
        $lines     = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $lineCount = count($lines);

        $currentKey    = null; // null = no key seen yet → orphan text → key ""
        $valueBuffer   = '';
        $hasValue      = false;
        $pendingBlanks = 0;   // consecutive blank lines not yet committed

        /**
         * Flush the active key+buffer into $result.
         * Duplicate keys are concatenated with a single newline separator.
         */
        $flush = static function () use (
            &$result,
            &$currentKey,
            &$valueBuffer,
            &$hasValue,
            &$pendingBlanks
        ): void {
            if ($currentKey === null && !$hasValue) {
                $pendingBlanks = 0;
                return;
            }

            $key   = $currentKey ?? '';
            $value = $valueBuffer;

            if (isset($result[$key])) {
                // Concatenate — insert \n only when both sides are non-empty
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

            // ── Key line ──────────────────────────────────────────────────────
            if (isset($line[0]) && isset($this->markerMap[$line[0]])) {
                // Keep (pendingBlanks - 1) blank lines as value content;
                // discard exactly one — the visual separator.
                $blanksToEmit = max(0, $pendingBlanks - 1);
                if ($blanksToEmit > 0 && ($hasValue || $currentKey !== null)) {
                    $valueBuffer .= str_repeat("\n", $blanksToEmit);
                }
                $pendingBlanks = 0;

                $flush();
                $currentKey = substr($line, 1); // everything after the marker
                continue;
            }

            // ── Escaped marker at start (\@ or \# etc.) ───────────────────────
            if (isset($line[0], $line[1]) && $line[0] === '\\' && isset($this->markerMap[$line[1]])) {
                $line = substr($line, 1); // strip the leading backslash
            }

            // ── Blank line ────────────────────────────────────────────────────
            if (trim($line) === '') {
                if ($hasValue || $currentKey !== null) {
                    $pendingBlanks++;
                }
                // Blank lines before the very first key are discarded silently
                continue;
            }

            // ── Value line ────────────────────────────────────────────────────
            // Commit all pending blanks — the next token is a value, not a key
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

        // Trailing blank lines at EOF are always discarded
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
            return $key . ':' . hash('xxh32', $content);
        }

        return $key;
    }
}
