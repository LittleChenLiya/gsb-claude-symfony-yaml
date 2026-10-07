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

use Symfony\Component\Yaml\Exception\DumpException;
use Symfony\Component\Yaml\Tag\TaggedValue;

/**
 * Dumper dumps PHP variables to YAML strings.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 *
 * @final
 */
class Dumper
{
    /**
     * Ids of the array references currently being dumped along the key path,
     * mapped to the key path at which their array was entered.
     *
     * @var array<string, string>
     */
    private array $refsBeingDumped = [];

    /**
     * The key path of the value currently being dumped.
     *
     * @var list<string|int>
     */
    private array $currentPath = [];

    /**
     * @param int $indentation The amount of spaces to use for indentation of nested nodes
     */
    public function __construct(private int $indentation = 4)
    {
        if ($indentation < 1) {
            throw new \InvalidArgumentException('The indentation must be greater than zero.');
        }
    }

    /**
     * Dumps a PHP value to YAML.
     *
     * @param mixed                     $input  The PHP value
     * @param int                       $inline The level where you switch to inline YAML
     * @param int                       $indent The level of indentation (used internally)
     * @param int-mask-of<Yaml::DUMP_*> $flags  A bit field of Yaml::DUMP_* constants to customize the dumped YAML string
     */
    public function dump(mixed $input, int $inline = 0, int $indent = 0, int $flags = 0): string
    {
        if ($flags & Yaml::DUMP_NULL_AS_EMPTY && $flags & Yaml::DUMP_NULL_AS_TILDE) {
            throw new \InvalidArgumentException('The Yaml::DUMP_NULL_AS_EMPTY and Yaml::DUMP_NULL_AS_TILDE flags cannot be used together.');
        }

        $this->refsBeingDumped = [];
        $this->currentPath = [];

        return $this->doDump($input, $inline, $indent, $flags);
    }

    private function doDump(mixed $input, int $inline = 0, int $indent = 0, int $flags = 0, int $nestingLevel = 0): string
    {
        $output = '';
        $prefix = $indent ? str_repeat(' ', $indent) : '';
        $dumpObjectAsInlineMap = true;

        if (Yaml::DUMP_OBJECT_AS_MAP & $flags && ($input instanceof \ArrayObject || $input instanceof \stdClass)) {
            $dumpObjectAsInlineMap = !(array) $input;
        }

        if ($inline <= 0 || (!\is_array($input) && !$input instanceof TaggedValue && $dumpObjectAsInlineMap) || !$input) {
            // Inline::dump() traverses the remaining sub-tree on its own, so
            // guard it against circular references before delegating to it.
            $inlineValue = $input instanceof TaggedValue ? $input->getValue() : $input;

            if (\is_array($inlineValue) && $inlineValue) {
                $this->detectCircularReference($inlineValue, $this->currentPath);
            }

            $output .= $prefix.Inline::dump($input, $flags, 0 === $nestingLevel);
        } elseif ($input instanceof TaggedValue) {
            $output .= $this->dumpTaggedValue($input, $inline, $indent, $flags, $prefix, $nestingLevel);
        } else {
            $dumpAsMap = Inline::isHash($input);
            $compactNestedMapping = Yaml::DUMP_COMPACT_NESTED_MAPPING & $flags && !$dumpAsMap;

            foreach ($input as $key => $value) {
                if ('' !== $output && "\n" !== $output[-1]) {
                    $output .= "\n";
                }

                if (\is_int($key) && Yaml::DUMP_NUMERIC_KEY_AS_STRING & $flags) {
                    $key = (string) $key;
                }

                $arrayRefId = null;

                if (\is_array($input) && \is_array($value) && null !== $arrayRef = \ReflectionReference::fromArrayElement($input, $key)) {
                    if (isset($this->refsBeingDumped[$arrayRefId = $arrayRef->getId()])) {
                        throw $this->createCircularReferenceException([...$this->currentPath, $key], $this->refsBeingDumped[$arrayRefId]);
                    }
                }

                if (Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK & $flags && \is_string($value) && str_contains($value, "\n") && !str_contains($value, "\r")) {
                    $output .= \sprintf('%s%s%s |%s%s', $prefix, $dumpAsMap ? Inline::dump($key, $flags).':' : '-', '', $this->getBlockIndentationIndicator($value), $this->getBlockChompingIndicator($value));
                    $output .= $this->dumpBlockScalarLines($value, $prefix);

                    continue;
                }

                if ($value instanceof TaggedValue) {
                    $output .= \sprintf('%s%s !%s', $prefix, $dumpAsMap ? Inline::dump($key, $flags).':' : '-', $value->getTag());

                    if (Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK & $flags && \is_string($value->getValue()) && str_contains($value->getValue(), "\n") && !str_contains($value->getValue(), "\r")) {
                        $output .= \sprintf(' |%s%s', $this->getBlockIndentationIndicator($value->getValue()), $this->getBlockChompingIndicator($value->getValue()));
                        $output .= $this->dumpBlockScalarLines($value->getValue(), $prefix);

                        continue;
                    }

                    if ($inline - 1 <= 0 || null === $value->getValue() || \is_scalar($value->getValue())) {
                        $output .= ' '.$this->doDump($value->getValue(), $inline - 1, 0, $flags, $nestingLevel + 1)."\n";
                    } else {
                        $output .= "\n";
                        $output .= $this->doDump($value->getValue(), $inline - 1, $dumpAsMap ? $indent + $this->indentation : $indent + 2, $flags, $nestingLevel + 1);
                    }

                    continue;
                }

                $dumpObjectAsInlineMap = true;

                if (Yaml::DUMP_OBJECT_AS_MAP & $flags && ($value instanceof \ArrayObject || $value instanceof \stdClass)) {
                    $dumpObjectAsInlineMap = !(array) $value;
                }

                $willBeInlined = $inline - 1 <= 0 || !\is_array($value) && $dumpObjectAsInlineMap || !$value;

                if (\is_array($value)) {
                    $this->currentPath[] = $key;

                    if (null !== $arrayRefId) {
                        $this->refsBeingDumped[$arrayRefId] = implode(' -> ', $this->currentPath);
                    }
                }

                try {
                    $output .= \sprintf('%s%s%s%s',
                        $prefix,
                        $dumpAsMap ? Inline::dump($key, $flags).':' : '-',
                        $willBeInlined || ($compactNestedMapping && \is_array($value) && Inline::isHash($value)) ? ' ' : "\n",
                        $compactNestedMapping && \is_array($value) && Inline::isHash($value) ? substr($this->doDump($value, $inline - 1, $indent + 2, $flags, $nestingLevel + 1), $indent + 2) : $this->doDump($value, $inline - 1, $willBeInlined ? 0 : $indent + $this->indentation, $flags, $nestingLevel + 1)
                    ).($willBeInlined ? "\n" : '');
                } finally {
                    if (\is_array($value)) {
                        array_pop($this->currentPath);

                        if (null !== $arrayRefId) {
                            unset($this->refsBeingDumped[$arrayRefId]);
                        }
                    }
                }
            }
        }

        return $output;
    }

    private function dumpTaggedValue(TaggedValue $value, int $inline, int $indent, int $flags, string $prefix, int $nestingLevel): string
    {
        $output = \sprintf('%s!%s', $prefix ? $prefix.' ' : '', $value->getTag());

        if (Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK & $flags && \is_string($value->getValue()) && str_contains($value->getValue(), "\n") && !str_contains($value->getValue(), "\r")) {
            $output .= \sprintf(' |%s%s', $this->getBlockIndentationIndicator($value->getValue()), $this->getBlockChompingIndicator($value->getValue()));
            $output .= $this->dumpBlockScalarLines($value->getValue(), $prefix);

            return $output;
        }

        if ($inline - 1 <= 0 || null === $value->getValue() || \is_scalar($value->getValue())) {
            return $output.' '.$this->doDump($value->getValue(), $inline - 1, 0, $flags, $nestingLevel + 1)."\n";
        }

        return $output."\n".$this->doDump($value->getValue(), $inline - 1, $indent, $flags, $nestingLevel + 1);
    }

    /**
     * Detects circular references in an array that is about to be dumped inline.
     *
     * Only arrays referenced from the key path currently being dumped are
     * tracked: values shared between several non-circular key paths are not
     * mistaken for circular references and keep being dumped as usual. The
     * inspected array is only read, never modified, and nothing is serialized
     * while detecting.
     *
     * @param list<string|int> $path The key path at which the array is being dumped
     *
     * @throws DumpException When a circular reference is detected
     */
    private function detectCircularReference(array $input, array $path): void
    {
        foreach ($input as $key => $value) {
            if ($value instanceof TaggedValue) {
                if (\is_array($taggedValue = $value->getValue()) && $taggedValue) {
                    $this->detectCircularReference($taggedValue, [...$path, $key]);
                }

                continue;
            }

            if (!\is_array($value) || !$value) {
                continue;
            }

            $keyPath = [...$path, $key];

            if (null === $ref = \ReflectionReference::fromArrayElement($input, $key)) {
                // an array that is not referenced cannot be part of a circular reference
                $this->detectCircularReference($value, $keyPath);

                continue;
            }

            if (isset($this->refsBeingDumped[$refId = $ref->getId()])) {
                throw $this->createCircularReferenceException($keyPath, $this->refsBeingDumped[$refId]);
            }

            $this->refsBeingDumped[$refId] = implode(' -> ', $keyPath);

            try {
                $this->detectCircularReference($value, $keyPath);
            } finally {
                unset($this->refsBeingDumped[$refId]);
            }
        }
    }

    /**
     * @param list<string|int> $keyPath
     */
    private function createCircularReferenceException(array $keyPath, string $referencedPath): DumpException
    {
        return new DumpException(\sprintf('A circular reference has been detected while dumping the key path "%s": the value refers back to the array at key path "%s".', implode(' -> ', $keyPath), $referencedPath));
    }

    private function getBlockChompingIndicator(string $value): string
    {
        // a block made of line breaks only has no content, so only the keep indicator preserves them
        if ("\n" === $value || isset($value[-2]) && "\n" === $value[-2] && "\n" === $value[-1]) {
            return '+';
        }

        if ("\n" === $value[-1]) {
            return '';
        }

        return '-';
    }

    private function dumpBlockScalarLines(string $value, string $prefix): string
    {
        $output = '';

        foreach (explode("\n", $value) as $row) {
            if ('' === $row) {
                $output .= "\n";
            } else {
                $output .= \sprintf("\n%s%s%s", $prefix, str_repeat(' ', $this->indentation), $row);
            }
        }

        return $output;
    }

    private function getBlockIndentationIndicator(string $value): string
    {
        $lines = explode("\n", $value);

        // The spec requires a block indentation indicator when the first line that is not empty starts with a space, even if that line holds only spaces
        // http://www.yaml.org/spec/1.2/spec.html#id2793979
        foreach ($lines as $line) {
            if ('' !== $line) {
                return str_starts_with($line, ' ') ? (string) $this->indentation : '';
            }
        }

        return '';
    }
}
