<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Fields;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\CpForms\FormConfigurationBlueprintCustomizer;
use UAMSWeb\FormSettings\Http\Controllers\FormsController;

uses(MockeryPHPUnitIntegration::class);

/**
 * The CP form settings blueprint the app's controller builds on the shape
 * `statamic/cms` 6 emits (UAMS-Web/uams-statamic#3082).
 *
 * Core returns one tab holding a positional list of sections and discards the
 * keys it built them under, so customization gated on a `tabs.name` or
 * `tabs.email` key never ran. These build the blueprint through the app's own
 * `FormsController::editFormBlueprint()`, on core's real output, and read the
 * injected sections and email-grid rules from it.
 *
 * @command  vendor/bin/pest tests/Integration/CpForms/FormSettingsBlueprintTest.php
 */

/**
 * Build the form settings blueprint the app's controller renders and validates.
 */
function formSettingsBlueprint(): Blueprint
{
    $form = mustBe(Form::class, FormFacade::make('form_settings_blueprint_probe')->title('Probe'), 'form');

    // Call the controller's protected builder, which runs core and then the UAMS customization
    $blueprint = new ReflectionMethod(FormsController::class, 'editFormBlueprint')
        ->invoke(app(FormsController::class), $form);

    return mustBe(Blueprint::class, $blueprint, 'blueprint');
}

/**
 * The validation errors the blueprint reports for the given values, as the
 * Control Panel posts them: stored values pre-processed, with field defaults.
 *
 * @param  array<string, mixed>  $values  Stored form settings.
 * @return array<string, mixed> Errors keyed by field path, empty when the values pass.
 */
function formSettingsBlueprintErrors(array $values): array
{
    $blueprint = formSettingsBlueprint();

    // Pre-process the stored values the way the publish form does before posting
    $stored = mustBe(Fields::class, mustBe(Fields::class, $blueprint->fields(), 'fields')->addValues($values), 'fields');
    $posted = mustBe(Collection::class, mustBe(Fields::class, $stored->preProcess(), 'fields')->values(), 'values')->all();

    // Validate the posted values against every rule the blueprint binds
    $submitted = mustBe(Fields::class, mustBe(Fields::class, $blueprint->fields(), 'fields')->addValues($posted), 'fields');

    try {
        $submitted->validate();
    } catch (ValidationException $validationException) {
        /** @var array<string, mixed> $errors */
        $errors = $validationException->errors();

        return $errors;
    }

    return [];
}

/**
 * A default confirmation that satisfies its conditional rules (UAMS-Web/uams-statamic#3162): the type defaults to a
 * message, which a save must then supply.
 *
 * @return array<string, mixed>
 */
function formSettingsBlueprintValidConfirmation(): array
{
    return [
        'confirmation_settings_default' => [
            'confirmation_type' => 'message',
            'confirmation_message' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Thanks']]]],
        ],
    ];
}

/**
 * An email row that satisfies every rule the customized grid binds.
 *
 * @return array<string, mixed>
 */
function formSettingsBlueprintValidEmailRow(): array
{
    return [
        'name' => 'Notify the office',
        'enabled' => true,
        'to_type' => 'email',
        'to' => 'office@example.com',
        'cc_type' => 'email',
        'bcc_type' => 'email',
        'reply_to_type' => 'email',
        'subject' => 'New submission',
        'message' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]],
    ];
}

it('injects the five settings sections after the sections core emits', function (): void {
    // Read the field handles of each section in the tab the blueprint renders
    /** @var list<array{fields: list<array{handle: string}>}> $sections */
    $sections = data_get(formSettingsBlueprint()->contents(), 'tabs.main.sections', []);
    $handlesBySection = array_map(
        static fn (array $section): array => array_column($section['fields'], 'handle'),
        $sections
    );

    // Core's four sections keep their own fields, with description after name
    expect($handlesBySection[0])->toBe(['title'])
        ->and($handlesBySection[1])->toBe(['heading', 'description'])
        ->and($handlesBySection[2])->toBe(['blueprint', 'honeypot'])
        ->and($handlesBySection[3])->toBe(['store'])
        ->and($handlesBySection[4])->toBe(['email']);

    // The injected sections follow core's, in order, and nothing follows them
    expect(array_slice($handlesBySection, 5))->toBe([
        ['submit'],
        ['section_confirmation_default', 'confirmation_settings_default', 'section_confirmation_additional', 'confirmation_additional'],
        ['limit_entries', 'limit_entries_count', 'limit_entries_period', 'limit_entries_message', 'schedule_form_query', 'schedule_form', 'schedule_pending_message', 'schedule_expired_message', 'require_login_query', 'require_login_message'],
        ['quiz_grading', 'quiz_pass_percent', 'quiz_grades'],
    ]);
});

it('accepts a form carrying only a title and a confirmation message', function (): void {
    expect(formSettingsBlueprintErrors(['title' => 'Probe', ...formSettingsBlueprintValidConfirmation()]))->toBe([]);
});

it('refuses a form whose default confirmation message is empty, as the type defaults to a message', function (): void {
    expect(array_keys(formSettingsBlueprintErrors(['title' => 'Probe'])))
        ->toBe(['confirmation_settings_default.confirmation_message']);
});

it('accepts an email row that fills every required column', function (): void {
    expect(formSettingsBlueprintErrors([
        'title' => 'Probe',
        ...formSettingsBlueprintValidConfirmation(),
        'email' => [formSettingsBlueprintValidEmailRow()],
    ]))->toBe([]);
});

it('refuses an email row with a required column empty', function (string $column): void {
    $row = formSettingsBlueprintValidEmailRow();
    $row[$column] = '';

    $errors = formSettingsBlueprintErrors([
        'title' => 'Probe',
        ...formSettingsBlueprintValidConfirmation(),
        'email' => [$row],
    ]);

    expect(array_keys($errors))->toBe(['email.0.'.$column]);
})->with(['name', 'to_type', 'subject', 'message']);

it('refuses a recipient source without its value and accepts it once supplied', function (string $kind, string $source, string $column, mixed $value): void {
    // Choose the source and leave its value empty
    $row = [...formSettingsBlueprintValidEmailRow(), $kind.'_type' => $source];
    if ($kind === 'to') {
        $row['to'] = null;
    }

    $values = ['title' => 'Probe', ...formSettingsBlueprintValidConfirmation()];

    // Check the empty value is reported on its own row (UAMS-Web/uams-statamic#3162)
    expect(array_keys(formSettingsBlueprintErrors([...$values, 'email' => [$row]])))->toBe(['email.0.'.$column]);

    // Check the same row passes once the value is supplied
    expect(formSettingsBlueprintErrors([...$values, 'email' => [[...$row, $column => $value]]]))->toBe([]);
})->with([
    'TO address' => ['to', 'email', 'to', 'office@example.com'],
    'TO field' => ['to', 'field', 'to_field', 'email'],
    'TO routing' => ['to', 'routing', 'to_routing', [['field' => 'department', 'operator' => 'equals', 'value' => 'cardiology', 'email' => 'cardio@example.com']]],
    'CC field' => ['cc', 'field', 'cc_field', 'email'],
    'BCC routing' => ['bcc', 'routing', 'bcc_routing', [['field' => 'department', 'operator' => 'equals', 'value' => 'cardiology', 'email' => 'cardio@example.com']]],
    'Reply-To field' => ['reply_to', 'field', 'reply_to_field', 'email'],
]);

it('accepts an empty CC, BCC and Reply-To address, which leaves that header unsent', function (): void {
    $row = [...formSettingsBlueprintValidEmailRow(), 'cc' => null, 'bcc' => null, 'reply_to' => null];

    expect(formSettingsBlueprintErrors(['title' => 'Probe', ...formSettingsBlueprintValidConfirmation(), 'email' => [$row]]))->toBe([]);
});

it('refuses an empty title, the rule core binds itself', function (): void {
    expect(array_keys(formSettingsBlueprintErrors(['title' => '', ...formSettingsBlueprintValidConfirmation()])))->toBe(['title']);
});

it('logs and leaves the contents unchanged when neither customized section is present', function (): void {
    $log = Log::spy();

    $contents = ['tabs' => ['main' => ['sections' => [['display' => 'Other', 'fields' => [['handle' => 'other', 'field' => ['type' => 'text']]]]]]]];

    $out = app(FormConfigurationBlueprintCustomizer::class)->customizeBlueprintContents(
        $contents,
        mustBe(Form::class, FormFacade::make('form_settings_blueprint_probe'), 'form')
    );

    expect($out)->toBe($contents);

    $log->shouldHaveReceived('warning')
        ->with('Form configuration email grid not customized: no email grid in the blueprint contents.', ['form' => 'form_settings_blueprint_probe'])
        ->once();
    $log->shouldHaveReceived('warning')
        ->with('Form configuration sections not injected: no name section in the blueprint contents.', ['form' => 'form_settings_blueprint_probe'])
        ->once();
});
