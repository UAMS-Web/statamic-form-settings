<?php

declare(strict_types=1);

use UAMSWeb\FormSettings\CpForms\FormConfigurationSectionLocator;

/**
 * Behavior of {@see FormConfigurationSectionLocator}: finding a section of the Statamic 6 CP
 * form-configuration blueprint by the handle of a field it carries (UAMS-Web/uams-statamic#3089).
 *
 * @command  vendor/bin/pest tests/Unit/CpForms/FormConfigurationSectionLocatorTest.php
 */
it('returns the tab key and the section and field positions of the first field with the handle', function (): void {
    $contents = ['tabs' => ['main' => ['sections' => [
        ['fields' => [['handle' => 'title', 'field' => []]]],
        ['fields' => [['handle' => 'store', 'field' => []], ['handle' => 'email', 'field' => []]]],
        ['fields' => [['handle' => 'email', 'field' => []]]],
    ]]]];

    expect(FormConfigurationSectionLocator::locateField(mustBeStringKeyed($contents, 'contents'), 'email'))
        ->toBe(['tab' => 'main', 'section' => 1, 'field' => 1]);
});

it('searches past broken tabs, sections and fields to a later match', function (): void {
    $contents = ['tabs' => [
        'broken' => 'not a tab',
        'no_sections' => ['sections' => 'not a list'],
        'later' => ['sections' => [
            'not a section',
            ['fields' => 'not a list'],
            ['fields' => ['not a field', ['field' => []], ['handle' => 'email']]],
        ]],
    ]];

    expect(FormConfigurationSectionLocator::locateField(mustBeStringKeyed($contents, 'contents'), 'email'))
        ->toBe(['tab' => 'later', 'section' => 2, 'field' => 2]);
});

it('reports a numeric tab key as a string and section and field keys as integers', function (): void {
    $contents = ['tabs' => [0 => ['sections' => ['7' => ['fields' => ['3' => ['handle' => 'email']]]]]]];

    expect(FormConfigurationSectionLocator::locateField(mustBeStringKeyed($contents, 'contents'), 'email'))
        ->toBe(['tab' => '0', 'section' => 7, 'field' => 3]);

    // Keyed sections and fields still report integers, as the return type declares.
    $keyed = ['tabs' => ['main' => ['sections' => ['email' => ['fields' => ['to' => ['handle' => 'email']]]]]]];

    expect(FormConfigurationSectionLocator::locateField($keyed, 'email'))
        ->toBe(['tab' => 'main', 'section' => 0, 'field' => 0]);
});

it('returns null without iterating a tabs value that is not a map', function (): void {
    $warnings = [];
    set_error_handler(function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        $result = FormConfigurationSectionLocator::locateField(['tabs' => 'main'], 'email');
    } finally {
        restore_error_handler();
    }

    expect($result)->toBeNull()->and($warnings)->toBe([]);
});

it('returns null when no tabs map exists or no field carries the handle', function (array $contents): void {
    expect(FormConfigurationSectionLocator::locateField(mustBeStringKeyed($contents, 'contents'), 'email'))->toBeNull();
})->with([
    'no tabs' => [[]],
    'tabs not a map' => [['tabs' => 'main']],
    'handle absent' => [['tabs' => ['main' => ['sections' => [['fields' => [['handle' => 'title']]]]]]]],
]);
