<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Yaml\Tests\Tag;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\BinaryScalar;
use Symfony\Component\Yaml\Yaml;

/**
 * Run with: php vendor/bin/phpunit Tests/Tag/BinaryScalarTest.php
 */
class BinaryScalarTest extends TestCase
{
    /**
     * Three scalar examples: NUL-containing payload, non-UTF-8 payload and
     * base64 text split over folded lines (whitespace must not change bytes).
     */
    public static function provideScalarExamples(): iterable
    {
        yield 'payload with NUL bytes' => ["\x00\x01\x02\x03\x04", 'AAECAwQ='];
        yield 'non-UTF-8 payload' => ["\xFB\xFF", '+/8='];
        yield 'folded base64 lines' => ["\x00\x01\x02\x03\x04", "AAEC\nAwQ="];
    }

    #[DataProvider('provideScalarExamples')]
    public function testFromBase64KeepsTagRawTextAndBytesSeparate(string $expectedBytes, string $rawBase64)
    {
        $binary = BinaryScalar::fromBase64($rawBase64);

        $this->assertSame('!!binary', $binary->getTag());
        $this->assertSame($rawBase64, $binary->getRawBase64());
        $this->assertSame($expectedBytes, $binary->getBytes());
        $this->assertSame(base64_encode($expectedBytes), $binary->toBase64());
        $this->assertSame('!!binary '.base64_encode($expectedBytes), $binary->toYaml());
    }

    public function testRoundTripWithNulAndNonUtf8Payload()
    {
        $payload = "\x00\x01\x02\xFB\xFF\x00";
        $binary = BinaryScalar::fromBytes($payload);

        $this->assertSame($payload, $binary->getBytes());
        $this->assertSame($payload, BinaryScalar::fromBase64($binary->getRawBase64())->getBytes());

        $yaml = Yaml::dump(['payload' => $binary]);
        $this->assertStringContainsString('payload: !!binary ', $yaml);
        $this->assertStringNotContainsString("\x00", $yaml);

        // default parse keeps the historical behavior: raw bytes as a plain string
        $parsed = Yaml::parse($yaml);
        $this->assertIsString($parsed['payload']);
        $this->assertSame($payload, $parsed['payload']);

        // explicit opt-in: bytes are wrapped in a BinaryScalar value object
        $parsed = Yaml::parse($yaml, Yaml::PARSE_BINARY_SCALAR);
        $this->assertInstanceOf(BinaryScalar::class, $parsed['payload']);
        $this->assertSame($payload, $parsed['payload']->getBytes());
        $this->assertSame('!!binary', $parsed['payload']->getTag());

        // dumping the parsed value again yields the same YAML: full round-trip
        $this->assertSame($yaml, Yaml::dump(['payload' => $parsed['payload']]));
    }

    public function testDumpRootLevelBinaryScalar()
    {
        $this->assertSame('!!binary AAECAwQ=', Yaml::dump(BinaryScalar::fromBytes("\x00\x01\x02\x03\x04")));
    }

    #[DataProvider('provideInvalidPadding')]
    public function testBadPaddingReportsPosition(string $rawBase64, int $expectedPosition)
    {
        try {
            BinaryScalar::fromBase64($rawBase64);
            $this->fail('Expected a ParseException to be thrown.');
        } catch (ParseException $e) {
            $this->assertStringContainsString('contains invalid characters', $e->getMessage());
            $this->assertStringContainsString(\sprintf('position %d', $expectedPosition), $e->getMessage());
        }
    }

    public static function provideInvalidPadding(): iterable
    {
        yield 'misplaced padding' => ['Zg=A', 2];
        yield 'padding in the middle' => ['SGVsbG8gd29ybG=Q', 14];
        yield 'too much padding' => ['SGVsbG8gd29yb===', 15];
    }

    public function testBadPaddingReportsPositionWhenParsing()
    {
        try {
            Yaml::parse("key: !!binary Zg=A\n");
            $this->fail('Expected a ParseException to be thrown.');
        } catch (ParseException $e) {
            $this->assertStringContainsString('position 2', $e->getMessage());
            $this->assertSame(1, $e->getParsedLine());
        }
    }

    public function testInvalidCharacterReportsPosition()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('invalid character "#" at position 7');

        BinaryScalar::fromBase64('SGVsbG8#d29ybGQ=');
    }

    public function testLengthNotMultipleOfFourIsRejected()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('length must be a multiple of four (2 bytes given)');

        BinaryScalar::fromBase64('QQ');
    }

    public function testPlainBase64LikeStringRemainsAString()
    {
        foreach ([0, Yaml::PARSE_BINARY_SCALAR] as $flags) {
            $parsed = Yaml::parse('key: Zm9vYmFy', $flags);
            $this->assertIsString($parsed['key']);
            $this->assertSame('Zm9vYmFy', $parsed['key']);
        }

        // dumping and re-parsing a plain string never enables the binary strategy
        $yaml = Yaml::dump(['key' => 'Zm9vYmFy']);
        $this->assertStringNotContainsString('!!binary', $yaml);
        $this->assertSame('Zm9vYmFy', Yaml::parse($yaml, Yaml::PARSE_BINARY_SCALAR)['key']);
    }

    public function testExceedingSizeLimitIsRejectedBeforeDecoding()
    {
        // 4 MiB of base64 text decoding to 3 MiB of bytes
        $rawBase64 = str_repeat('QUJD', 1024 * 1024);

        $memoryBefore = memory_get_usage();

        try {
            BinaryScalar::fromBase64($rawBase64, 1024);
            $this->fail('Expected a ParseException to be thrown.');
        } catch (ParseException $e) {
            $this->assertStringContainsString('exceeds the maximum of 1024 bytes', $e->getMessage());
        }

        // the limit is enforced arithmetically: no decoded payload was allocated
        $this->assertLessThan(1_000_000, memory_get_usage() - $memoryBefore);
    }

    public function testFoldedLinesDoNotChangeBytes()
    {
        $yaml = "key: !!binary >\n  AAEC\n  AwQ=\n";
        $expectedBytes = "\x00\x01\x02\x03\x04";

        $parsed = Yaml::parse($yaml);
        $this->assertSame($expectedBytes, $parsed['key']);

        $parsed = Yaml::parse($yaml, Yaml::PARSE_BINARY_SCALAR);
        $this->assertInstanceOf(BinaryScalar::class, $parsed['key']);
        $this->assertSame($expectedBytes, $parsed['key']->getBytes());

        // folded, spaced and single-line base64 all decode to the same bytes
        $this->assertSame(
            BinaryScalar::fromBase64('AAECAwQ=')->getBytes(),
            BinaryScalar::fromBase64("AAEC\n AwQ=\t")->getBytes()
        );
    }

    public function testCustomObjectTagsAreNotExecuted()
    {
        // object instantiation must stay opt-in; parsing binary data never triggers it
        $this->assertNull(Yaml::parse('!php/object "O:8:stdClass:0:{}"'));
        $this->assertSame("\x00", Yaml::parse('!!binary AA=='));
    }
}
