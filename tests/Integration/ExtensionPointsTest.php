<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\FieldtypeRepository;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Contracts\FormSettingsSection;
use UAMSWeb\FormSettings\Contracts\SubmissionContext;
use UAMSWeb\FormSettings\CpForms\FormConfigurationBlueprintCustomizer;
use UAMSWeb\FormSettings\Fieldtypes\FormSettingsSubsection;
use UAMSWeb\FormSettings\Rules\SubfieldsAny;
use UAMSWeb\FormSettings\Support\NullSubmissionContext;

/**
 * The seams a host or another package uses: the submission context binding,
 * extra settings sections, the add-on's own validation message, and the
 * subsection fieldtype the settings screen relies on.
 *
 * @command  vendor/bin/pest tests/Integration/ExtensionPointsTest.php
 */

/**
 * The field handles of each section the customizer leaves in the main tab,
 * starting from core's own contents for a new form.
 *
 * @param  Form  $form  The form whose settings are being edited.
 * @return list<list<string>> Each section's field handles, in order.
 */
function extensionSectionHandles(Form $form): array
{
    $core = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/CpForms/core-form-settings-contents.json'), true, flags: JSON_THROW_ON_ERROR);
    $contents = app(FormConfigurationBlueprintCustomizer::class)->customizeBlueprintContents(mustBeStringKeyed($core, 'core contents'), $form);

    /** @var list<array{fields: list<array{handle: string}>}> $sections */
    $sections = data_get($contents, 'tabs.main.sections', []);

    return array_map(static fn (array $section): array => array_column($section['fields'], 'handle'), $sections);
}

/**
 * A section another package registers, which records the form it was given.
 */
final class ProbeFormSettingsSection implements FormSettingsSection
{
    /**
     * The handle of the last form the section was built for.
     */
    public static ?string $lastForm = null;

    /**
     * @param  string  $handle  The handle of the section's one field.
     */
    public function __construct(private string $handle = 'probe_setting') {}

    public function section(Form $form): array
    {
        $handle = $form->handle();
        self::$lastForm = is_string($handle) ? $handle : null;

        return [
            $this->handle => [
                'display' => 'Probe',
                'fields' => [
                    ['handle' => $this->handle, 'field' => ['type' => 'text', 'display' => 'Probe setting']],
                ],
            ],
        ];
    }
}

it('captures nothing by default, and lets a host bind its own submission context', function (): void {
    expect(app(SubmissionContext::class))->toBeInstanceOf(NullSubmissionContext::class);

    // A host's own context, bound over the default
    $hostContext = new class implements SubmissionContext
    {
        public function capture(Submission $submission): array
        {
            return ['ip' => '192.0.2.1'];
        }

        public function during(array $context, callable $send): mixed
        {
            return $send();
        }
    };
    app()->instance(SubmissionContext::class, $hostContext);

    expect(app(SubmissionContext::class))->toBe($hostContext);
});

it('adds no section of its own beyond the settings sections', function (): void {
    $form = mustBe(Form::class, FormFacade::make('extension_probe')->title('Probe'), 'form');

    expect(array_slice(extensionSectionHandles($form), -1))->toBe([['quiz_grading', 'quiz_pass_percent', 'quiz_grades']]);
});

it('injects tagged sections after its own, in the order they were tagged, for the form being edited', function (): void {
    // Two sections from another package, tagged in order
    app()->bind('probe.section.first', fn (): ProbeFormSettingsSection => new ProbeFormSettingsSection('probe_first'));
    app()->bind('probe.section.second', fn (): ProbeFormSettingsSection => new ProbeFormSettingsSection('probe_second'));
    app()->tag(['probe.section.first', 'probe.section.second'], FormSettingsSection::TAG);

    ProbeFormSettingsSection::$lastForm = null;

    $form = mustBe(Form::class, FormFacade::make('extension_probe')->title('Probe'), 'form');

    expect(array_slice(extensionSectionHandles($form), -3))->toBe([
        ['quiz_grading', 'quiz_pass_percent', 'quiz_grades'],
        ['probe_first'],
        ['probe_second'],
    ])->and(ProbeFormSettingsSection::$lastForm)->toBe('extension_probe');
});

it("refuses a group whose subfields are all empty, with the add-on's own message", function (): void {
    $validator = Validator::make(['address' => ['street' => '', 'city' => null]], ['address' => [new SubfieldsAny]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('address'))->toBe('At least one subfield of address must have a value.');
});

it('accepts a group with one subfield filled', function (): void {
    $validator = Validator::make(['address' => ['street' => '', 'city' => 'Little Rock']], ['address' => [new SubfieldsAny]]);

    expect($validator->passes())->toBeTrue();
});

it('registers its subsection fieldtype under its own handle, offered in no blueprint', function (): void {
    $fieldtype = mustBe(FormSettingsSubsection::class, app(FieldtypeRepository::class)->find('form_settings_subsection'), 'fieldtype');

    expect($fieldtype->component())->toBe('form_settings_subsection')
        ->and($fieldtype->selectable())->toBeFalse()
        ->and($fieldtype->selectableInForms())->toBeFalse()
        ->and($fieldtype->validatable())->toBeFalse();
});
