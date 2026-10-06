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
use Symfony\Component\Yaml\DateScalarPolicy;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Inline;
use Symfony\Component\Yaml\Yaml;

/**
 * Run with: vendor/bin/phpunit Tests/DateScalarPolicyTest.php
 */
class DateScalarPolicyTest extends TestCase
{
    private string $defaultTimezone;

    protected function setUp(): void
    {
        $this->defaultTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);
    }

    /**
     * The four declared scalar shapes, with fixed date strings only.
     */
    public static function provideDeclaredDateScalars(): iterable
    {
        // scalar, expected instant expressed in UTC
        yield 'date-time with explicit offset' => ['2026-10-07T12:34:56+02:00', '2026-10-07T10:34:56.000000+00:00'];
        yield 'date-time with Z and microseconds' => ['2026-10-07T12:34:56.123456Z', '2026-10-07T12:34:56.123456+00:00'];
        yield 'date only denotes midnight UTC' => ['2026-10-07', '2026-10-07T00:00:00.000000+00:00'];
        yield 'negative offset crossing midnight' => ['2026-02-28T23:30:00-05:00', '2026-03-01T04:30:00.000000+00:00'];
    }

    #[DataProvider('provideDeclaredDateScalars')]
    public function testParseDeclaredScalars(string $scalar, string $expectedInstant)
    {
        $date = DateScalarPolicy::parse($scalar);

        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame($expectedInstant, $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'));
    }

    #[DataProvider('provideDeclaredDateScalars')]
    public function testMatchesDeclaredScalars(string $scalar, string $expectedInstant)
    {
        $this->assertTrue(DateScalarPolicy::matches($scalar));
    }

    public static function provideNonMatchingScalars(): iterable
    {
        yield 'missing timezone' => ['2026-10-07T12:34:56'];
        yield 'not fixed-width' => ['2026-10-7'];
        yield 'offset without minutes' => ['2026-10-07T12:34:56+02'];
        yield 'not a date at all' => ['hello'];
    }

    #[DataProvider('provideNonMatchingScalars')]
    public function testDoesNotMatchNonDeclaredScalars(string $scalar)
    {
        $this->assertFalse(DateScalarPolicy::matches($scalar));
    }

    public function testRoundTripKeepsSameInstant()
    {
        foreach (['2026-10-07T12:34:56+02:00', '2026-10-07T12:34:56.123456Z', '2026-10-07'] as $scalar) {
            $date = DateScalarPolicy::parse($scalar);
            $dumped = DateScalarPolicy::dump($date);

            $this->assertTrue(DateScalarPolicy::matches($dumped), \sprintf('The dumped scalar "%s" matches the declared schema.', $dumped));
            $this->assertSame($date->format('U.u'), DateScalarPolicy::parse($dumped)->format('U.u'));
        }

        // canonical scalars are dumped back identically
        $this->assertSame('2026-10-07T12:34:56+02:00', DateScalarPolicy::dump(DateScalarPolicy::parse('2026-10-07T12:34:56+02:00')));
        $this->assertSame('2026-10-07T12:34:56.123456+00:00', DateScalarPolicy::dump(DateScalarPolicy::parse('2026-10-07T12:34:56.123456Z')));
    }

    public function testMissingTimezoneIsRejected()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('no timezone offset');

        DateScalarPolicy::parse('2026-10-07T12:34:56');
    }

    public static function provideInvalidCalendarDates(): iterable
    {
        yield 'February 30th with time' => ['2026-02-30T00:00:00Z'];
        yield 'February 30th date only' => ['2026-02-30'];
        yield 'month 13' => ['2026-13-01T00:00:00Z'];
    }

    #[DataProvider('provideInvalidCalendarDates')]
    public function testInvalidCalendarDateIsRejected(string $scalar)
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('not a valid calendar date');

        DateScalarPolicy::parse($scalar);
    }

    public function testOverPrecisionIsRejected()
    {
        // 7 fractional digits exceed the declared microsecond precision policy
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('exceeds the maximum supported precision of 6 digits');

        DateScalarPolicy::parse('2026-10-07T12:34:56.1234567Z');
    }

    public function testLeapSecondIsRejected()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('not a valid time of day');

        DateScalarPolicy::parse('2026-10-07T23:59:60Z');
    }

    public function testMachineTimezoneChangesDoNotAffectParsing()
    {
        $expectedInstant = '2026-10-07T10:34:56.000000+00:00';
        $expectedDateOnly = '2026-10-07T00:00:00.000000+00:00';

        foreach (['UTC', 'Pacific/Kiritimati', 'America/New_York', 'Asia/Shanghai'] as $timezone) {
            date_default_timezone_set($timezone);
            $this->assertSame($timezone, date_default_timezone_get(), 'Sanity check: the machine timezone really changed.');

            $date = DateScalarPolicy::parse('2026-10-07T12:34:56+02:00');
            $this->assertSame($expectedInstant, $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'));

            // date-only scalars denote midnight UTC, never local midnight
            $dateOnly = DateScalarPolicy::parse('2026-10-07');
            $this->assertSame($expectedDateOnly, $dateOnly->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'));
        }
    }

    public function testParseDatetimeStrictFlag()
    {
        $date = Yaml::parse('2026-10-07T12:34:56+02:00', Yaml::PARSE_DATETIME_STRICT);

        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame('2026-10-07T10:34:56.000000+00:00', $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'));

        // the block parser goes through the same policy
        $parsed = Yaml::parse("d: 2026-10-07T12:34:56.123456Z\n", Yaml::PARSE_DATETIME_STRICT);
        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed['d']);
        $this->assertSame('2026-10-07T12:34:56.123456+00:00', $parsed['d']->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'));
    }

    public function testParseDatetimeStrictFlagRejectsFebruary30th()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('not a valid calendar date');

        Yaml::parse('2026-02-30T00:00:00Z', Yaml::PARSE_DATETIME_STRICT);
    }

    public function testParseDatetimeStrictFlagRejectsMissingTimezone()
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('no timezone offset');

        Yaml::parse('2026-10-07T12:34:56', Yaml::PARSE_DATETIME_STRICT);
    }

    public function testRegularParseFlagsBehaviorIsPreserved()
    {
        // without any flag, a timestamp scalar still evaluates to its Unix timestamp
        $this->assertSame(1791369296, Yaml::parse('2026-10-07T12:34:56+02:00'));

        // PARSE_DATETIME keeps its lenient behavior (missing timezone accepted)
        $this->assertInstanceOf(\DateTimeImmutable::class, Yaml::parse('2026-10-07T12:34:56', Yaml::PARSE_DATETIME));

        // scalars that are not declared dates stay plain strings, even with the strict flag
        $this->assertSame('2026-10-07 12:34', Yaml::parse('2026-10-07 12:34', Yaml::PARSE_DATETIME_STRICT));
        $this->assertSame('not-a-date', Yaml::parse('not-a-date', Yaml::PARSE_DATETIME_STRICT));

        // quoted scalars stay plain strings
        $this->assertSame('2026-10-07', Yaml::parse("'2026-10-07'", Yaml::PARSE_DATETIME_STRICT));
    }

    public function testDumpDatetimeStrictFlagRoundTrips()
    {
        $date = new \DateTimeImmutable('2026-10-07T12:34:56.123456+02:00');

        $yaml = Yaml::dump(['d' => $date], 2, 4, Yaml::DUMP_DATETIME_STRICT);
        $this->assertSame('d: 2026-10-07T12:34:56.123456+02:00', rtrim($yaml, "\n"));

        $parsed = Yaml::parse($yaml, Yaml::PARSE_DATETIME_STRICT);
        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed['d']);
        $this->assertSame($date->format('U.u'), $parsed['d']->format('U.u'));

        // Inline::dump honors the flag as well
        $this->assertSame('2026-10-07T12:34:56.123456+02:00', Inline::dump($date, Yaml::DUMP_DATETIME_STRICT));

        // default dump behavior is unchanged
        $this->assertSame('2026-10-07T12:34:56.123456+02:00', Inline::dump($date));
    }
}
