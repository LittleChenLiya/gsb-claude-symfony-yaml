<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Yaml\Tag;

use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Represents a YAML "!!binary" scalar.
 *
 * The tag that selected the binary strategy, the raw base64 text as written
 * in the document and the decoded bytes are kept separately, so that binary
 * payloads (including NUL bytes and non-UTF-8 sequences) are never silently
 * rewritten as if they were plain UTF-8 strings.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class Binary
{
    public const TAG = '!!binary';

    /**
     * The maximum number of decoded bytes accepted by default.
     */
    public const DEFAULT_MAX_BYTES = 16_777_216; // 16 MiB

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    private const WHITESPACE = [' ', "\t", "\n", "\r"];

    private function __construct(
        private readonly string $bytes,
        private readonly string $raw,
    ) {
    }

    /**
     * Creates a binary value from raw bytes (e.g. when dumping).
     */
    public static function fromBytes(string $bytes): self
    {
        return new self($bytes, base64_encode($bytes));
    }

    /**
     * Creates a binary value from its base64 representation.
     *
     * Whitespace (space, tab, CR and LF, e.g. from folded block scalars) is
     * ignored as mandated by RFC 4648 and never alters the decoded bytes.
     * Padding ("=") is only accepted at the very end of the data; misplaced
     * or excess padding is reported with its position. The decoded length is
     * computed arithmetically and checked against $maxBytes *before* any
     * buffer proportional to the payload is allocated, so over-limit data
     * is rejected without decoding it.
     *
     * @throws ParseException When the data is not valid base64 or decodes to more than $maxBytes bytes
     */
    public static function fromBase64(string $raw, ?int $maxBytes = null): self
    {
        $maxBytes ??= self::DEFAULT_MAX_BYTES;

        $length = \strlen($raw);
        $nonWhitespace = $length
            - substr_count($raw, ' ')
            - substr_count($raw, "\t")
            - substr_count($raw, "\n")
            - substr_count($raw, "\r");

        // Reject over-limit payloads arithmetically, before allocating
        // anything proportional to the payload size.
        if (0 !== $nonWhitespace && 0 === $nonWhitespace % 4) {
            $trailingPadding = 0;

            for ($i = $length - 1; $i >= 0 && $trailingPadding <= 2; --$i) {
                if (\in_array($raw[$i], self::WHITESPACE, true)) {
                    continue;
                }

                if ('=' !== $raw[$i]) {
                    break;
                }

                ++$trailingPadding;
            }

            if ($trailingPadding <= 2 && ($decodedLength = intdiv($nonWhitespace, 4) * 3 - $trailingPadding) > $maxBytes) {
                throw new ParseException(\sprintf('The base64 encoded data decodes to %d bytes, which exceeds the maximum allowed size of %d bytes.', $decodedLength, $maxBytes));
            }
        }

        $normalized = '';
        $firstPadPosition = null;
        $padding = 0;

        for ($i = 0; $i < $length; ++$i) {
            $char = $raw[$i];

            if (\in_array($char, self::WHITESPACE, true)) {
                continue;
            }

            if ('=' === $char) {
                $firstPadPosition ??= $i;

                if (++$padding > 2) {
                    throw new ParseException(\sprintf('The base64 encoded data (%s) contains invalid characters: unexpected padding "=" at position %d, at most two padding characters are allowed.', $normalized, $i));
                }

                $normalized .= $char;

                continue;
            }

            if (null !== $firstPadPosition) {
                throw new ParseException(\sprintf('The base64 encoded data (%s) contains invalid characters: padding "=" at position %d is only allowed at the end of the data, found "%s" at position %d.', $normalized, $firstPadPosition, $char, $i));
            }

            if (!str_contains(self::ALPHABET, $char)) {
                throw new ParseException(\sprintf('The base64 encoded data (%s) contains invalid characters: unexpected character "%s" at position %d.', $normalized, $char, $i));
            }

            $normalized .= $char;
        }

        if ('' === $normalized) {
            return new self('', $raw);
        }

        if (0 !== \strlen($normalized) % 4) {
            throw new ParseException(\sprintf('The normalized base64 encoded data (data without whitespace characters) length must be a multiple of four (%d bytes given).', \strlen($normalized)));
        }

        $decodedLength = intdiv(\strlen($normalized), 4) * 3 - $padding;

        if ($decodedLength > $maxBytes) {
            throw new ParseException(\sprintf('The base64 encoded data decodes to %d bytes, which exceeds the maximum allowed size of %d bytes.', $decodedLength, $maxBytes));
        }

        $bytes = base64_decode($normalized, true);

        if (false === $bytes) {
            // cannot happen given the validation above, kept as a safety net
            throw new ParseException(\sprintf('The base64 encoded data (%s) could not be decoded.', $normalized));
        }

        return new self($bytes, $raw);
    }

    /**
     * The decoded bytes, which may contain NUL bytes and non-UTF-8 sequences.
     */
    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * The base64 text as written in the parsed document (whitespace included).
     */
    public function raw(): string
    {
        return $this->raw;
    }

    /**
     * The tag that selected the binary strategy.
     */
    public function tag(): string
    {
        return self::TAG;
    }

    /**
     * The canonical single-line base64 representation used when dumping.
     */
    public function toBase64(): string
    {
        return base64_encode($this->bytes);
    }

    /**
     * The number of decoded bytes.
     */
    public function length(): int
    {
        return \strlen($this->bytes);
    }
}