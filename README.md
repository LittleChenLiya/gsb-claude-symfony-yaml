Yaml Component
==============

The Yaml component loads and dumps YAML files.

Binary scalars
--------------

The component provides an explicit binary scalar strategy through the
`Symfony\Component\Yaml\Tag\BinaryScalar` value object. The tag (`!!binary`),
the raw base64 text and the decoded bytes are kept separate, so binary
payloads (NUL bytes and non-UTF-8 sequences included) are never rewritten as
plain UTF-8 strings. Base64 whitespace and padding are handled per RFC 4648:
folding line breaks never change the decoded bytes, invalid encodings are
rejected with the position of the offending character, and oversized payloads
are rejected before decoding (no large allocation). Binary handling is always
explicit: parsing a plain string never enables it, and custom object tags are
never executed.

```php
use Symfony\Component\Yaml\Tag\BinaryScalar;
use Symfony\Component\Yaml\Yaml;

// three scalar examples:
BinaryScalar::fromBase64('AAECAwQ=')->getBytes();   // "\x00\x01\x02\x03\x04" (NUL bytes)
BinaryScalar::fromBase64('+/8=')->getBytes();       // "\xFB\xFF" (non-UTF-8)
BinaryScalar::fromBase64("AAEC\nAwQ=")->getBytes(); // folded lines, same bytes

// dump and parse back with the same strategy (opt-in via PARSE_BINARY_SCALAR)
$yaml = Yaml::dump(['payload' => BinaryScalar::fromBytes("\x00\xFF")]);
$parsed = Yaml::parse($yaml, Yaml::PARSE_BINARY_SCALAR);
$parsed['payload']->getBytes(); // "\x00\xFF"
```

Run the related tests with:

```
php vendor/bin/phpunit Tests/Tag/BinaryScalarTest.php
```

Sponsor
-------

This package is looking for a [backer][1].

Help Symfony by [sponsoring][3] its development!

Resources
---------

 * [Documentation](https://symfony.com/doc/current/components/yaml.html)
 * [Contributing](https://symfony.com/doc/current/contributing/index.html)
 * [Report issues](https://github.com/symfony/symfony/issues) and
   [send Pull Requests](https://github.com/symfony/symfony/pulls)
   in the [main Symfony repository](https://github.com/symfony/symfony)

[1]: https://symfony.com/backers
[3]: https://symfony.com/sponsor
