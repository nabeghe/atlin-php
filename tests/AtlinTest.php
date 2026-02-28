<?php

declare(strict_types=1);

namespace Nabeghe\Atlin\Tests;

use Nabeghe\Atlin\Atlin;
use Nabeghe\Atlin\Cache\FileCache;
use Nabeghe\Atlin\Config\AtlinConfig;
use PHPUnit\Framework\TestCase;

class AtlinTest extends TestCase
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

    // ── Escaping @ ───────────────────────────────────────────────────────────

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
        $input  = "@first\nvalue1\n\n\n\n@second\nvalue2";
        $result = $this->atlin->parse($input);
        $this->assertSame("value1\n\n", $result['first']);
        $this->assertSame('value2', $result['second']);
    }

    public function testSingleBlankLineIsSeparator(): void
    {
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

    // ── Comments ─────────────────────────────────────────────────────────────

    public function testCommentLineIsDiscarded(): void
    {
        $input  = "@key\n# this is a comment\nvalue";
        $result = $this->atlin->parse($input);
        $this->assertSame('value', $result['key']);
    }

    public function testMultipleCommentLinesAreDiscarded(): void
    {
        $input  = "# file header\n# second comment line\n@key\nvalue";
        $result = $this->atlin->parse($input);
        $this->assertArrayNotHasKey('', $result);
        $this->assertSame('value', $result['key']);
    }

    public function testCommentBetweenKeysIsDiscarded(): void
    {
        $input  = "@key1\nvalue1\n# separator comment\n@key2\nvalue2";
        $result = $this->atlin->parse($input);
        $this->assertSame('value1', $result['key1']);
        $this->assertSame('value2', $result['key2']);
    }

    public function testHashWithLeadingSpaceIsNotAComment(): void
    {
        $input  = "@key\n # not a comment\nvalue";
        $result = $this->atlin->parse($input);
        $this->assertSame(" # not a comment\nvalue", $result['key']);
    }

    public function testHashMidLineIsNotAComment(): void
    {
        $input  = "@key\ncolor: #ff0000";
        $result = $this->atlin->parse($input);
        $this->assertSame('color: #ff0000', $result['key']);
    }

    public function testCommentsDisabledTreatsHashAsValue(): void
    {
        $config = new AtlinConfig(comments: false);
        $atlin  = new Atlin($config);

        $input  = "@key\n# not ignored\nvalue";
        $result = $atlin->parse($input);
        $this->assertSame("# not ignored\nvalue", $result['key']);
    }

    public function testCommentsDisabledAllowsHashAsOrphanKey(): void
    {
        $config = new AtlinConfig(comments: false);
        $atlin  = new Atlin($config);

        $result = $atlin->parse("# orphan\n@key\nvalue");
        $this->assertSame('# orphan', $result['']);
        $this->assertSame('value', $result['key']);
    }

    public function testCommentOnlyFileReturnsEmpty(): void
    {
        $input = "# just comments\n# nothing else\n";
        $this->assertSame([], $this->atlin->parse($input));
    }

    public function testCommentDoesNotBreakBlankLineCounting(): void
    {
        // comment is stripped first; two blank lines remain →
        // one separator + one value newline → value = "value\n"
        $input  = "@key\nvalue\n\n# comment between blanks\n\n@next\nnext value";
        $result = $this->atlin->parse($input);
        $this->assertSame("value\n", $result['key']);
        $this->assertSame('next value', $result['next']);
    }

    // ── Escaping # ───────────────────────────────────────────────────────────

    public function testEscapedHashIsNotTreatedAsComment(): void
    {
        $input  = "@key\n\\#not a comment\nvalue";
        $result = $this->atlin->parse($input);
        $this->assertSame("#not a comment\nvalue", $result['key']);
    }

    public function testEscapedHashStripsOnlyTheBackslash(): void
    {
        // Only the leading \ is removed; the # and the rest are kept as-is.
        $input  = "@key\n\\#ff0000";
        $result = $this->atlin->parse($input);
        $this->assertSame('#ff0000', $result['key']);
    }

    public function testEscapedHashWorksAlongsideEscapedAt(): void
    {
        $input  = "@key\n\\#hash line\n\\@at line";
        $result = $this->atlin->parse($input);
        $this->assertSame("#hash line\n@at line", $result['key']);
    }

    public function testEscapedHashNotNeededWhenCommentsDisabled(): void
    {
        // When comments are off, \# should NOT strip the backslash —
        // the literal string "\#" is the value.
        $config = new AtlinConfig(comments: false);
        $atlin  = new Atlin($config);

        $input  = "@key\n\\#still has backslash";
        $result = $atlin->parse($input);
        $this->assertSame('\\#still has backslash', $result['key']);
    }

    public function testEscapedHashInOrphanBlock(): void
    {
        // \# before the first key → goes into the "" key
        $input  = "\\#escaped at top\n@key\nvalue";
        $result = $this->atlin->parse($input);
        $this->assertSame('#escaped at top', $result['']);
        $this->assertSame('value', $result['key']);
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
        $tmp = tempnam(sys_get_temp_dir(), 'atlin_').'.atlin';
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
        $dir    = sys_get_temp_dir().'/atlin_test_'.uniqid('', true);
        $cache  = new FileCache($dir);
        $config = new AtlinConfig($cache, 0, false);
        $atlin  = new Atlin($config);

        $result1 = $atlin->parse("@title\nAtlin Test", 'test_key');
        $result2 = $atlin->parse("@title\nAtlin Test", 'test_key');

        $this->assertSame($result1, $result2);
        $this->assertSame('Atlin Test', $result1['title']);

        $atlin->flushCache();
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }

    public function testInvalidateCacheEntry(): void
    {
        $dir    = sys_get_temp_dir().'/atlin_inv_'.uniqid('', true);
        $cache  = new FileCache($dir);
        $config = new AtlinConfig($cache, 0, false);
        $atlin  = new Atlin($config);

        $atlin->parse("@k\nv", 'inv_key');
        $atlin->invalidate('inv_key');

        $this->assertNull($cache->get('inv_key'));

        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }

    public function testFlushCache(): void
    {
        $dir    = sys_get_temp_dir().'/atlin_flush_'.uniqid('', true);
        $cache  = new FileCache($dir);
        $config = new AtlinConfig($cache, 0, false);
        $atlin  = new Atlin($config);

        $atlin->parse("@a\nb", 'key1');
        $atlin->parse("@c\nd", 'key2');
        $atlin->flushCache();

        $this->assertNull($cache->get('key1'));
        $this->assertNull($cache->get('key2'));

        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }

    public function testContentHashKeyDifferentiatesDifferentContent(): void
    {
        $dir    = sys_get_temp_dir().'/atlin_hash_'.uniqid('', true);
        $cache  = new FileCache($dir);
        $config = new AtlinConfig($cache, 0, true);
        $atlin  = new Atlin($config);

        $atlin->parse("@a\nv1", 'same_key');
        $result = $atlin->parse("@a\nv2", 'same_key');

        $this->assertSame('v2', $result['a']);

        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }
}
