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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\Binary;

class BinaryTest extends TestCase
{
    public function testFromBase64DecodesNulAndNonUtf8Bytes()
    {
        $payload = "\x00\x01\x02\xff\xfeNUL:\x00\x00";
        $binary = Binary::fromBase64(base64_encode($payload));

        $this->assertSame($payload, $binary->bytes());
        $this->assertSame(\strlen($payload), $binary->length());
    }

    public function testFromBase64IgnoresWhitespaceWithoutChangingBytes()
    {
        $expected = Binary::fromBase64('AAEC/wQ=')->bytes();

        // folding the base64 text (as YAML block scalars do) must not change the decoded bytes
        $this->assertSame($expected, Binary::fromBase64("AAEC\n/wQ=")->bytes());
        $this->assertSame($expected, Binary::fromBase64("AAEC\r\n  /wQ=\t")->bytes());
        $this->assertSame($expected, Binary::fromBase64("AA EC/w Q=")->bytes());
    }

    public function testTagRawAndBytesAreKeptSeparately()
    {
        $binary = Binary::fromBase64("AAEC\n/wQ=");

        $this->assertSame('!!binary', $binary->tag());
        $this->assertSame("AAEC\n/wQ=", $binary->raw());
        $this->assertSame("\x00\x01\x02\xff\x04", $binary->bytes());
        $this->assertSame('AAEC/wQ=', $binary->toBase64());
    }

    public function testFromBytesRoundTripsThroughBase64()
    {
        $payload = random_bytes(64)."\x00\xff";
        $binary = Binary::fromBytes($payload);

        $this->assertSame($payload, $binary->bytes());
        $this->assertSame($payload, Binary::fromBase64($binary->toBase64())->bytes());
    }

    public function testEmptyData()
    {
        $this->assertSame('', Binary::fromBase64('')->bytes());
        $this->assertSame('', Binary::fromBase64("  \n\t")->bytes());
        $this->assertSame('', Binary::fromBytes('')->toBase64());
    }

    public function testMisplacedPaddingIsRejectedWithPosition()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('padding "=" at position 6 is only allowed at the end of the data, found "Q" at position 7');

        Binary::fromBase64('SGVsbG=Q');
    }

    public function testExcessPaddingIsRejectedWithPosition()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('unexpected padding "=" at position 9, at most two padding characters are allowed');

        Binary::fromBase64('SGVsbG8===');
    }

    public function testInvalidCharacterIsRejectedWithPosition()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('unexpected character "#" at position 3');

        Binary::fromBase64('SGV#sbG8');
    }

    public function testInvalidCharacterPositionIsReportedInRawCoordinates()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('unexpected character "#" at position 7');

        Binary::fromBase64("SGV\n sb#G8");
    }

    public function testLengthNotMultipleOfFourIsRejected()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('The normalized base64 encoded data (data without whitespace characters) length must be a multiple of four (5 bytes given).');

        Binary::fromBase64('SGVsb');
    }

    public function testOverLimitDataIsRejectedBeforeDecoding()
    {
        // 8 MiB of base64 decodes to 6 MiB; the limit must be enforced
        // arithmetically, without allocating the decoded buffer
        $input = str_repeat('QUJD', 2 * 1024 * 1024);

        try {
            Binary::fromBase64($input, 1024);
            $this->fail('Expected a ParseException to be thrown.');
        } catch (ParseException $e) {
            $this->assertSame('The base64 encoded data decodes to 6291456 bytes, which exceeds the maximum allowed size of 1024 bytes.', $e->getMessage());
        }
    }

    public function testDefaultMaxBytesIsAccepted()
    {
        $binary = Binary::fromBase64(base64_encode(str_repeat('a', 1024)));

        $this->assertSame(1024, $binary->length());
    }
}
