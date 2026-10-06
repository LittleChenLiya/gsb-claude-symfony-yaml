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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Inline;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the explicit date scalar policy (Yaml::PARSE_DATETIME_STRICT and
 * Yaml::DUMP_DATETIME_STRICT).
 *
 * The policy only converts scalars that match the declared schema (a YAML
 * timestamp with an explicit timezone and at most microsecond precision);
 * it never converts a scalar just because it "looks like a date".
 *
 * Four canonical scalar examples:
 *
 *  1. "2024-01-15T10:30:00.123456+05:30" - valid, explicit offset: converted
 *     to a \DateTimeImmutable and dumped back to the very same string/instant;
 *  2. "2024-01-15T10:30:00" - no timezone: kept as the original string, the
 *     machine's default timezone is never consulted;
 *  3. "2024-02-30T10:30:00Z" - impossible calendar date: rejected;
 *  4. "2024-01-15T10:30:00.123456789Z" - beyond microsecond precision: rejected.
 *
 * Run with: vendor/bin/phpunit Tests/DateTimeStrictPolicyTest.php
 */
class DateTimeStrictPolicyTest extends TestCase
{
    private string $defaultTimezone;

    protected function setUp(): void
    {
        Inline::initialize(0, 0);
        $this->defaultTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);
    }

    public function testValidTimestampWithOffsetRoundTripsToSameInstant()
    {
        // Example 1: valid scalar with an explicit offset.
        $scalar = '2024-01-15T10:30:00.123456+05:30';

        $parsed = Inline::parse($scalar, Yaml::PARSE_DATETIME_STRICT);

        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed);
        // 2024-01-15T10:30:00.123456+05:30 is 2024-01-15T05:00:00.123456 UTC.
        $this->assertSame(1705294800, $parsed->getTimestamp());
        $this->assertSame('123456', $parsed->format('u'));
        $this->assertSame('+05:30', $parsed->format('P'));

        $dumped = Inline::dump($parsed, Yaml::DUMP_DATETIME_STRICT);
        $this->assertSame($scalar, $dumped);

        $reparsed = Inline::parse($dumped, Yaml::PARSE_DATETIME_STRICT);
        $this->assertInstanceOf(\DateTimeImmutable::class, $reparsed);
        $this->assertSame($parsed->format('U.u'), $reparsed->format('U.u'), 'The round-trip preserves the exact instant.');
    }

    public function testZuluTimestampIsConvertedToDateTime()
    {
        $parsed = Inline::parse('2001-12-15T02:59:43.1Z', Yaml::PARSE_DATETIME_STRICT);

        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed);
        $this->assertSame('+00:00', $parsed->format('P'));
        $this->assertSame('2001-12-15T02:59:43.100000+00:00', Inline::dump($parsed, Yaml::DUMP_DATETIME_STRICT));
    }

    public function testTimestampWithoutTimezoneIsKeptAsString()
    {
        // Example 2: no timezone, so the scalar is not converted at all.
        $this->assertSame('2024-01-15T10:30:00', Inline::parse('2024-01-15T10:30:00', Yaml::PARSE_DATETIME_STRICT));
        $this->assertSame('2024-01-15', Inline::parse('2024-01-15', Yaml::PARSE_DATETIME_STRICT));

        // The string round-trips as a string (it is quoted on dump).
        $dumped = Inline::dump('2024-01-15T10:30:00', Yaml::DUMP_DATETIME_STRICT);
        $this->assertSame("'2024-01-15T10:30:00'", $dumped);
        $this->assertSame('2024-01-15T10:30:00', Inline::parse($dumped, Yaml::PARSE_DATETIME_STRICT));
    }

    public function testInvalidCalendarDateWithTimezoneIsRejected()
    {
        // Example 3: February 30 does not exist and must not be normalized to March.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('The date "2024-02-30T10:30:00Z" could not be parsed as it is not a valid calendar date');

        Inline::parse('2024-02-30T10:30:00Z', Yaml::PARSE_DATETIME_STRICT);
    }

    public function testInvalidCalendarDateWithoutTimezoneIsRejected()
    {
        // February 30 is rejected even when no timezone is present: it may
        // not "pass" as a string either.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('The date "2024-02-30" could not be parsed as it is not a valid calendar date');

        Inline::parse('2024-02-30', Yaml::PARSE_DATETIME_STRICT);
    }

    public function testExcessivePrecisionIsRejected()
    {
        // Example 4: 9 fractional digits exceed the microsecond precision policy.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('The date "2024-01-15T10:30:00.123456789Z" could not be parsed as its fractional seconds exceed the supported precision of 6 digits (microseconds)');

        Inline::parse('2024-01-15T10:30:00.123456789Z', Yaml::PARSE_DATETIME_STRICT);
    }

    public function testMicrosecondPrecisionIsAccepted()
    {
        $parsed = Inline::parse('2024-01-15T10:30:00.123456Z', Yaml::PARSE_DATETIME_STRICT);

        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed);
        $this->assertSame('123456', $parsed->format('u'));
    }

    public function testOutOfRangeTimeOfDayIsRejected()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('The date "2024-01-15T25:00:00Z" could not be parsed as it is not a valid time of day');

        Inline::parse('2024-01-15T25:00:00Z', Yaml::PARSE_DATETIME_STRICT);
    }

    public function testMachineDefaultTimezoneDoesNotAffectParsingNorDumping()
    {
        $scalar = '2024-01-15T10:30:00.123456+05:30';
        $instants = [];
        $dumps = [];

        foreach (['UTC', 'America/New_York', 'Asia/Tokyo'] as $timezone) {
            date_default_timezone_set($timezone);

            $parsed = Inline::parse($scalar, Yaml::PARSE_DATETIME_STRICT);
            $this->assertInstanceOf(\DateTimeImmutable::class, $parsed);
            $instants[$timezone] = $parsed->format('U.u');
            $dumps[$timezone] = Inline::dump($parsed, Yaml::DUMP_DATETIME_STRICT);
        }

        $this->assertSame('1705294800.123456', $instants['UTC']);
        $this->assertSame($instants['UTC'], $instants['America/New_York']);
        $this->assertSame($instants['UTC'], $instants['Asia/Tokyo']);
        $this->assertSame($dumps['UTC'], $dumps['America/New_York']);
        $this->assertSame($dumps['UTC'], $dumps['Asia/Tokyo']);
        $this->assertSame($scalar, $dumps['UTC']);
    }

    public function testStrictPolicyAppliesWhenParsingWholeDocuments()
    {
        $document = "with_zone: 2024-01-15T10:30:00.123456+05:30\nwithout_zone: 2024-01-15T10:30:00\nplain: 2024-01-15\n";

        $parsed = Yaml::parse($document, Yaml::PARSE_DATETIME_STRICT);

        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed['with_zone']);
        $this->assertSame(1705294800, $parsed['with_zone']->getTimestamp());
        $this->assertSame('2024-01-15T10:30:00', $parsed['without_zone']);
        $this->assertSame('2024-01-15', $parsed['plain']);
    }

    public function testDefaultFlagsBehaviorIsPreserved()
    {
        // Without the strict flags, the historical behavior is untouched:
        // timestamps become integers (or \DateTimeImmutable with PARSE_DATETIME).
        $this->assertSame(1705276800, Inline::parse('2024-01-15'));

        $dateTime = Inline::parse('2024-01-15', Yaml::PARSE_DATETIME);
        $this->assertInstanceOf(\DateTimeImmutable::class, $dateTime);

        $this->assertSame('2024-01-15T00:00:00+00:00', Inline::dump($dateTime));
    }
}
