# Atlin

[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-blue)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

A lightweight, high-performance **key-value format** parser and serializer for PHP.  
Designed for translation files — but works great for any structured text data.

---

## The Atlin Format

Atlin is a plain-text key-value format with minimal syntax and maximum readability.

### Rules

| Rule | Behaviour |
|------|-----------|
| `@key` at line start | Declares a key. Everything after `@` on that line is the key name. |
| Line(s) after key (not starting with `@`) | The value for that key. Multi-line values are supported. |
| Exactly **one** blank line before a `@` line | Ignored — used as a visual separator between entries. |
| **More than one** blank line before a `@` line | All except the last are part of the value. |
| Blank line(s) at end of file | Always ignored (treated as file-ending whitespace). |
| Text before any key | Assigned to the empty-string key `""`. |
| `@` alone on a line | Produces the empty-string key `""`. |
| Duplicate keys | Values are **concatenated** with a newline (`\n`). |
| `\@` at line start | Escaped — treated as a literal `@` in the value. |
| `\#` at line start | Escaped — treated as a literal `#` in the value (when comments enabled). |
| `@` not at line start | Always part of the value (e.g. email addresses). |
| `@` with a leading space (e.g. ` @foo`) | Part of the value, NOT a key. |

### Blank Line Behaviour (Important)

The blank line rule is precise:

```
@key
value

@next
```
→ Single blank line = separator → `key = "value"` ✅

```
@key
value


@next
```
→ Two blank lines = first is value content, second is separator → `key = "value\n"` ✅

```
@key
value



@next
```
→ Three blank lines = first two are value content, last is separator → `key = "value\n\n"` ✅

```
@key
value

```
→ Trailing blank line at EOF = ignored → `key = "value"` ✅

### Full Example

```atlin
@app.name
My Awesome App

@app.description
This is a multi-line
description of the app.

@button.save
Save

@button.cancel
Cancel

@email.signature
Best regards,
The Team

\@this is not a key, it is a value without a preceding key declaration

@note
Send feedback to support@example.com
```

Parsed result:

```php
[
    'app.name'        => 'My Awesome App',
    'app.description' => "This is a multi-line\ndescription of the app.",
    'button.save'     => 'Save',
    'button.cancel'   => 'Cancel',
    'email.signature' => "Best regards,\nThe Team",
    ''                => '@this is not a key, it is a value without a preceding key declaration',
    'note'            => 'Send feedback to support@example.com',
]
```

### Duplicate Keys (Concatenation)

```atlin
@terms
By using this app you agree to our terms.

@terms
Updated: January 2026.
```

Result:

```php
[
    'terms' => "By using this app you agree to our terms.\nUpdated: January 2026.",
]
```

### Why Atlin?

- **Human-readable** — no quotes, no special delimiters, no indentation rules.
- **Multi-line values** — just keep writing on the next lines.
- **Precise blank line control** — one blank = separator, more = content.
- **Zero noise** — trailing blank lines at EOF are always ignored.
- **Unicode-safe** — key names can be any Unicode string (Persian, Arabic, CJK, etc.).
- **Fast** — single O(n) pass parser with zero regex.

---

## Installation

```bash
composer require nabeghe/atlin
```

Requires **PHP ≥ 7.4**.

---

## Quick Start

```php
use Nabeghe\Atlin\Atlin;

$atlin = new Atlin();

// Parse a string
$data = $atlin->parse("@hello\nHello, World!");
echo $data['hello']; // Hello, World!

// Parse a file
$data = $atlin->parseFile(__DIR__ . '/lang/en.atlin');

// Serialize an array back to Atlin format
$text = $atlin->serialize([
    'greeting' => 'Hi!',
    'farewell' => 'Bye!',
]);
```

---

## Caching

Atlin supports three cache backends. Caching is **disabled by default** — enable
it by passing an `AtlinConfig` with a cache driver.

### File Cache (recommended for most apps)

Stores the parsed PHP array as a plain `<?php return [...];` file, which benefits
from OPcache for extremely fast cold reads — no deserialization needed.

```php
use Nabeghe\Atlin\Atlin;
use Nabeghe\Atlin\Cache\FileCache;
use Nabeghe\Atlin\Config\AtlinConfig;

$cache  = new FileCache('/path/to/cache/dir');
$config = new AtlinConfig(
    cache:          $cache,
    cacheTtl:       0,    // 0 = store indefinitely
    contentHashKey: true  // auto-invalidate when source content changes
);
$atlin = new Atlin($config);

// The file path is used as the cache key automatically
$data = $atlin->parseFile('/path/to/lang/en.atlin');
```

### APCu Cache (single-server, sub-millisecond)

```php
use Nabeghe\Atlin\Cache\ApcuCache;

$cache  = new ApcuCache('myapp:'); // optional key prefix
$config = new AtlinConfig($cache, 3600); // TTL: 1 hour
$atlin  = new Atlin($config);
```

### Redis Cache (distributed / multi-server)

```php
use Nabeghe\Atlin\Cache\RedisCache;

// phpredis extension
$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);

$cache  = new RedisCache($redis, 'myapp:');
$config = new AtlinConfig($cache, 3600);
$atlin  = new Atlin($config);
```

Works with both the **phpredis** extension and **Predis** client.

### Cache Management

```php
// Invalidate a single entry
$atlin->invalidate('/path/to/lang/en.atlin');

// Flush all entries managed by the current driver
$atlin->flushCache();

// Check if the driver is available
$atlin->getCache()->isAvailable(); // bool
```

### Cache Driver Comparison

| Driver | Speed | Persistence | Multi-server | Requirements |
|--------|-------|-------------|--------------|--------------|
| `NullCache` | — | — | — | none (default) |
| `FileCache` | ⚡⚡ (+ OPcache) | ✅ | ❌ | writable directory |
| `ApcuCache` | ⚡⚡⚡ | ❌ | ❌ | `ext-apcu` |
| `RedisCache` | ⚡⚡ | ✅ | ✅ | `ext-redis` or Predis |

---

## API Reference

### `Atlin::parse(string $content, string $cacheKey = ''): array`

Parse an Atlin-formatted string into an associative array.  
Optionally provide a `$cacheKey` to enable caching for this content.

### `Atlin::parseFile(string $filePath, bool $useCache = true): array`

Read and parse an Atlin file.  
Uses the file path as the cache key when `$useCache` is `true`.

### `Atlin::serialize(array $data, bool $blankLines = true): string`

Convert an associative array back to Atlin format.  
Set `$blankLines` to `false` to omit blank separators between entries.

### `Atlin::invalidate(string $cacheKey, string $content = ''): void`

Manually invalidate a cache entry by its logical key.

### `Atlin::flushCache(): void`

Remove all cache entries managed by the current driver.

### `Atlin::getCache(): CacheInterface`

Access the underlying cache driver instance.

---

## Project Structure

```
nabeghe/atlin/
├── src/
│   ├── Atlin.php                   ← Main parser/serializer class
│   ├── Cache/
│   │   ├── CacheInterface.php      ← Contract for all cache drivers
│   │   ├── NullCache.php           ← No-op driver (caching disabled)
│   │   ├── ApcuCache.php           ← APCu in-memory driver
│   │   ├── RedisCache.php          ← Redis driver (phpredis / Predis)
│   │   └── FileCache.php           ← PHP opcode file driver
│   ├── Config/
│   │   └── AtlinConfig.php         ← Immutable configuration value-object
│   └── Exception/
│       ├── AtlinException.php      ← Base library exception
│       └── CacheException.php      ← Cache operation exception
├── tests/
│   ├── AtlinTest.php               ← Full parser/serializer test suite
│   └── Cache/
│       ├── FileCacheTest.php       ← FileCache unit tests
│       └── ApcuCacheTest.php       ← ApcuCache unit tests (auto-skipped if unavailable)
├── composer.json
├── phpunit.xml
└── README.md
```

---

## Running Tests

```bash
# Install dependencies
composer install

# Run all tests
composer test

# Run with coverage report (requires Xdebug or PCOV)
composer test-coverage
```

**PHPUnit 10 or 11** is supported.  
APCu tests are automatically skipped when the extension is not loaded.

To enable APCu in CLI (for local testing), add to your `php.ini`:

```ini
apc.enable_cli=1
```

---

## 📖 License

Licensed under the MIT license, see [LICENSE.md](LICENSE.md) for details.
