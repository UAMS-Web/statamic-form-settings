<?php

declare(strict_types=1);

use UAMSWeb\FormSettings\Rules\LikertRatingOnScale;

covers(LikertRatingOnScale::class);

/**
 * Behavior of the `LikertRatingOnScale` rule: a question without rows takes one
 * rating, a question with rows takes one rating per listed row, every rating
 * given must be a value on the scale (compared as strings, so `3` and `'3'`
 * match), a blank rating is left to `required`, and a row the question does not
 * list fails before its rating is read.
 *
 * @command  vendor/bin/pest tests/Integration/Rules/LikertRatingOnScaleTest.php
 */

/**
 * Run the rule and return every message it failed with.
 *
 * @param  list<int|string>  $scale  The scale values.
 * @param  list<string>  $rows  The question's rows, if any.
 * @return list<mixed>
 */
function likertRatingMessages(array $scale, array $rows, mixed $value): array
{
    $messages = [];

    new LikertRatingOnScale($scale, $rows)->validate('rating', $value, function (mixed $message) use (&$messages): void {
        $messages[] = $message;
    });

    return $messages;
}

it('accepts a single rating on the scale', function (mixed $value): void {
    expect(likertRatingMessages([1, 2, 3, 4, 5], [], $value))->toBe([]);
})->with([
    'integer' => [3],
    'string' => ['3'],
    'blank' => [''],
    'null' => [null],
]);

it('accepts a string scale compared with an integer rating', function (): void {
    expect(likertRatingMessages(['1', '2', '3'], [], 2))->toBe([]);
});

it('rejects a single rating off the scale', function (mixed $value): void {
    expect(likertRatingMessages([1, 2, 3, 4, 5], [], $value))->toBe([__('statamic-form-settings::validation.likert_scale.not_on_scale')]);
})->with([
    'above' => [6],
    'zero' => ['0'],
    'padded' => [' 3'],
    'float form' => ['3.0'],
    'a list' => [[3]],
]);

it('accepts a rating per listed row, leaving blank rows to required', function (): void {
    expect(likertRatingMessages(['agree', 'neutral', 'disagree'], ['speed', 'clarity'], [
        'speed' => 'agree',
        'clarity' => null,
    ]))->toBe([])
        ->and(likertRatingMessages(['agree'], ['speed', 'clarity'], ['clarity' => '']))->toBe([]);
});

it('accepts integer row keys matched against string rows', function (): void {
    expect(likertRatingMessages([1, 2], ['0', '1'], [0 => 1, 1 => 2]))->toBe([]);
});

it('rejects a row rating off the scale', function (mixed $rating): void {
    expect(likertRatingMessages(['agree', 'disagree'], ['speed', 'clarity'], ['speed' => 'agree', 'clarity' => $rating]))
        ->toBe([__('statamic-form-settings::validation.likert_scale.not_on_scale')]);
})->with([
    'unknown value' => ['strongly agree'],
    'a list' => [['agree']],
    'an object' => [(object) []],
]);

it('rejects a row the question does not list, before reading its rating', function (): void {
    expect(likertRatingMessages(['agree'], ['speed'], ['speed' => 'agree', 'price' => 'not on the scale']))
        ->toBe([__('statamic-form-settings::validation.likert_scale.unknown_row')]);
});

it('rejects a value that is not a list of row ratings for a question with rows', function (mixed $value): void {
    expect(likertRatingMessages(['agree'], ['speed'], $value))->toBe([__('statamic-form-settings::validation.likert_scale.not_on_scale')]);
})->with([
    'a single rating' => ['agree'],
    'null' => [null],
]);

it('reports only the first failing row', function (): void {
    expect(likertRatingMessages(['agree'], ['speed', 'clarity'], ['speed' => 'nope', 'clarity' => 'also nope']))
        ->toBe([__('statamic-form-settings::validation.likert_scale.not_on_scale')]);
});

it('checks the rows after a blank one', function (mixed $blank): void {
    expect(likertRatingMessages(['agree'], ['speed', 'clarity'], ['speed' => $blank, 'clarity' => 'nope']))
        ->toBe([__('statamic-form-settings::validation.likert_scale.not_on_scale')]);
})->with([
    'null' => [null],
    'empty string' => [''],
]);

it('matches row keys against the listed rows strictly', function (): void {
    // The integer key 1 reads as '1', which a loose comparison would equate with '01'.
    expect(likertRatingMessages(['agree'], ['01'], [1 => 'agree']))
        ->toBe([__('statamic-form-settings::validation.likert_scale.unknown_row')]);
});
