<?php

declare(strict_types=1);

use UAMSWeb\FormSettings\Fieldtypes\LikertScale;

/**
 * Pins the LikertScale fieldtype's blueprint config fields, its row-label
 * validator, and its Control Panel identity.
 *
 * @command  vendor/bin/pest tests/Integration/Fieldtypes/LikertScaleConfigTest.php
 */
covers(LikertScale::class);

/**
 * The fieldtype's config field sections.
 *
 * @return list<array<string, mixed>>
 */
function likertConfigItems(): array
{
    /** @var list<array<string, mixed>> $items */
    $items = new ReflectionMethod(LikertScale::class, 'configFieldItems')->invoke(new LikertScale);

    return $items;
}

/**
 * Run the row-label validator closure and collect the failure messages.
 *
 * @return list<string>
 */
function likertRowLabelFailures(mixed $value): array
{
    $closure = mustBe(Closure::class, data_get(likertConfigItems(), '0.fields.multiple_rows.validate.4'), 'row-label validator');
    $failures = [];

    $closure('multiple_rows', $value, function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    return $failures;
}

it('defines the scale and appearance config fields', function (): void {
    $items = likertConfigItems();

    expect(data_get($items, '0.fields.multiple_rows.validate.4'))->toBeInstanceOf(Closure::class);

    // The closure is checked on its own below; compare everything around it
    data_set($items, '0.fields.multiple_rows.validate.4', 'closure');

    expect($items)->toBe([
        [
            'display' => 'Likert Scale Options',
            'fields' => [
                'scale_type' => [
                    'display' => 'Scale Type',
                    'instructions' => 'Choose the type of scale to display.',
                    'type' => 'radio',
                    'options' => [
                        'agreement' => 'Agreement',
                        'experience' => 'Experience',
                        'frequency' => 'Frequency (With "Always")',
                        'frequency_no_always' => 'Frequency (Without "Always")',
                        'likelihood' => 'Likelihood',
                        'quality' => 'Quality',
                        'satisfaction' => 'Satisfaction',
                    ],
                    'default' => 'agreement',
                    'validate' => ['required'],
                ],
                'number_of_points' => [
                    'display' => 'Number of Points',
                    'instructions' => 'Select how many points the scale should have.',
                    'type' => 'select',
                    'options' => [
                        2 => 'Two Points',
                        3 => 'Three Points',
                        4 => 'Four Points',
                        5 => 'Five Points',
                        6 => 'Six Points',
                        7 => 'Seven Points',
                        8 => 'Eight Points',
                        9 => 'Nine Points',
                        10 => 'Ten Points',
                    ],
                    'default' => 5,
                    'validate' => ['required'],
                ],
                'enable_not_applicable' => [
                    'display' => 'Enable Zero-Point Option',
                    'instructions' => 'Allow users to select a zero-point option.',
                    'type' => 'toggle',
                    'default' => false,
                ],
                'not_applicable_label' => [
                    'type' => 'text',
                    'display' => 'Zero-Point Option Label',
                    'instructions' => 'Define the label for the zero-point option.',
                    'default' => 'Not Applicable',
                    'if' => ['enable_not_applicable' => 'equals true'],
                    'validate' => [
                        'required_if:enable_not_applicable,true',
                        'exclude_unless:enable_not_applicable,true',
                    ],
                ],
                'display_order' => [
                    'type' => 'radio',
                    'display' => 'Scale Display Order',
                    'instructions' => 'Choose whether the worst or lowest rating is listed first, or vice-versa.',
                    'options' => [
                        'asc' => 'Low to High',
                        'desc' => 'High to Low',
                    ],
                    'default' => 'asc',
                ],
                'reverse_scale' => [
                    'display' => 'Reverse Scale',
                    'instructions' => 'Reverse the scale so that the lowest conceptual value is the highest numeric value.',
                    'type' => 'toggle',
                    'default' => false,
                ],
                'enable_multiple_rows' => [
                    'display' => 'Enable Multiple Rows',
                    'instructions' => 'Add multiple rows to the field.',
                    'type' => 'toggle',
                    'default' => false,
                ],
                'multiple_rows' => [
                    'display' => 'Row Labels',
                    'instructions' => 'Set the text for each row.',
                    'type' => 'list',
                    'if' => ['enable_multiple_rows' => 'equals true'],
                    'validate' => [
                        'required_if:enable_multiple_rows,true',
                        'exclude_unless:enable_multiple_rows,true',
                        'array',
                        'min:1',
                        'closure',
                    ],
                ],
            ],
        ],
        [
            'display' => 'Appearance & Behavior',
            'fields' => [
                'inline' => [
                    'display' => 'Inline',
                    'instructions' => __('statamic::fieldtypes.radio.config.inline'),
                    'type' => 'toggle',
                ],
            ],
        ],
    ]);
});

it('accepts row labels that all have text', function (mixed $value): void {
    expect(likertRowLabelFailures($value))->toBe([]);
})->with([
    'labels' => [['Staff', 'Value', '10']],
    'a number label' => [['Staff', 7]],
    'not a list' => ['Staff'],
]);

it('rejects a blank row label once, however many there are', function (mixed $value): void {
    expect(likertRowLabelFailures($value))->toBe(['Each row label must have a value.']);
})->with([
    'an empty label' => [['Staff', '']],
    'a whitespace label' => [['Staff', '   ']],
    'a zero label' => [['Staff', ' 0 ']],
    'a non-text label' => [['Staff', ['nested']]],
    'several blanks' => [['', 'Staff', '']],
]);

it('presents as the likert_scale component with the sliders icon', function (): void {
    $likert = new LikertScale;

    expect($likert->component())->toBe('likert_scale')
        ->and($likert->icon())->toBe('sliders-horizontal')
        ->and($likert->categories())->toBe([])
        ->and($likert->defaultable())->toBeFalse();
});
