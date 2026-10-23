<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\CpForms\FormConfigurationBlueprintCustomizer;

/**
 * The complete CP form settings tree the app's customization produces (UAMS-Web/uams-statamic#3089).
 *
 * The injected sections and the email grid's extra fields are literal blueprint
 * structure: labels, instructions, conditions, validation rules and defaults.
 * Asserting the whole tree at once means a change to any of them fails here, so
 * each one is a deliberate fixture change rather than a silent edit.
 *
 * The input is core's own output for a new form, captured from statamic/cms
 * v6.35.1 without any add-on, so this pins only what this application adds. When
 * the customization changes on purpose, regenerate the expected fixture from the
 * same input and review its diff.
 *
 * @command  vendor/bin/pest tests/Integration/CpForms/FormSettingsCustomizedContentsTest.php
 */

/**
 * Read a JSON fixture from tests/Fixtures/CpForms.
 *
 * @return array<string, mixed>
 */
function formSettingsCustomizedContentsFixture(string $name): array
{
    $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/CpForms/'.$name), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

it("produces exactly the expected form settings tree from core's contents", function (): void {
    $log = Log::spy();
    $form = mustBe(Form::class, FormFacade::make('probe')->title('Probe'), 'form');

    $contents = app(FormConfigurationBlueprintCustomizer::class)
        ->customizeBlueprintContents(formSettingsCustomizedContentsFixture('core-form-settings-contents.json'), $form);

    // A validation rule object is compared by its class, the only part of it the tree defines
    array_walk_recursive($contents, function (mixed &$value): void {
        if (is_object($value)) {
            $value = 'object:'.$value::class;
        }
    });

    expect($contents)->toBe(formSettingsCustomizedContentsFixture('customized-form-settings-contents.json'));

    // Both customizer classes report that they found the section they extend
    $log->shouldNotHaveReceived('warning');
});
