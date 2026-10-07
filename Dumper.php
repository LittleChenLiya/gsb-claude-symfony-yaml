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

                if (\is_string($value) && null !== $blockScalarStyle = $this->getBlockScalarStyle($value, $flags)) {
                    $output .= \sprintf('%s%s%s %s%s%s', $prefix, $dumpAsMap ? Inline::dump($key, $flags).':' : '-', '', $blockScalarStyle, $this->getBlockIndentationIndicator($value), $this->getBlockChompingIndicator($value));
                    $output .= $this->dumpBlockScalarLines($value, $prefix, $blockScalarStyle);

                    continue;
                }

                if ($value instanceof TaggedValue) {
                    $output .= \sprintf('%s%s !%s', $prefix, $dumpAsMap ? Inline::dump($key, $flags).':' : '-', $value->getTag());

                    if (\is_string($value->getValue()) && null !== $blockScalarStyle = $this->getBlockScalarStyle($value->getValue(), $flags)) {
                        $output .= \sprintf(' %s%s%s', $blockScalarStyle, $this->getBlockIndentationIndicator($value->getValue()), $this->getBlockChompingIndicator($value->getValue()));
                        $output .= $this->dumpBlockScalarLines($value->getValue(), $prefix, $blockScalarStyle);

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

                $output .= \sprintf('%s%s%s%s',
                    $prefix,
                    $dumpAsMap ? Inline::dump($key, $flags).':' : '-',
                    $willBeInlined || ($compactNestedMapping && \is_array($value) && Inline::isHash($value)) ? ' ' : "\n",
                    $compactNestedMapping && \is_array($value) && Inline::isHash($value) ? substr($this->doDump($value, $inline - 1, $indent + 2, $flags, $nestingLevel + 1), $indent + 2) : $this->doDump($value, $inline - 1, $willBeInlined ? 0 : $indent + $this->indentation, $flags, $nestingLevel + 1)
                ).($willBeInlined ? "\n" : '');
            }
        }

        return $output;
    }

    private function dumpTaggedValue(TaggedValue $value, int $inline, int $indent, int $flags, string $prefix, int $nestingLevel): string
    {
        $output = \sprintf('%s!%s', $prefix ? $prefix.' ' : '', $value->getTag());

        if (\is_string($value->getValue()) && null !== $blockScalarStyle = $this->getBlockScalarStyle($value->getValue(), $flags)) {
            $output .= \sprintf(' %s%s%s', $blockScalarStyle, $this->getBlockIndentationIndicator($value->getValue()), $this->getBlockChompingIndicator($value->getValue()));
            $output .= $this->dumpBlockScalarLines($value->getValue(), $prefix, $blockScalarStyle);

            return $output;
        }

        if ($inline - 1 <= 0 || null === $value->getValue() || \is_scalar($value->getValue())) {
            return $output.' '.$this->doDump($value->getValue(), $inline - 1, 0, $flags, $nestingLevel + 1)."\n";
        }

        return $output."\n".$this->doDump($value->getValue(), $inline - 1, $indent, $flags, $nestingLevel + 1);
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

    /**
     * Returns the block scalar style to use for a multi-line string, or null when
     * the value should not be dumped as a block scalar at all.
     *
     * The folded style (">") is only selected when every line break of the value is
     * preserved by the YAML folding rules, so that parsing the dumped output yields
     * the very same string; otherwise the method explicitly falls back to the
     * literal style ("|").
     */
    private function getBlockScalarStyle(string $value, int $flags): ?string
    {
        if (!($flags & (Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_MULTI_LINE_FOLDED_BLOCK)) || !str_contains($value, "\n") || str_contains($value, "\r")) {
            return null;
        }

        if ($flags & Yaml::DUMP_MULTI_LINE_FOLDED_BLOCK && $this->isFoldableBlockScalar($value)) {
            return '>';
        }

        return '|';
    }

    /**
     * A multi-line string can be dumped with the folded style without data loss when
     * none of its line breaks would be folded into a space. A line break is kept by
     * the folding rules when it is adjacent to an empty line or to a more-indented
     * line, so only two consecutive "plain" lines (not empty, not more-indented)
     * make the value unsuitable for the folded style.
     */
    private function isFoldableBlockScalar(string $value): bool
    {
        $previousLineIsPlain = false;

        foreach (explode("\n", $value) as $line) {
            $lineIsPlain = '' !== $line && ' ' !== $line[0] && "\t" !== $line[0];

            if ($lineIsPlain && $previousLineIsPlain) {
                return false;
            }

            $previousLineIsPlain = $lineIsPlain;
        }

        return true;
    }

    private function dumpBlockScalarLines(string $value, string $prefix, string $style = '|'): string
    {
        $rows = '>' === $style ? $this->getFoldedBlockScalarRows($value) : explode("\n", $value);
        $output = '';

        foreach ($rows as $row) {
            if ('' === $row) {
                $output .= "\n";
            } else {
                $output .= \sprintf("\n%s%s%s", $prefix, str_repeat(' ', $this->indentation), $row);
            }
        }

        return $output;
    }

    /**
     * Computes the content lines of a folded block scalar so that parsing them
     * yields the original string back:
     *
     *  - each line break of the value must be materialized, as a folded line break
     *    between two plain lines would become a space;
     *  - a more-indented line keeps the line breaks around it, so it is emitted as
     *    is and also preserves the line break that precedes it;
     *  - every other line break is materialized by an empty line, which the folding
     *    rules keep as a line feed.
     *
     * @return string[]
     */
    private function getFoldedBlockScalarRows(string $value): array
    {
        $rows = [];
        $pendingLineBreaks = 0;
        $contentSeen = false;
        $previousLineIndented = false;

        foreach (explode("\n", $value) as $i => $line) {
            if ($i > 0) {
                ++$pendingLineBreaks;
            }

            if ('' === $line) {
                // empty lines are materialized through the pending line breaks and,
                // like in the parser, do not reset the more-indented line state
                continue;
            }

            $lineIsIndented = \in_array($line[0], [' ', "\t"], true);
            // a more-indented line keeps the preceding line break, unless it is the
            // very first content line of the block; a plain line only keeps it when
            // the previous content line was more-indented
            $keptLineBreaks = $lineIsIndented ? ($contentSeen ? 1 : 0) : ($previousLineIndented ? 1 : 0);

            while ($pendingLineBreaks > $keptLineBreaks) {
                $rows[] = '';
                --$pendingLineBreaks;
            }

            $pendingLineBreaks = 0;
            $rows[] = $line;
            $contentSeen = true;
            $previousLineIndented = $lineIsIndented;
        }

        while ($pendingLineBreaks-- > 0) {
            $rows[] = '';
        }

        if (!$contentSeen) {
            // a block holding only empty lines needs one extra empty line, as the
            // first one is consumed while the indentation of the block is detected
            $rows[] = '';
        }

        return $rows;
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
