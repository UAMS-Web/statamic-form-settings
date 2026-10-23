<?php

declare(strict_types=1);

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\Field;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Fieldtypes\FormConditionalLogic;

uses(MockeryPHPUnitIntegration::class);

// Explicitly define the target class for this file
covers(FormConditionalLogic::class);

/**
 * Mutation-facing and behavioral coverage for `FormConditionalLogic`.
 *
 * @command  vendor/bin/pest tests/Integration/Fieldtypes/FormConditionalLogicFieldtypeTest.php
 */

/**
 * A form Field mock returning fixed handle/type/display/config.
 *
 * @param  array<string, mixed>  $config
 */
function fclFieldMock(string $handle, string $type, ?string $display, array $config = []): Field
{
    $field = Mockery::mock(Field::class);
    $field->shouldReceive('handle')->andReturn($handle);
    $field->shouldReceive('type')->andReturn($type);
    $field->shouldReceive('display')->andReturn($display);
    $field->shouldReceive('config')->andReturn($config);

    return $field;
}

/**
 * Bind FormConditionalLogic to a Field with the given config.
 *
 * @param  array<string, mixed>  $config
 */
function fclFieldtype(array $config = []): FormConditionalLogic
{
    // Build the fieldtype under test
    $fieldtype = new FormConditionalLogic;

    // Bind a Field with the given config over the type handle
    $fieldtype->setField(new Field(
        'logic',
        array_merge(
            ['type' => 'form_conditional_logic'],
            $config
        )
    ));

    return $fieldtype;
}

it('returns no suggestable fields when no form handle is configured', function (): void {
    expect(fclFieldtype()->preload()['suggestable_fields'])->toBe([]);
});

it('lists conditionable form fields with raw options and skips layout-only fields', function (): void {
    // Deliberately array-shaped options (the same shape FormEmailRouting flattens),
    // so this test proves FormConditionalLogic keeps them RAW rather than merely
    // coinciding with flattening on scalar options.
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('fields')->andReturn(collect([
        fclFieldMock('favorite', 'select', 'Favorite', ['options' => [
            'a' => ['value' => 'A', 'label' => 'Apple'],
            'b' => ['value' => 'B', 'label' => 'Banana'],
        ]]),
        fclFieldMock('note', 'html', 'Note'),
        fclFieldMock('section_1', 'section', 'Section'),
        fclFieldMock('subsection_1', 'subsection', 'Subsection'),
        fclFieldMock('divider_1', 'divider', 'Divider'),
        fclFieldMock('name', 'text', ''),
        fclFieldMock('plain', 'text', 'Plain', []),
    ]));
    FormFacade::shouldReceive('find')->with('contact')->andReturn($form);

    /** @var list<array{handle: string, config: array<string, mixed>}> $suggestable */
    $suggestable = fclFieldtype(['form_handle' => 'contact'])->preload()['suggestable_fields'];

    // Layout-only types are not conditionable and are dropped; empty display falls
    // back to the handle; missing options become an empty array.
    expect(array_column($suggestable, 'handle'))->toBe(['favorite', 'name', 'plain'])
        ->and($suggestable[0]['config'])->toBe([
            'display' => 'Favorite',
            'type' => 'select',
            'options' => [
                'a' => ['value' => 'A', 'label' => 'Apple'],
                'b' => ['value' => 'B', 'label' => 'Banana'],
            ],
        ])
        ->and($suggestable[1]['config'])->toBe([
            'display' => 'name',
            'type' => 'text',
            'options' => [],
        ])
        ->and($suggestable[2]['config'])->toBe([
            'display' => 'Plain',
            'type' => 'text',
            'options' => [],
        ]);
});

it('uses the ab-testing icon and form_conditional_logic Vue component', function (): void {
    $fieldtype = fclFieldtype();

    expect($fieldtype->icon())->toBe('edit-design-compare-ab-testing')
        ->and($fieldtype->component())->toBe('form_conditional_logic');
});

it('is forms/special, not selectable, and not localizable', function (): void {
    $fieldtype = fclFieldtype();

    expect($fieldtype->categories())->toBe(['forms', 'special'])
        ->and($fieldtype->selectable())->toBeFalse()
        ->and($fieldtype->selectableInForms())->toBeFalse()
        ->and($fieldtype->localizable())->toBeFalse();
});

it('exposes the configuration config section exactly', function (): void {
    // Reflect the protected configFieldItems map
    $method = new ReflectionMethod(FormConditionalLogic::class, 'configFieldItems');

    // Invoke configFieldItems on a bound fieldtype
    /** @var list<array<string, mixed>> $items */
    $items = $method->invoke(fclFieldtype());

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
                    'instructions' => __('Instructions to display to users configuring conditional logic.'),
                    'type' => 'textarea',
                    'default' => __('Configure when this confirmation should be triggered based on form field values.'),
                ],
            ],
        ],
    ]);
});

it('coerces a non-scalar form_handle to an empty string for form lookup', function (): void {
    // Stub an empty form returned for the cleared handle
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('fields')->andReturn(collect([]));
    FormFacade::shouldReceive('find')->with('')->andReturn($form);

    // Assert preload yields no suggestable fields for a non-scalar handle
    expect(fclFieldtype(['form_handle' => ['not' => 'a string']])->preload()['suggestable_fields'])
        ->toBe([]);
});

it('casts a scalar non-string form_handle before looking up the form', function (): void {
    // Stub a form looked up by the string-cast handle
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('fields')->andReturn(collect([
        fclFieldMock('email', 'text', 'Email'),
    ]));
    FormFacade::shouldReceive('find')->with('42')->andReturn($form);

    // Preload with an integer form_handle so the cast path runs
    /** @var list<array{handle: string, config: array<string, mixed>}> $suggestable */
    $suggestable = fclFieldtype(['form_handle' => 42])->preload()['suggestable_fields'];

    // Assert the form fields were resolved after casting
    expect(array_column($suggestable, 'handle'))->toBe(['email']);
});
