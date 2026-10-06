<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Yaml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\Binary;
use Symfony\Component\Yaml\Yaml;

/**
 * Integration tests for the explicit "!!binary" scalar strategy.
 *
 * The three supported scalar styles are:
 *  - plain inline:   key: !!binary AAEC/w==
 *  - quoted inline:  key: !!binary "AAEC/w=="
 *  - folded block:   key: !!binary |  (base64 split over several lines)
 */
class BinaryScalarTest extends TestCase
{
    private const PAYLOAD = "\x00\x01\x02\xff\xfeNUL:\x00\x00";
    private const PAYLOAD_BASE64 = 'AAEC//5OVUw6AAA=';

    #[DataProvider('provideScalarExamples')]
    public function testParseBinaryScalarExamples(string $yaml)
    {
        $result = Yaml::parse($yaml, Yaml::PARSE_BINARY);

        $this->assertInstanceOf(Binary::class, $result['key']);
        $this->assertSame('!!binary', $result['key']->tag());
        $this->assertSame(self::PAYLOAD, $result['key']->bytes());
    }

    public static function provideScalarExamples(): iterable
    {
        yield 'plain inline scalar' => ['key: !!binary '.self::PAYLOAD_BASE64];

        yield 'double-quoted inline scalar' => ['key: !!binary "'.self::PAYLOAD_BASE64.'"'];

        yield 'folded block scalar' => ["key: !!binary |\n    AAEC//5O\n    VUw6AAA=\n"];
    }

    public function testFoldedBlockScalarDoesNotChangeBytes()
    {
        $oneLine = Yaml::parse('key: !!binary '.self::PAYLOAD_BASE64, Yaml::PARSE_BINARY)['key'];
        $folded = Yaml::parse("key: !!binary |\n    AAEC\n    //5O\n    VUw6\n    AAA=\n", Yaml::PARSE_BINARY)['key'];

        $this->assertSame($oneLine->bytes(), $folded->bytes());
        $this->assertSame(self::PAYLOAD, $folded->bytes());
    }

    public function testRawTextIsPreservedSeparatelyFromBytes()
    {
        $binary = Yaml::parse("key: !!binary |\n    AAEC//5O\n    VUw6AAA=\n", Yaml::PARSE_BINARY)['key'];

        $this->assertSame("AAEC//5O\nVUw6AAA=\n", $binary->raw());
        $this->assertSame(self::PAYLOAD, $binary->bytes());
        $this->assertSame(self::PAYLOAD_BASE64, $binary->toBase64());
    }

    public function testBinaryRoundTripWithNulAndNonUtf8Bytes()
    {
        $dumped = Yaml::dump(['key' => Binary::fromBytes(self::PAYLOAD)]);

        $this->assertSame('key: !!binary '.self::PAYLOAD_BASE64, trim($dumped));

        $reparsed = Yaml::parse($dumped, Yaml::PARSE_BINARY);

        $this->assertInstanceOf(Binary::class, $reparsed['key']);
        $this->assertSame(self::PAYLOAD, $reparsed['key']->bytes());
    }

    public function testInlineRoundTrip()
    {
        $dumped = Yaml::dump(['key' => Binary::fromBytes(self::PAYLOAD)], 0);

        $this->assertSame('{ key: !!binary '.self::PAYLOAD_BASE64.' }', $dumped);
        $this->assertSame(self::PAYLOAD, Yaml::parse($dumped, Yaml::PARSE_BINARY)['key']->bytes());
    }

    public function testBinaryIsNotEnabledWithoutTheFlag()
    {
        // without Yaml::PARSE_BINARY, "!!binary" keeps decoding to a plain string
        $this->assertSame(['key' => self::PAYLOAD], Yaml::parse('key: !!binary '.self::PAYLOAD_BASE64));
    }

    public function testPlainBase64LikeStringStaysAString()
    {
        // a plain scalar that merely looks like base64 must not enable the binary strategy
        $this->assertSame(['key' => 'aGVsbG8='], Yaml::parse('key: aGVsbG8='));
        $this->assertSame(['key' => 'aGVsbG8='], Yaml::parse('key: aGVsbG8=', Yaml::PARSE_BINARY));
        $this->assertSame('aGVsbG8=', Yaml::parse('aGVsbG8=', Yaml::PARSE_BINARY));
    }

    public function testBadPaddingReportsPosition()
    {
        try {
            Yaml::parse('key: !!binary "SGVsbG8gd29ybG=Q"', Yaml::PARSE_BINARY);
            $this->fail('Expected a ParseException to be thrown.');
        } catch (ParseException $e) {
            $this->assertStringContainsString('padding "=" at position 14 is only allowed at the end of the data, found "Q" at position 15', $e->getMessage());
        }
    }

    public function testExcessPaddingReportsPosition()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('unexpected padding "=" at position 17, at most two padding characters are allowed');

        Yaml::parse('key: !!binary "SGVsbG8gd29ybGQ==="', Yaml::PARSE_BINARY);
    }

    public function testCustomObjectTagIsNotExecuted()
    {
        // even with object support enabled, the binary strategy must never
        // evaluate custom object tags hidden in its value
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('contains invalid characters');

        Yaml::parse('key: !!binary !php/object a', Yaml::PARSE_BINARY | Yaml::PARSE_OBJECT);
    }

    public function testEmptyBinary()
    {
        $result = Yaml::parse("key: !!binary ''", Yaml::PARSE_BINARY);

        $this->assertInstanceOf(Binary::class, $result['key']);
        $this->assertSame('', $result['key']->bytes());
    }
}
