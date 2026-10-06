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
 * The tag, the raw base64 text and the decoded bytes are kept separate so that
 * binary payloads (including NUL bytes and non-UTF-8 sequences) are never
 * rewritten as plain UTF-8 strings. Binary handling is always explicit: plain
 * strings are never turned into BinaryScalar instances automatically, only the
 * "!!binary" tag (or calling one of the named constructors) does.
 *
 * Decoding follows RFC 4648: whitespace (including YAML folding line breaks)
 * is ignored and "=" padding is only accepted at the very end of the
 * normalized data. Invalid encodings and payloads exceeding the configured
 * size limit are rejected before the value is constructed, and the size limit
 * is enforced arithmetically before decoding so that oversized inputs never
 * trigger a large memory allocation.
 *
 * @author Symfony Community
 */
final class BinaryScalar
{
    public const TAG = '!!binary';

    /**
     * Default safety limit for the decoded payload size (16 MiB).
     */
    public const DEFAULT_MAX_BYTES = 16_777_216;

    private const BASE64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    private function __construct(
        private readonly string $bytes,
        private readonly string $rawBase64,
    ) {
    }

    /**
     * Creates a binary scalar from raw bytes.
     */
    public static function fromBytes(string $bytes): self
    {
        return new self($bytes, base64_encode($bytes));
    }

    /**
     * Creates a binary scalar from base64 text.
     *
     * Whitespace is ignored as mandated by RFC 4648, so folded base64 lines
     * decode to the very same bytes as their single-line equivalent.
     *
     * @param string   $rawBase64 The base64 text as written in the document (whitespace allowed)
     * @param int|null $maxBytes  The maximum accepted decoded size, or null for no limit;
     *                            the limit is checked before decoding, without allocating
     *                            the decoded payload
     *
     * @throws ParseException When the base64 text is invalid or the decoded size exceeds $maxBytes
     */
    public static function fromBase64(string $rawBase64, ?int $maxBytes = self::DEFAULT_MAX_BYTES): self
    {
        $normalized = preg_replace('/\s+/', '', $rawBase64);
        $length = \strlen($normalized);

        if (0 === $length) {
            throw new ParseException('The base64 encoded data () contains invalid characters.');
        }

        if (0 !== $length % 4) {
            throw new ParseException(\sprintf('The normalized base64 encoded data (data without whitespace characters) length must be a multiple of four (%d bytes given).', $length));
        }

        $firstPadding = strpos($normalized, '=');
        $dataLength = false === $firstPadding ? $length : $firstPadding;

        if ($dataLength !== $valid = strspn($normalized, self::BASE64_ALPHABET, 0, $dataLength)) {
            throw new ParseException(\sprintf('The base64 encoded data (%s) contains invalid characters (invalid character "%s" at position %d of the normalized data).', $normalized, $normalized[$valid], $valid));
        }

        $padding = 0;

        if (false !== $firstPadding) {
            $padding = strspn($normalized, '=', $firstPadding);

            if ($padding !== $length - $firstPadding) {
                throw new ParseException(\sprintf('The base64 encoded data (%s) contains invalid characters (misplaced "=" padding at position %d of the normalized data).', $normalized, $firstPadding));
            }

            if ($padding > 2) {
                throw new ParseException(\sprintf('The base64 encoded data (%s) contains invalid characters (unexpected "=" padding at position %d of the normalized data).', $normalized, $firstPadding + 2));
            }
        }

        $decodedLength = intdiv($length, 4) * 3 - $padding;

        if (null !== $maxBytes && $decodedLength > $maxBytes) {
            throw new ParseException(\sprintf('The base64 encoded data would decode to %d bytes, which exceeds the maximum of %d bytes.', $decodedLength, $maxBytes));
        }

        $bytes = base64_decode($normalized, true);

        if (false === $bytes) {
            throw new ParseException(\sprintf('The base64 encoded data (%s) contains invalid characters.', $normalized));
        }

        return new self($bytes, $rawBase64);
    }

    /**
     * Returns the tag selecting the binary strategy: "!!binary".
     */
    public function getTag(): string
    {
        return self::TAG;
    }

    /**
     * Returns the decoded bytes, preserved verbatim (NUL bytes included).
     */
    public function getBytes(): string
    {
        return $this->bytes;
    }

    /**
     * Returns the base64 text exactly as it was supplied, whitespace included.
     */
    public function getRawBase64(): string
    {
        return $this->rawBase64;
    }

    /**
     * Returns the canonical (single-line) base64 representation of the bytes.
     */
    public function toBase64(): string
    {
        return base64_encode($this->bytes);
    }

    /**
     * Returns the YAML representation of this scalar: "!!binary <base64>".
     */
    public function toYaml(): string
    {
        return self::TAG.' '.$this->toBase64();
    }
}
