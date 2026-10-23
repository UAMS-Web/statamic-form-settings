<?php

declare(strict_types=1);

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\Field;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Fieldtypes\FormEmailFieldSelect;

uses(MockeryPHPUnitIntegration::class);

covers(FormEmailFieldSelect::class);

/**
 * Behavior of `FormEmailFieldSelect`, the email-field picker of the form email
 * settings (UAMS-Web/uams-statamic#3089): which of a form's fields it offers, and how it reads the
 * form handle.
 *
 * @command  vendor/bin/pest tests/Integration/Fieldtypes/FormEmailFieldSelectFieldtypeTest.php
 */

/**
 * A form Field mock returning a fixed handle, type, display and validation.
 *
 * @param  list<string>  $validate
 */
function emailFieldSelectFieldMock(string $handle, string $type, string $display, array $validate): Field
{
    $field = Mockery::mock(Field::class);
    $field->shouldReceive('handle')->andReturn($handle);
    $field->shouldReceive('type')->andReturn($type);
    $field->shouldReceive('display')->andReturn($display);
    $field->shouldReceive('get')->with('validate', [])->andReturn($validate);

    return $field;
}

/**
 * Bind FormEmailFieldSelect to a Field with the given config.
 *
 * @param  array<string, mixed>  $config
 */
function emailFieldSelectFieldtype(array $config = []): FormEmailFieldSelect
{
    $fieldtype = new FormEmailFieldSelect;
    $fieldtype->setField(new Field('recipient_field', array_merge(['type' => 'form_email_field_select'], $config)));

    return $fieldtype;
}

it('offers only the required email fields, labelled by display or handle', function (): void {
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('fields')->andReturn(collect([
        emailFieldSelectFieldMock('work_email', 'email', 'Work email', ['required', 'email']),
        emailFieldSelectFieldMock('alt_email', 'email', '', ['sometimes', 'required_if:contact,yes']),
        emailFieldSelectFieldMock('optional_email', 'email', 'Optional', ['nullable', 'email']),
        emailFieldSelectFieldMock('no_rules', 'email', 'No rules', []),
        emailFieldSelectFieldMock('name', 'text', 'Name', ['required']),
        emailFieldSelectFieldMock('suffix', 'email', 'Suffix', ['email_required_']),
    ]));
    FormFacade::shouldReceive('find')->once()->with('contact')->andReturn($form);

    expect(emailFieldSelectFieldtype(['form_handle' => 'contact'])->preload()['email_fields'])->toBe([
        ['value' => 'work_email', 'label' => 'Work email'],
        ['value' => 'alt_email', 'label' => 'alt_email'],
    ]);
});

it('keeps the field config and adds the email fields to it', function (): void {
    $preload = emailFieldSelectFieldtype(['instructions' => 'Pick one.'])->preload();

    expect($preload['instructions'])->toBe('Pick one.')
        ->and($preload['email_fields'])->toBe([]);
});

it('looks a numeric form handle up as a string', function (): void {
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('fields')->andReturn(collect([
        emailFieldSelectFieldMock('email', 'email', 'Email', ['required']),
    ]));
    FormFacade::shouldReceive('find')->once()->with(Mockery::on(fn (mixed $handle): bool => $handle === '42'))->andReturn($form);

    expect(emailFieldSelectFieldtype(['form_handle' => 42])->preload()['email_fields'])
        ->toBe([['value' => 'email', 'label' => 'Email']]);
});

it('does not look a form up for a missing, empty or non-scalar handle', function (mixed $handle): void {
    FormFacade::shouldReceive('find')->never();

    expect(emailFieldSelectFieldtype(['form_handle' => $handle])->preload()['email_fields'])->toBe([]);
})->with([
    'null' => [null],
    'empty' => [''],
    'zero' => ['0'],
    'list' => [['contact']],
]);

it('offers no fields for a handle naming no form', function (): void {
    FormFacade::shouldReceive('find')->once()->with('missing')->andReturn(null);

    expect(emailFieldSelectFieldtype(['form_handle' => 'missing'])->preload()['email_fields'])->toBe([]);
});

it('uses the mail icon and form_email_field_select Vue component', function (): void {
    $fieldtype = emailFieldSelectFieldtype();

    expect($fieldtype->icon())->toBe('mail-sign-at')
        ->and($fieldtype->component())->toBe('form_email_field_select');
});

it('is forms/special, not selectable, and not localizable', function (): void {
    $fieldtype = emailFieldSelectFieldtype();

    expect($fieldtype->categories())->toBe(['forms', 'special'])
        ->and($fieldtype->selectable())->toBeFalse()
        ->and($fieldtype->selectableInForms())->toBeFalse()
        ->and($fieldtype->localizable())->toBeFalse();
});

it('exposes the configuration config section exactly', function (): void {
    /** @var list<array<string, mixed>> $items */
    $items = new ReflectionMethod(FormEmailFieldSelect::class, 'configFieldItems')->invoke(emailFieldSelectFieldtype());

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
                    'instructions' => __('Instructions to display to users selecting email fields.'),
                    'type' => 'textarea',
                    'default' => __('Select a required form field that contains email addresses.'),
                ],
            ],
        ],
    ]);
});
