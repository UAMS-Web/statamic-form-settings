<?php

declare(strict_types=1);

use UAMSWeb\FormSettings\CpForms\ConfirmationSettingsValuesFilter;

covers(ConfirmationSettingsValuesFilter::class);

/**
 * Behavior of {@see ConfirmationSettingsValuesFilter}: which confirmation settings are
 * stored for each confirmation type when the CP form settings are saved (UAMS-Web/uams-statamic#3089).
 *
 * @command  vendor/bin/pest tests/Integration/CpForms/ConfirmationSettingsValuesFilterTest.php
 */

/**
 * Filter confirmation settings through the container-built filter.
 *
 * @param  array<string, mixed>  $value
 * @return array<string, mixed>
 */
function confirmationSettingsFiltered(array $value): array
{
    return app(ConfirmationSettingsValuesFilter::class)->filter($value);
}

/**
 * Settings carrying a value for every branch, so each type's choice is visible.
 *
 * @return array<string, mixed>
 */
function confirmationSettingsEvery(): array
{
    return [
        'confirmation_message' => 'Thanks',
        'confirmation_redirect_url' => '/thanks',
        'confirmation_redirect_target' => '_blank',
        'confirmation_redirect_delay_query' => false,
        'confirmation_redirect_delay_time' => 5,
        'confirmation_redirect_message' => 'Redirecting',
        'confirmation_page' => 'page-id',
        'confirmation_replace_entry' => 'entry-id',
        'stray' => 'x',
    ];
}

it('keeps only the settings the confirmation type uses', function (string $type, array $expected): void {
    expect(confirmationSettingsFiltered(['confirmation_type' => $type] + confirmationSettingsEvery()))
        ->toBe(['confirmation_type' => $type, 'confirmation_message' => 'Thanks'] + $expected);
})->with([
    'message' => ['message', []],
    'redirect' => ['redirect', [
        'confirmation_redirect_url' => '/thanks',
        'confirmation_redirect_target' => '_blank',
        'confirmation_redirect_delay_query' => false,
        'confirmation_redirect_delay_time' => 5,
        'confirmation_redirect_message' => 'Redirecting',
    ]],
    'entry' => ['entry', ['confirmation_page' => 'page-id']],
    'replace' => ['replace', ['confirmation_replace_entry' => 'entry-id']],
]);

it('defaults the type to message', function (): void {
    expect(confirmationSettingsFiltered(confirmationSettingsEvery()))
        ->toBe(['confirmation_type' => 'message', 'confirmation_message' => 'Thanks']);
});

it('drops an empty message and empty redirect, page and entry values', function (): void {
    $empty = [
        'confirmation_message' => '',
        'confirmation_redirect_url' => '',
        'confirmation_redirect_target' => null,
        'confirmation_redirect_delay_query' => null,
        'confirmation_redirect_delay_time' => 0,
        'confirmation_redirect_message' => '',
        'confirmation_page' => '',
        'confirmation_replace_entry' => '',
    ];

    foreach (['message', 'redirect', 'entry', 'replace'] as $type) {
        expect(confirmationSettingsFiltered(['confirmation_type' => $type] + $empty))->toBe(['confirmation_type' => $type]);
    }
});

it('keeps a redirect target and delay toggle that are set but empty', function (): void {
    expect(confirmationSettingsFiltered([
        'confirmation_type' => 'redirect',
        'confirmation_redirect_target' => '',
        'confirmation_redirect_delay_query' => false,
    ]))->toBe([
        'confirmation_type' => 'redirect',
        'confirmation_redirect_target' => '',
        'confirmation_redirect_delay_query' => false,
    ]);
});
