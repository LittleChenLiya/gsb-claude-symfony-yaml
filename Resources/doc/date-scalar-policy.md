# Explicit date scalar policy

The flags `Yaml::PARSE_DATETIME_STRICT` and `Yaml::DUMP_DATETIME_STRICT` enable
an explicit, deterministic policy for date-like scalars. A scalar is never
converted just because it "looks like a date": it is converted only when it
matches the declared schema (a YAML timestamp with an explicit timezone and at
most microsecond precision). Otherwise it is either kept as the original
string or rejected with a dedicated `ParseException`.

## Policy rules

* **Declared schema only**: only scalars matching the YAML timestamp grammar
  *and* carrying an explicit timezone (`Z` or a numeric offset such as
  `+05:30`) are converted, always to a `\DateTimeImmutable` object. Without
  the flag, the historical behavior (integer timestamp, or
  `\DateTimeImmutable` with `PARSE_DATETIME`) is unchanged.
* **Missing timezone**: a date-like scalar without a timezone is kept as the
  original string. The machine's default timezone
  (`date_default_timezone_get()` / `date.timezone`) is never consulted, so
  changing the machine timezone cannot change the result.
* **Precision**: fractional seconds are limited to 6 digits (microseconds).
  More digits are rejected explicitly instead of being silently truncated.
* **Invalid calendar dates**: impossible dates (e.g. February 30) and
  out-of-range time or offset components are rejected explicitly instead of
  being silently normalized to a different instant.
* **Dump round-trip**: with `DUMP_DATETIME_STRICT`, `\DateTimeInterface`
  instances are always dumped as `Y-m-d\TH:i:s.uP` (microseconds + numeric
  offset), so parsing the output back with `PARSE_DATETIME_STRICT` yields the
  very same instant. Strings that look like dates stay quoted strings.

## Four scalar examples

| Scalar                                  | Result under `PARSE_DATETIME_STRICT`                                  |
|-----------------------------------------|-----------------------------------------------------------------------|
| `2024-01-15T10:30:00.123456+05:30`      | `\DateTimeImmutable` (instant `1705294800.123456`); dumps back identically |
| `2024-01-15T10:30:00`                   | kept as the string `'2024-01-15T10:30:00'` (no timezone, no conversion)  |
| `2024-02-30T10:30:00Z`                  | `ParseException`: not a valid calendar date                              |
| `2024-01-15T10:30:00.123456789Z`        | `ParseException`: exceeds microsecond precision                          |

```php
use Symfony\Component\Yaml\Yaml;

$value = Yaml::parse('2024-01-15T10:30:00.123456+05:30', Yaml::PARSE_DATETIME_STRICT);
// $value instanceof \DateTimeImmutable, independent of the machine timezone

$yaml = Yaml::dump($value, 2, 4, Yaml::DUMP_DATETIME_STRICT);
// "2024-01-15T10:30:00.123456+05:30" — parses back to the same instant
```

## Running the tests

```
vendor/bin/phpunit Tests/DateTimeStrictPolicyTest.php
```

(or, with a PHPUnit phar and no vendor directory:
`php phpunit.phar --no-configuration --bootstrap <psr-4-bootstrap> Tests/DateTimeStrictPolicyTest.php`)
