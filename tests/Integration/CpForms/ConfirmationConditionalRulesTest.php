<?php

declare(strict_types=1);

use UAMSWeb\FormSettings\CpForms\ConfirmationSettingsFieldBuilder;
use UAMSWeb\FormSettings\Tests\Support\ConfirmationSettingsValidation as Confirmation;

/*
 * Guards the confirmation settings' conditional rules against the nested Control Panel blueprint
 * (UAMS-Web/uams-statamic#3162). The fields sit in a group, so a rule naming its companion by bare handle resolved to
 * nothing: `required_if` passed every save and `exclude_unless` dropped every setting. Each rule
 * now names its companion by the full path the save submits, in the default group and in an
 * additional confirmation alike.
 *
 * Every case runs at both paths and pairs the rejection with a negative control: the same save
 * passes once the required field is supplied.
 *
 * @command  vendor/bin/pest tests/Integration/CpForms/ConfirmationConditionalRulesTest.php
 */

/**
 * Build a one-paragraph Bard document, the shape a Bard setting is submitted in.
 *
 * @return list<array<string, mixed>>
 */
function confirmationBardText(string $text): array
{
    return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]];
}

dataset('confirmation paths', [
    'default group' => [Confirmation::DEFAULT],
    'additional confirmation' => [Confirmation::ADDITIONAL],
]);

dataset('required by type', [
    'message' => ['message', 'confirmation_message', confirmationBardText('Thanks for writing')],
    'redirect' => ['redirect', 'confirmation_redirect_url', 'https://example.com/thanks'],
    'entry' => ['entry', 'confirmation_page', ['page-id']],
    'replace' => ['replace', 'confirmation_replace_entry', ['entry-id']],
]);

it('rejects a confirmation type without its setting and accepts it once supplied', function (string $path, string $type, string $handle, mixed $value): void {
    // Check the empty setting is rejected, by its own full path
    $missing = Confirmation::validate($path, ['confirmation_type' => $type]);

    expect($missing->passes())->toBeFalse()
        ->and(array_keys($missing->errors()->toArray()))->toBe([$path.'.'.$handle]);

    // Check the same save passes once the setting is supplied
    $supplied = Confirmation::validate($path, ['confirmation_type' => $type, $handle => $value]);

    expect($supplied->passes())->toBeTrue()
        ->and($supplied->validated())->toHaveKey($path.'.'.$handle);
})->with('confirmation paths')->with('required by type');

it('rejects a redirect delay without its time or message and accepts it once both are supplied', function (string $path): void {
    $settings = [
        'confirmation_type' => 'redirect',
        'confirmation_redirect_url' => 'https://example.com/thanks',
        'confirmation_redirect_delay_query' => true,
    ];

    // Check each missing delay setting is reported by its own full path
    $missing = Confirmation::validate($path, $settings);

    expect($missing->passes())->toBeFalse()
        ->and(array_keys($missing->errors()->toArray()))->toEqualCanonicalizing([
            $path.'.confirmation_redirect_delay_time',
            $path.'.confirmation_redirect_message',
        ]);

    // Check supplying one leaves only the other reported
    $timeOnly = Confirmation::validate($path, [...$settings, 'confirmation_redirect_delay_time' => 5]);

    expect(array_keys($timeOnly->errors()->toArray()))->toBe([$path.'.confirmation_redirect_message']);

    // Check the save passes once both are supplied
    $supplied = Confirmation::validate($path, [
        ...$settings,
        'confirmation_redirect_delay_time' => 5,
        'confirmation_redirect_message' => confirmationBardText('Redirecting'),
    ]);

    expect($supplied->passes())->toBeTrue();
})->with('confirmation paths');

it('drops the settings a confirmation type does not use', function (string $path): void {
    $validator = Confirmation::validate($path, [
        'confirmation_type' => 'message',
        'confirmation_message' => confirmationBardText('Thanks'),
        'confirmation_redirect_url' => 'https://example.com/thanks',
        'confirmation_page' => ['page-id'],
        'confirmation_query_string' => 'foo=bar',
        'confirmation_replace_entry' => ['entry-id'],
    ]);

    expect($validator->passes())->toBeTrue();

    $validated = $validator->validated();

    // Check the used setting survives and every unused one is dropped
    expect($validated)->toHaveKey($path.'.confirmation_message');

    foreach (['confirmation_redirect_url', 'confirmation_page', 'confirmation_query_string', 'confirmation_replace_entry'] as $handle) {
        expect($validated)->not->toHaveKey($path.'.'.$handle);
    }
})->with('confirmation paths');

it('drops the delay settings while the delay is off', function (string $path): void {
    $validator = Confirmation::validate($path, [
        'confirmation_type' => 'redirect',
        'confirmation_redirect_url' => 'https://example.com/thanks',
        'confirmation_redirect_delay_query' => false,
        'confirmation_redirect_delay_time' => 5,
        'confirmation_redirect_message' => confirmationBardText('Redirecting'),
    ]);

    expect($validator->passes())->toBeTrue()
        ->and($validator->validated())->not->toHaveKey($path.'.confirmation_redirect_delay_time')
        ->and($validator->validated())->not->toHaveKey($path.'.confirmation_redirect_message');
})->with('confirmation paths');

it('names each companion field by its full path in the emitted rules', function (string $path): void {
    expect(Confirmation::rules($path, 'confirmation_message'))
        ->toBe(['exclude_unless:'.$path.'.confirmation_type,message', 'required_if:'.$path.'.confirmation_type,message'])
        ->and(Confirmation::rules($path, 'confirmation_redirect_delay_time'))
        ->toContain('required_if:'.$path.'.confirmation_redirect_delay_query,true');
})->with('confirmation paths');

it('limits each page setting to a single entry', function (string $handle): void {
    $entry = mustBeStringKeyed((new ConfirmationSettingsFieldBuilder)->build(Confirmation::DEFAULT)[$handle] ?? null, $handle);
    $field = mustBeStringKeyed($entry['field'] ?? null, $handle.' field');

    expect($field)->toMatchArray(['type' => 'entries', 'max_items' => 1, 'collections' => ['pages']]);
})->with(['confirmation_page', 'confirmation_replace_entry']);

it('offers the configured collections in each page picker', function (string $handle): void {
    config(['statamic-form-settings.confirmation_page_collections' => ['pages', 'landing_pages']]);

    $entry = mustBeStringKeyed((new ConfirmationSettingsFieldBuilder)->build(Confirmation::DEFAULT)[$handle] ?? null, $handle);
    $field = mustBeStringKeyed($entry['field'] ?? null, $handle.' field');

    expect($field['collections'])->toBe(['pages', 'landing_pages']);
})->with(['confirmation_page', 'confirmation_replace_entry']);

it('falls back to the pages collection when none is configured', function (): void {
    config(['statamic-form-settings.confirmation_page_collections' => []]);

    $entry = mustBeStringKeyed((new ConfirmationSettingsFieldBuilder)->build(Confirmation::DEFAULT)['confirmation_page'] ?? null, 'confirmation_page');
    $field = mustBeStringKeyed($entry['field'] ?? null, 'confirmation_page field');

    expect($field['collections'])->toBe(['pages']);
});
