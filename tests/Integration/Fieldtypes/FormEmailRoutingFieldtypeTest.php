<?php

declare(strict_types=1);

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\Field;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Fieldtypes\FormEmailRouting;

uses(MockeryPHPUnitIntegration::class);

// Explicitly define the target class for this file
covers(FormEmailRouting::class);

/**
 * Mutation-facing and behavioral coverage for `FormEmailRouting`.
 *
 * @command  vendor/bin/pest tests/Integration/Fieldtypes/FormEmailRoutingFieldtypeTest.php
 */

/**
 * A form Field mock returning fixed handle/type/display/config.
 *
 * @param  array<string, mixed>  $config
 */
function ferFieldMock(string $handle, string $type, ?string $display, array $config = []): Field
{
    $field = Mockery::mock(Field::class);
    $field->shouldReceive('handle')->andReturn($handle);
    $field->shouldReceive('type')->andReturn($type);
    $field->shouldReceive('display')->andReturn($display);
    $field->shouldReceive('config')->andReturn($config);

    return $field;
}

/**
 * Bind FormEmailRouting to a Field with the given config.
 *
 * @param  array<string, mixed>  $config
 */
function ferFieldtype(array $config = []): FormEmailRouting
{
    // Build the fieldtype under test
    $fieldtype = new FormEmailRouting;

    // Bind a Field with the given config over the type handle
    $fieldtype->setField(new Field(
        'routing',
        array_merge(
            ['type' => 'form_email_routing'],
            $config
        )
    ));

    return $fieldtype;
}

it('returns no suggestable fields when no form handle is configured', function (): void {
    expect(ferFieldtype()->preload()['suggestable_fields'])->toBe([]);
});

it('flattens array-shaped select options to scalar values and skips layout-only fields', function (): void {
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('fields')->andReturn(collect([
        ferFieldMock('favorite', 'select', 'Favorite', ['options' => [
            'a' => ['value' => 'A', 'label' => 'Apple'],
            'b' => ['value' => 'B', 'label' => 'Banana'],
            2 => ['label' => 'No value key'],
            'plain' => 'scalar',
        ]]),
        ferFieldMock('note', 'html', 'Note'),
        ferFieldMock('section_1', 'section', 'Section'),
        ferFieldMock('subsection_1', 'subsection', 'Subsection'),
        ferFieldMock('divider_1', 'divider', 'Divider'),
        ferFieldMock('name', 'text', ''),
        ferFieldMock('empty_opts', 'select', 'Empty', ['options' => 'not-an-array']),
        ferFieldMock('plain', 'text', 'Plain', []),
    ]));
    FormFacade::shouldReceive('find')->with('contact')->andReturn($form);

    /** @var list<array{handle: string, config: array<string, mixed>}> $suggestable */
    $suggestable = ferFieldtype(['form_handle' => 'contact'])->preload()['suggestable_fields'];

    expect(array_column($suggestable, 'handle'))->toBe(['favorite', 'name', 'empty_opts', 'plain'])
        ->and($suggestable[0]['config']['options'])->toBe([
            'a' => 'A',
            'b' => 'B',
            '2' => ['label' => 'No value key'],
            'plain' => 'scalar',
        ])
        ->and($suggestable[1]['config'])->toBe([
            'display' => 'name',
            'type' => 'text',
            'options' => [],
        ])
        ->and($suggestable[2]['config']['options'])->toBe([])
        ->and($suggestable[3]['config']['options'])->toBe([]);
});

it('uses the mail-settings icon and form_email_routing Vue component', function (): void {
    $fieldtype = ferFieldtype();

    expect($fieldtype->icon())->toBe('mail-settings')
        ->and($fieldtype->component())->toBe('form_email_routing');
});

it('is forms/special, not selectable, and not localizable', function (): void {
    $fieldtype = ferFieldtype();

    expect($fieldtype->categories())->toBe(['forms', 'special'])
        ->and($fieldtype->selectable())->toBeFalse()
        ->and($fieldtype->selectableInForms())->toBeFalse()
        ->and($fieldtype->localizable())->toBeFalse();
});

it('exposes the configuration config section exactly', function (): void {
    // Reflect the protected configFieldItems map
    $method = new ReflectionMethod(FormEmailRouting::class, 'configFieldItems');

    // Invoke configFieldItems on a bound fieldtype
    /** @var list<array<string, mixed>> $items */
    $items = $method->invoke(ferFieldtype());

    // Assert the Configuration section shape exactly
    expect($items)->toBe([
        [
            'display' => __('Configuration'),
            'fields' => [
                'form_handle' => [
                    'display' => __('Form Handle'),
                    'instructions' => __('The handle of the form to get fields from.'),
                    'type' => 'text',
                    'validate' => ['required'],
                ],
                'instructions' => [
                    'display' => __('Instructions'),
                    'instructions' => __('Instructions to display to users configuring email routing.'),
                    'type' => 'textarea',
                    'default' => __('Configure conditional routing rules for email recipients.'),
                ],
            ],
        ],
    ]);
});

it('skips form lookup for a non-scalar form_handle', function (): void {
    // Assert Form::find is never called for a non-scalar handle
    FormFacade::shouldReceive('find')->never();

    // Assert preload yields no suggestable fields
    expect(ferFieldtype(['form_handle' => ['not' => 'a string']])->preload()['suggestable_fields'])
        ->toBe([]);
});

it('casts a scalar non-string form_handle before looking up the form', function (): void {
    // Stub a form looked up by the string-cast handle
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('fields')->andReturn(collect([
        ferFieldMock('email', 'text', 'Email'),
    ]));
    FormFacade::shouldReceive('find')->with('42')->andReturn($form);

    // Preload with an integer form_handle so the cast path runs
    /** @var list<array{handle: string, config: array<string, mixed>}> $suggestable */
    $suggestable = ferFieldtype(['form_handle' => 42])->preload()['suggestable_fields'];

    // Assert the form fields were resolved after casting
    expect(array_column($suggestable, 'handle'))->toBe(['email']);
});
