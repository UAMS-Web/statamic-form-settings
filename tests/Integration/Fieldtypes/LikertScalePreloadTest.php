<?php

declare(strict_types=1);

use Statamic\Fields\Field;
use UAMSWeb\FormSettings\Fieldtypes\LikertScale;

covers(LikertScale::class);

/**
 * The LikertScale fieldtype's preload() feeds the Vue component and the front-end
 * template. It must (UAMS-Web/uams-statamic-archive#194): order options by `display_order`, apply `reverse_scale`
 * to the numeric mapping, append the zero-point option with its CONFIGURED label
 * (the previous code hardcoded "Not Applicable"), and expose the matrix row labels.
 *
 * @command  vendor/bin/pest tests/Integration/Fieldtypes/LikertScalePreloadTest.php
 */

/**
 * The preload() output for a LikertScale field with the given config.
 *
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function likertPreload(array $config): array
{
    $fieldtype = new LikertScale;
    $fieldtype->setField(new Field('q', array_merge(['type' => 'likert_scale'], $config)));

    return $fieldtype->preload();
}

it('orders options low-to-high by default (asc)', function (): void {
    /** @var list<array{value: int|string, label: string}> $options */
    $options = likertPreload(['scale_type' => 'agreement', 'number_of_points' => 5])['options'];

    expect(array_column($options, 'value'))->toBe([1, 2, 3, 4, 5]);
    expect($options[0]['label'])->toBe('Strongly Disagree');
    expect($options[4]['label'])->toBe('Strongly Agree');
});

it('orders options high-to-low when display_order is desc', function (): void {
    /** @var list<array{value: int|string, label: string}> $options */
    $options = likertPreload([
        'scale_type' => 'agreement', 'number_of_points' => 5, 'display_order' => 'desc',
    ])['options'];

    expect(array_column($options, 'value'))->toBe([5, 4, 3, 2, 1]);
    expect($options[0]['label'])->toBe('Strongly Agree');
    expect($options[4]['label'])->toBe('Strongly Disagree');
});

it('reverses the numeric mapping when reverse_scale is on (label keeps position, value flips)', function (): void {
    /** @var list<array{value: int|string, label: string}> $options */
    $options = likertPreload([
        'scale_type' => 'agreement', 'number_of_points' => 5, 'reverse_scale' => true,
    ])['options'];

    // Display order still asc (Strongly Disagree first), but its value is now the highest.
    expect($options[0]['label'])->toBe('Strongly Disagree');
    expect($options[0]['value'])->toBe(5);
    expect($options[4]['label'])->toBe('Strongly Agree');
    expect($options[4]['value'])->toBe(1);
});

it('appends the zero-point option with its configured label', function (): void {
    /** @var non-empty-list<array{value: int|string, label: string}> $options */
    $options = likertPreload([
        'scale_type' => 'agreement', 'number_of_points' => 5,
        'enable_not_applicable' => true, 'not_applicable_label' => 'Does Not Apply',
    ])['options'];

    expect(end($options))->toBe(['value' => 'not_applicable', 'label' => 'Does Not Apply']);
});

it('falls back to the default zero-point label when none is configured', function (): void {
    /** @var non-empty-list<array{value: int|string, label: string}> $options */
    $options = likertPreload([
        'scale_type' => 'agreement', 'number_of_points' => 5, 'enable_not_applicable' => true,
    ])['options'];

    expect(end($options)['label'])->toBe('Not Applicable');
});

it('does not append a zero-point option when disabled', function (): void {
    /** @var list<array{value: int|string, label: string}> $options */
    $options = likertPreload(['scale_type' => 'agreement', 'number_of_points' => 5])['options'];

    expect(collect($options)->pluck('value')->all())->not->toContain('not_applicable');
});

it('exposes matrix row labels only when multiple rows are enabled', function (): void {
    $off = likertPreload(['scale_type' => 'agreement', 'number_of_points' => 5]);
    expect($off['enable_multiple_rows'])->toBeFalse();
    expect($off['multiple_rows'])->toBe([]);

    $on = likertPreload([
        'scale_type' => 'agreement', 'number_of_points' => 5,
        'enable_multiple_rows' => true, 'multiple_rows' => ['Cleanliness', '', 'Staff'],
    ]);
    expect($on['enable_multiple_rows'])->toBeTrue();
    expect($on['multiple_rows'])->toBe(['Cleanliness', 'Staff']); // blanks filtered out
});

it('exposes the inline flag', function (): void {
    expect(likertPreload([
        'scale_type' => 'agreement', 'number_of_points' => 5, 'inline' => false,
    ])['inline'])->toBeFalse();
});

it('exposes a full non-empty scale for every scale type at every selectable point count', function (string $scaleType, int $points): void {
    // Every (scale_type, number_of_points) pair the blueprint config offers must
    // yield a usable scale. A gap (e.g. experience/likelihood once lacked a 5-point
    // set — the default) renders an empty, unanswerable radiogroup on public forms.
    $options = likertPreload(['scale_type' => $scaleType, 'number_of_points' => $points])['options'];

    expect($options)->toHaveCount($points);
})->with(function (): array {
    $cases = [];
    foreach (['agreement', 'experience', 'frequency', 'frequency_no_always', 'likelihood', 'quality', 'satisfaction'] as $type) {
        foreach (range(2, 10) as $points) {
            $cases["{$type} @ {$points}"] = [$type, $points];
        }
    }

    return $cases;
});

it('normalizes matrix row labels: strips brackets, trims, drops blanks, de-duplicates', function (): void {
    // The row label is BOTH the display legend and the name="{handle}[label]" submit
    // key, so it must survive PHP array-name parsing (no []) and stay unique (UAMS-Web/uams-statamic-archive#194).
    $rows = likertPreload([
        'scale_type' => 'agreement', 'number_of_points' => 5,
        'enable_multiple_rows' => true,
        'multiple_rows' => ['  Wait [min]  ', 'Cleanliness', 'Cleanliness', '   ', 'Staff'],
    ])['multiple_rows'];

    expect($rows)->toBe(['Wait min', 'Cleanliness', 'Staff']);
});

it('normalizes row labels identically via the shared static normalizer', function (): void {
    // Single source of truth shared with UAMSWeb\FormSettings\Scoring\SurveyScorer.
    expect(LikertScale::normalizeRowLabels(['A]B', 'A]B', '', 'C']))->toBe(['AB', 'C']);
    expect(LikertScale::normalizeRowLabels('not-an-array'))->toBe([]);
});

it('falls back to five points, ascending and unreversed, not inline, when the config is unset or not numeric', function (array $config): void {
    $preload = likertPreload(array_merge(['scale_type' => 'agreement'], $config));

    // An unset toggle reads as the toggle's own default, which is off
    expect(array_column(mustBeArray($preload['options'], 'scale options'), 'value'))->toBe([1, 2, 3, 4, 5])
        ->and($preload['inline'])->toBeFalse();
})->with([
    'nothing set' => [[]],
    'a non-numeric point count' => [['number_of_points' => 'many']],
]);

it('reads stored integer toggles as booleans', function (): void {
    $preload = likertPreload(['scale_type' => 'agreement', 'number_of_points' => 2, 'reverse_scale' => 1, 'inline' => 0]);

    expect(array_column(mustBeArray($preload['options'], 'scale options'), 'value'))->toBe([2, 1])
        ->and($preload['inline'])->toBeFalse();
});

it('labels the zero-point option from its configured text, falling back when that is blank or not text', function (mixed $label, string $expected): void {
    $options = mustBeArray(likertPreload([
        'scale_type' => 'agreement', 'number_of_points' => 2,
        'enable_not_applicable' => true, 'not_applicable_label' => $label,
    ])['options'], 'scale options');

    expect(end($options))->toBe(['value' => 'not_applicable', 'label' => $expected]);
})->with([
    'a number' => [7, '7'],
    'whitespace' => ['   ', 'Not Applicable'],
    'a list' => [['N/A'], 'Not Applicable'],
]);

it('ignores configured rows while multiple rows are disabled', function (): void {
    expect(likertPreload([
        'scale_type' => 'agreement', 'number_of_points' => 5,
        'enable_multiple_rows' => false, 'multiple_rows' => ['Staff'],
    ])['multiple_rows'])->toBe([]);
});

it('normalizes row labels from a collection, reading numbers as text and dropping non-text rows', function (): void {
    expect(LikertScale::normalizeRowLabels(collect(['Staff', 5, ['nested']])))->toBe(['Staff', '5']);
});

it('reads the configured scale type and a point count stored as text', function (): void {
    $options = mustBeArray(likertPreload(['scale_type' => 'satisfaction', 'number_of_points' => '3'])['options'], 'scale options');

    expect($options)->toBe([
        ['value' => 1, 'label' => 'Dissatisfied'],
        ['value' => 2, 'label' => 'Neutral'],
        ['value' => 3, 'label' => 'Satisfied'],
    ]);
});

it('falls back to the agreement scale for a scale type that names none', function (): void {
    $options = mustBeArray(likertPreload(['scale_type' => 7, 'number_of_points' => 2])['options'], 'scale options');

    expect(array_column($options, 'label'))->toBe(['Disagree', 'Agree']);
});

it('offers no scale options for a point count outside the scale', function (): void {
    expect(likertPreload(['scale_type' => 'agreement', 'number_of_points' => 11])['options'])->toBe([]);
});

it('gives the public form template the same data the control panel preloads', function (): void {
    $fieldtype = new LikertScale;
    $fieldtype->setField(new Field('q', ['type' => 'likert_scale', 'scale_type' => 'quality', 'number_of_points' => 3, 'inline' => true]));

    expect($fieldtype->extraRenderableFieldData())->toBe($fieldtype->preload())
        ->and($fieldtype->extraRenderableFieldData()['options'])->not->toBe([]);
});
