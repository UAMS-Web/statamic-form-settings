<?php

declare(strict_types=1);

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use UAMSWeb\FormSettings\CpForms\ConfirmationSettingsValuesFilter;
use UAMSWeb\FormSettings\CpForms\FormConfigurationValuesFilter;

uses(MockeryPHPUnitIntegration::class);

/**
 * Unit tests for CP form configuration value filtering by field conditions.
 *
 * Mutation: 100% on UAMSWeb\FormSettings\CpForms\FormConfigurationValuesFilter (15 mutants, --covered-only).
 *
 * @command  vendor/bin/pest tests/Integration/CpForms/FormConfigurationValuesFilterTest.php
 */
it('excludes a field when its if condition is not met', function (): void {
    $conditionalField = Mockery::mock(Field::class);
    $conditionalField->shouldReceive('conditions')->andReturn([
        'if' => ['confirmation_type' => 'equals message'],
    ]);

    $fields = Mockery::mock(Fields::class);
    $fields->shouldReceive('get')->with('confirmation_type')->andReturn(null);
    $fields->shouldReceive('get')->with('confirmation_only_when_message')->andReturn($conditionalField);

    $out = app(FormConfigurationValuesFilter::class)->filterFieldsByConditions([
        'confirmation_type' => 'redirect',
        'confirmation_only_when_message' => ['should' => 'drop'],
    ], $fields);

    expect(data_get($out, 'confirmation_type'))->toBe('redirect');
    expect($out)->not->toHaveKey('confirmation_only_when_message');
});

it('drops email grid rows whose routing rule is meaningless', function (): void {
    $emailField = Mockery::mock(Field::class);
    $emailField->shouldReceive('conditions')->andReturn([]);

    $fields = Mockery::mock(Fields::class);
    $fields->shouldReceive('get')->with('email')->andReturn($emailField);

    $out = app(FormConfigurationValuesFilter::class)->filterFieldsByConditions([
        'email' => [
            [
                'name' => 'Admin',
                'enabled' => true,
                'to_type' => 'routing',
                'to_routing' => [
                    ['field' => '', 'value' => '', 'email' => ''],
                ],
            ],
        ],
    ], $fields);

    expect($out)->toHaveKey('email');

    $emailRow = data_get($out, 'email.0');
    expect($emailRow)->toBeArray();
    expect($emailRow)->not->toHaveKey('to_routing');
    expect(data_get($out, 'email.0.to_type'))->toBe('routing');
});

it('keeps email grid routing with a complete rule', function (): void {
    $emailField = Mockery::mock(Field::class);
    $emailField->shouldReceive('conditions')->andReturn([]);

    $fields = Mockery::mock(Fields::class);
    $fields->shouldReceive('get')->with('email')->andReturn($emailField);

    $routing = [
        ['field' => 'department', 'value' => 'ER', 'email' => 'er@example.test'],
    ];

    $out = app(FormConfigurationValuesFilter::class)->filterFieldsByConditions([
        'email' => [
            [
                'name' => 'Admin',
                'enabled' => true,
                'to_type' => 'routing',
                'to_routing' => $routing,
            ],
        ],
    ], $fields);

    expect(data_get($out, 'email.0.to_routing'))->toBe($routing);
});

/**
 * A field double with the given conditions.
 *
 * @param  array<string, mixed>  $conditions
 */
function formConfigField(array $conditions = []): Field
{
    $field = Mockery::mock(Field::class);
    $field->shouldReceive('conditions')->andReturn($conditions);

    return $field;
}

it('keeps a field whose condition is met, and passes its value through', function (): void {
    $fields = Mockery::mock(Fields::class);
    $fields->shouldReceive('get')->with('confirmation_type')->andReturn(null);
    $fields->shouldReceive('get')->with('message_options')
        ->andReturn(formConfigField(['if' => ['confirmation_type' => 'equals message']]));

    $out = app(FormConfigurationValuesFilter::class)->filterFieldsByConditions([
        'confirmation_type' => 'message',
        'message_options' => ['tone' => 'warm', 'empty' => ''],
    ], $fields);

    expect($out)->toBe([
        'confirmation_type' => 'message',
        'message_options' => ['tone' => 'warm', 'empty' => ''],
    ]);
});

it('passes a plain value and an unrecognized list through unchanged', function (): void {
    $fields = Mockery::mock(Fields::class);
    $fields->shouldReceive('get')->with('title')->andReturn(formConfigField());
    $fields->shouldReceive('get')->with('honeypot_settings')->andReturn(formConfigField());

    $out = app(FormConfigurationValuesFilter::class)->filterFieldsByConditions([
        'title' => 'Contact us',
        'honeypot_settings' => ['field' => 'winnie', 'unused' => null],
    ], $fields);

    expect($out)->toBe([
        'title' => 'Contact us',
        'honeypot_settings' => ['field' => 'winnie', 'unused' => null],
    ]);
});

it('normalizes the default confirmation settings through their own filter', function (): void {
    $fields = Mockery::mock(Fields::class);
    $fields->shouldReceive('get')->with('confirmation_settings_default')->andReturn(formConfigField());

    $out = app(FormConfigurationValuesFilter::class)->filterFieldsByConditions([
        'confirmation_settings_default' => ['confirmation_message' => 'Thanks!', 'stray' => 'dropped'],
    ], $fields);

    expect($out)->toBe([
        'confirmation_settings_default' => app(ConfirmationSettingsValuesFilter::class)
            ->filter(['confirmation_message' => 'Thanks!', 'stray' => 'dropped']),
    ])->and($out['confirmation_settings_default'])->not->toHaveKey('stray');
});
