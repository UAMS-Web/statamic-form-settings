<?php

declare(strict_types=1);

if (! function_exists('positionOf')) {
    /**
     * Locate a substring in rendered output, failing by name when it is absent.
     *
     * Ordering assertions ("the first award renders before the second") are
     * written as two `strpos()` calls compared with `toBeLessThan()`, but
     * `strpos()` returns `int|false`, so both operands need narrowing. The suites
     * did that with `assert($pos !== false)`, an instrument that `zend.assertions=-1`
     * compiles out and that reports nothing useful when it trips (UAMS-Web/uams-statamic-archive#1733, copied from UAMS-Web/uams-statamic with the tests that use it).
     *
     * Returning a plain `int` narrows both sides at once by control flow, and an
     * absent needle now fails with the string it could not find rather than with a
     * bare assertion, or, worse, with `toBeLessThan(false)` quietly comparing
     * against `0`.
     *
     * ```php
     * expect(positionOf($html, 'first'))->toBeLessThan(positionOf($html, 'second'));
     * ```
     *
     * @param  string  $haystack  The rendered output to search.
     * @param  string  $needle  The substring whose position is wanted.
     * @return int Byte offset of the first occurrence.
     */
    function positionOf(string $haystack, string $needle): int
    {
        $position = strpos($haystack, $needle);

        if ($position === false) {
            throw new RuntimeException(sprintf('Expected the rendered output to contain "%s".', $needle));
        }

        return $position;
    }
}
