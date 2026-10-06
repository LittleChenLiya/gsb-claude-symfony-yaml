<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Yaml;

use Symfony\Component\Yaml\Exception\ParseException;

/**
 * An explicit, strict policy for YAML date scalars.
 *
 * The policy draws a hard line between plain strings, timestamps and
 * \DateTimeInterface objects: a scalar is only ever converted when it exactly
 * matches the declared schema below; it is never converted just "because it
 * looks like a date". Scalars that have the general shape of a date but
 * violate the policy are rejected with a dedicated error instead of being
 * silently normalized or interpreted in the machine's local timezone.
 *
 * Declared schema (fixed-width, YAML 1.2 timestamp profile of ISO 8601):
 *
 *     YYYY-MM-DD                                              (date only)
 *     YYYY-MM-DD[Tt or space]HH:MM:SS[.fraction][Z|+HH:MM|-HH:MM]
 *
 * Rules:
 *  - a date-only scalar denotes midnight UTC; the local timezone is never consulted;
 *  - a date-time scalar MUST carry an explicit timezone ("Z" or "±HH:MM"); a
 *    missing timezone is rejected, the machine default is never assumed;
 *  - the calendar date must be valid (e.g. February 30th is rejected);
 *  - seconds range from 00 to 59 (leap seconds are not supported);
 *  - fractions of a second are accepted with 1 to MAX_FRACTION_DIGITS digits
 *    (microsecond precision); more digits are rejected as over-precision
 *    instead of being silently truncated;
 *  - dumping always emits the same schema with an explicit numeric offset, so
 *    that self::parse(self::dump($date)) denotes the very same instant.
 *
 * Four examples:
 *  - "2026-10-07T12:34:56+02:00"   -> \DateTimeImmutable for that exact instant
 *  - "2026-10-07T12:34:56.123456Z" -> \DateTimeImmutable, microseconds preserved
 *  - "2026-10-07"                  -> \DateTimeImmutable at midnight UTC
 *  - "2026-10-07T12:34:56"         -> rejected: no timezone offset
 */
final class DateScalarPolicy
{
    /**
     * The maximum number of accepted fractional-second digits (microseconds).
     *
     * Fractions with 1 to 6 digits are accepted and preserved; fractions with
     * more digits are rejected as over-precision instead of being truncated.
     */
    public const MAX_FRACTION_DIGITS = 6;

    /**
     * Tells whether a scalar exactly matches the declared date schema,
     * including the rule that a date-time scalar carries an explicit timezone.
     */
    public static function matches(string $scalar): bool
    {
        if (!preg_match(self::getSchemaRegex(), $scalar, $matches)) {
            return false;
        }

        return '' === ($matches['hour'] ?? '') || '' !== ($matches['tz'] ?? '');
    }

    /**
     * Converts a scalar to a \DateTimeImmutable according to the declared schema.
     *
     * The machine's default timezone is never consulted: date-only scalars
     * denote midnight UTC and date-time scalars must carry an explicit offset.
     *
     * @throws ParseException When the scalar does not match the declared schema,
     *                        carries an over-precise fraction, denotes an invalid
     *                        calendar date or lacks a timezone offset
     */
    public static function parse(string $scalar, int $parsedLine = -1, ?string $parsedFile = null): \DateTimeImmutable
    {
        if (!preg_match(self::getSchemaRegex(), $scalar, $matches)) {
            throw new ParseException(\sprintf('The date "%s" does not match the declared date schema "YYYY-MM-DD[THH:MM:SS[.ffffff][Z|±HH:MM]]".', $scalar), $parsedLine, $scalar, $parsedFile);
        }

        $fraction = $matches['fraction'] ?? '';

        if (self::MAX_FRACTION_DIGITS < \strlen($fraction)) {
            throw new ParseException(\sprintf('The date "%s" has %d fractional digits, which exceeds the maximum supported precision of %d digits (microseconds).', $scalar, \strlen($fraction), self::MAX_FRACTION_DIGITS), $parsedLine, $scalar, $parsedFile);
        }

        if (!checkdate((int) $matches['month'], (int) $matches['day'], (int) $matches['year'])) {
            throw new ParseException(\sprintf('The date "%s" is not a valid calendar date.', $scalar), $parsedLine, $scalar, $parsedFile);
        }

        if ('' === ($matches['hour'] ?? '')) {
            // A date-only scalar denotes midnight UTC; the local timezone is never consulted.
            return new \DateTimeImmutable(\sprintf('%04d-%02d-%02dT00:00:00+00:00', (int) $matches['year'], (int) $matches['month'], (int) $matches['day']), new \DateTimeZone('UTC'));
        }

        if (23 < (int) $matches['hour'] || 59 < (int) $matches['minute'] || 59 < (int) $matches['second']) {
            throw new ParseException(\sprintf('The time in the date "%s" is not a valid time of day (leap seconds are not supported).', $scalar), $parsedLine, $scalar, $parsedFile);
        }

        if ('' === ($tz = $matches['tz'] ?? '')) {
            throw new ParseException(\sprintf('The date "%s" has no timezone offset; the local timezone is never assumed. Append "Z" or an explicit "+HH:MM"/"-HH:MM" offset.', $scalar), $parsedLine, $scalar, $parsedFile);
        }

        if ('Z' !== $tz && (23 < (int) substr($tz, 1, 2) || 59 < (int) substr($tz, 4, 2))) {
            throw new ParseException(\sprintf('The timezone offset in the date "%s" is not a valid offset.', $scalar), $parsedLine, $scalar, $parsedFile);
        }

        $normalized = \sprintf('%04d-%02d-%02dT%02d:%02d:%02d', (int) $matches['year'], (int) $matches['month'], (int) $matches['day'], (int) $matches['hour'], (int) $matches['minute'], (int) $matches['second']);

        if ('' !== $fraction) {
            $normalized .= '.'.str_pad($fraction, self::MAX_FRACTION_DIGITS, '0');
        }

        $normalized .= 'Z' === $tz ? '+00:00' : $tz;

        // The normalized string always carries an explicit offset, so the
        // default timezone is never consulted; it is pinned to UTC regardless.
        return new \DateTimeImmutable($normalized, new \DateTimeZone('UTC'));
    }

    /**
     * Dumps a date according to the same declared schema, with an explicit
     * numeric offset, so that self::parse(self::dump($date)) denotes the very
     * same instant.
     */
    public static function dump(\DateTimeInterface $date): string
    {
        $fraction = rtrim($date->format('u'), '0');

        return $date->format('Y-m-d\TH:i:s').('' === $fraction ? '' : '.'.$fraction).$date->format('P');
    }

    private static function getSchemaRegex(): string
    {
        return <<<'REGEX'
            ~^
            (?P<year>[0-9]{4})
            -(?P<month>[0-9]{2})
            -(?P<day>[0-9]{2})
            (?:
                (?:[Tt]|[ \t]+)
                (?P<hour>[0-9]{2})
                :(?P<minute>[0-9]{2})
                :(?P<second>[0-9]{2})
                (?:\.(?P<fraction>[0-9]+))?
                (?:[ \t]*(?P<tz>Z|[-+][0-9]{2}:[0-9]{2}))?
            )?
            $~x
            REGEX;
    }
}
