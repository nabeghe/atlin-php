<?php declare(strict_types=1);

namespace Nabeghe\Atlin\Tests;

use Nabeghe\Atlin\Atlin;
use Nabeghe\Atlin\Cache\FileCache;
use Nabeghe\Atlin\Config\AtlinConfig;
use PHPUnit\Framework\TestCase;

/**
 * Full test-suite for the Atlin parser and serializer.
 */
final class AtlinTest extends TestCase
{
    private Atlin $atlin;

    protected function setUp(): void
    {
        $this->atlin = new Atlin();
    }

    // ── Parsing — basic ───────────────────────────────────────────────────────

    public function testParseSimpleKeyValue(): void
    {
        $input = "@greeting\nHello, World!";
        $this->assertSame(['greeting' => 'Hello, World!'], $this->atlin->parse($input));
    }

    public function testParseMultipleKeys(): void
    {
        $input = "@name\nAlice\n@age\n30";
        $this->assertSame(['name' => 'Alice', 'age' => '30'], $this->atlin->parse($input));
    }

    public function testParseBlankLineSeparatorBetweenKeys(): void
    {
        $input  = "@key1\nvalue1\n\n@key2\nvalue2";
        $result = $this->atlin->parse($input);
        $this->assertSame('value1', $result['key1']);
        $this->assertSame('value2', $result['key2']);
    }

    public function testParseMultilineValue(): void
    {
        $input = "@poem\nRoses are red\nViolets are blue";
        $this->assertSame(
            ['poem' => "Roses are red\nViolets are blue"],
            $this->atlin->parse($input)
        );
    }

    public function testParseEmptyStringKeyWhenNoAtPrefix(): void
    {
        $input  = "orphan text\n@named\nvalue";
        $result = $this->atlin->parse($input);
        $this->assertSame('orphan text', $result['']);
        $this->assertSame('value', $result['named']);
    }

    public function testParseEmptyKeyWhenAtAloneOnLine(): void
    {
        $input  = "@\nvalue for empty key";
        $result = $this->atlin->parse($input);
        $this->assertSame('value for empty key', $result['']);
    }

    public function testParseEmptyContent(): void
    {
        $this->assertSame([], $this->atlin->parse(''));
    }

    // ── Duplicate keys ────────────────────────────────────────────────────────

    public function testParseDuplicateKeysConcatenated(): void
    {
        $input  = "@msg\nHello\n@msg\nWorld";
        $result = $this->atlin->parse($input);
        $this->assertSame("Hello\nWorld", $result['msg']);
    }

    public function testParseDuplicateKeysWithSeparator(): void
    {
        $input  = "@msg\nLine 1\n\n@msg\nLine 2";
        $result = $this->atlin->parse($input);
        $this->assertSame("Line 1\nLine 2", $result['msg']);
    }

    // ── Escaping ─────────────────────────────────────────────────────────────

    public function testEscapedAtSignIsNotTreatedAsKey(): void
    {
        $input  = "@key\n\\@not-a-key\nstill value";
        $result = $this->atlin->parse($input);
        $this->assertArrayNotHasKey('not-a-key', $result);
        $this->assertStringContainsString('@not-a-key', $result['key']);
    }

    public function testAtSignNotAtLineStartIsPartOfValue(): void
    {
        $input  = "@key\nSend email to user@example.com";
        $result = $this->atlin->parse($input);
        $this->assertSame('Send email to user@example.com', $result['key']);
    }

    public function testAtSignWithLeadingSpaceIsValue(): void
    {
        $input  = "@key\n @notakey";
        $result = $this->atlin->parse($input);

        // The full line " @notakey" (with leading space) is the value
        $this->assertSame(' @notakey', $result['key']);
        $this->assertArrayNotHasKey('notakey', $result);
    }

    // ── Edge cases ────────────────────────────────────────────────────────────

    public function testKeyWithoutValue(): void
    {
        $result = $this->atlin->parse("@key");
        $this->assertSame('', $result['key']);
    }

    public function testMultipleBlankLinesBetweenKeys(): void
    {
        // 3 blank lines: 2 become part of value, 1 is the separator
        $input  = "@first\nvalue1\n\n\n\n@second\nvalue2";
        $result = $this->atlin->parse($input);
        $this->assertSame("value1\n\n", $result['first']);
        $this->assertSame('value2', $result['second']);
    }

    public function testSingleBlankLineIsSeparator(): void
    {
        // Exactly 1 blank line → pure separator, not part of value
        $input  = "@first\nvalue1\n\n@second\nvalue2";
        $result = $this->atlin->parse($input);
        $this->assertSame('value1', $result['first']);
        $this->assertSame('value2', $result['second']);
    }

    public function testCRLFLineEndings(): void
    {
        $input  = "@key\r\nvalue\r\n@key2\r\nvalue2";
        $result = $this->atlin->parse($input);
        $this->assertSame('value', $result['key']);
        $this->assertSame('value2', $result['key2']);
    }

    public function testKeyNamePreservesUnicode(): void
    {
        $input  = "@سلام دنیا\nمقدار";
        $result = $this->atlin->parse($input);
        $this->assertSame('مقدار', $result['سلام دنیا']);
    }

    public function testOnlyBlankLines(): void
    {
        $this->assertSame([], $this->atlin->parse("\n\n\n"));
    }

    // ── Serialization ────────────────────────────────────────────────────────

    public function testSerializeAndParseRoundtrip(): void
    {
        $original = [
            'name'  => 'Alice',
            'email' => 'alice@example.com',
            'bio'   => "Line 1\nLine 2",
        ];

        $parsed = $this->atlin->parse($this->atlin->serialize($original));

        $this->assertSame($original['name'],  $parsed['name']);
        $this->assertSame($original['email'], $parsed['email']);
        $this->assertSame($original['bio'],   $parsed['bio']);
    }

    public function testSerializeEmptyArray(): void
    {
        $this->assertSame('', $this->atlin->serialize([]));
    }

    public function testSerializeWithoutBlankLines(): void
    {
        $output = $this->atlin->serialize(['a' => '1', 'b' => '2'], false);
        $this->assertStringNotContainsString("\n\n", $output);
    }

    // ── File parsing ─────────────────────────────────────────────────────────

    public function testParseFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'atlin_') . '.atlin';
        file_put_contents($tmp, "@hello\nworld");
        $result = $this->atlin->parseFile($tmp, false);
        unlink($tmp);
        $this->assertSame('world', $result['hello']);
    }

    public function testParseFileThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->atlin->parseFile('/non/existent/file.atlin');
    }

    // ── Cache (FileCache integration) ────────────────────────────────────────

    public function testFileCacheStoresAndRetrieves(): void
    {
        $dir    = sys_get_temp_dir() . '/atlin_test_' . uniqid('', true);
        $cache  = new FileCache($dir);
        $config = new AtlinConfig($cache, 0, false);
        $atlin  = new Atlin($config);

        $result1 = $atlin->parse("@title\nAtlin Test", 'test_key');
        $result2 = $atlin->parse("@title\nAtlin Test", 'test_key');

        $this->assertSame($result1, $result2);
        $this->assertSame('Atlin Test', $result1['title']);

        $atlin->flushCache();
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testInvalidateCacheEntry(): void
    {
        $dir    = sys_get_temp_dir() . '/atlin_inv_' . uniqid('', true);
        $cache  = new FileCache($dir);
        $config = new AtlinConfig($cache, 0, false);
        $atlin  = new Atlin($config);

        $atlin->parse("@k\nv", 'inv_key');
        $atlin->invalidate('inv_key');

        $this->assertNull($cache->get('inv_key'));

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testFlushCache(): void
    {
        $dir    = sys_get_temp_dir() . '/atlin_flush_' . uniqid('', true);
        $cache  = new FileCache($dir);
        $config = new AtlinConfig($cache, 0, false);
        $atlin  = new Atlin($config);

        $atlin->parse("@a\nb", 'key1');
        $atlin->parse("@c\nd", 'key2');
        $atlin->flushCache();

        $this->assertNull($cache->get('key1'));
        $this->assertNull($cache->get('key2'));

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testContentHashKeyDifferentiatesDifferentContent(): void
    {
        $dir    = sys_get_temp_dir() . '/atlin_hash_' . uniqid('', true);
        $cache  = new FileCache($dir);
        $config = new AtlinConfig($cache, 0, true);
        $atlin  = new Atlin($config);

        $atlin->parse("@a\nv1", 'same_key');
        $result = $atlin->parse("@a\nv2", 'same_key');

        $this->assertSame('v2', $result['a']);

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testCustomMarker(): void
    {
        $config = new AtlinConfig(null, 0, true, ['#']);
        $atlin  = new Atlin($config);

        $result = $atlin->parse("#key\nvalue");
        $this->assertSame('value', $result['key']);
        // @ should now be treated as a plain value character
        $this->assertSame('@notakey', $atlin->parse("#k\n@notakey")['k']);
    }

    public function testMultipleMarkers(): void
    {
        $config = new AtlinConfig(null, 0, true, ['@', '#']);
        $atlin  = new Atlin($config);

        $result = $atlin->parse("@key1\nval1\n\n#key2\nval2");
        $this->assertSame('val1', $result['key1']);
        $this->assertSame('val2', $result['key2']);
    }

    public function testEscapeCustomMarker(): void
    {
        $config = new AtlinConfig(null, 0, true, ['#']);
        $atlin  = new Atlin($config);

        $result = $atlin->parse("#key\n\\#not-a-key");
        $this->assertStringContainsString('#not-a-key', $result['key']);
    }

    public function testSerializeUsesFirstMarker(): void
    {
        $config = new AtlinConfig(null, 0, true, ['#', '@']);
        $atlin  = new Atlin($config);

        $out = $atlin->serialize(['k' => 'v']);
        $this->assertStringStartsWith('#k', $out);
    }

    public function testEmptyMarkersDefaultsToAt(): void
    {
        $config = new AtlinConfig(null, 0, true, []);
        $atlin  = new Atlin($config);

        $result = $atlin->parse("@key\nvalue");
        $this->assertSame('value', $result['key']);
    }
}
