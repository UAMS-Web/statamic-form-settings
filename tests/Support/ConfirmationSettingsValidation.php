<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Tests\Support;

use Illuminate\Validation\Validator;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\Fields;
use Statamic\Fields\Validator as FieldsValidator;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\CpForms\FormConfigurationBlueprintCustomizer;

/**
 * Validates a form-settings save against the nested Control Panel blueprint (UAMS-Web/uams-statamic-archive#3162).
 *
 * The confirmation fields sit in the `confirmation_settings_default` group and, for each
 * additional confirmation, in the `confirmation_settings` group of a `confirmation_additional`
 * replicator set. Their conditional rules resolve against that nesting, so a flat field set
 * cannot show whether they work. This builds the form settings blueprint the way the Control
 * Panel does: core's own settings contents, captured in `tests/Fixtures/CpForms`, run through
 * the application's customizer. It then validates submitted values through Statamic's field
 * validator, the same path `FormsController::update()` takes.
 */
final class ConfirmationSettingsValidation
{
    /**
     * Path prefix of the default confirmation's settings group.
     */
    public const string DEFAULT = 'confirmation_settings_default';

    /**
     * Path prefix of the first additional confirmation's settings group.
     */
    public const string ADDITIONAL = 'confirmation_additional.0.confirmation_settings';

    /**
     * Validate a save whose confirmation settings sit at the given path.
     *
     * The rest of the payload is a valid form, so any error the validator reports comes from the
     * confirmation settings under test.
     *
     * @param  string  $path  Either DEFAULT or ADDITIONAL.
     * @param  array<string, mixed>  $settings  The confirmation settings group's submitted values.
     * @return Validator The Laravel validator, ready to be asked for its errors or values.
     */
    public static function validate(string $path, array $settings): Validator
    {
        // Place the settings in the default group, or in one additional confirmation
        $values = $path === self::DEFAULT
            ? [self::DEFAULT => $settings]
            : [
                self::DEFAULT => ['confirmation_type' => 'message', 'confirmation_message' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Thanks']]]]],
                'confirmation_additional' => [[
                    'type' => 'confirmation',
                    'confirmation_name' => 'Second',
                    'confirmation_settings' => $settings,
                ]],
            ];

        // Validate the full form settings payload against the nested blueprint
        $fields = mustBe(Fields::class, self::blueprint()->fields()->addValues(['title' => 'Probe', 'submit' => 'Send', ...$values]), 'the form settings fields');

        /** @var FieldsValidator $fieldsValidator */
        $fieldsValidator = $fields->validator();

        /** @var Validator $validator */
        $validator = $fieldsValidator->validator();

        return $validator;
    }

    /**
     * Read the rules the nested blueprint emits for one confirmation settings field.
     *
     * @param  string  $path  Either DEFAULT or ADDITIONAL.
     * @param  string  $handle  The confirmation settings field's handle.
     * @return list<mixed> The field's rules as Statamic hands them to Laravel.
     */
    public static function rules(string $path, string $handle): array
    {
        $rules = self::validate($path, ['confirmation_type' => 'message'])->getRules();

        return array_values(mustBeArray($rules[$path.'.'.$handle] ?? [], $path.'.'.$handle.' rules'));
    }

    /**
     * Build the form settings blueprint with the application's customizations applied.
     */
    private static function blueprint(): \Statamic\Fields\Blueprint
    {
        // Read core's own settings contents for a new form
        $core = json_decode(
            (string) file_get_contents(__DIR__.'/../Fixtures/CpForms/core-form-settings-contents.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        /** @var array<string, mixed> $core */
        $form = mustBe(Form::class, FormFacade::make('probe')->title('Probe'), 'form');

        // Apply the customizer that injects the confirmation sections
        $contents = app(FormConfigurationBlueprintCustomizer::class)->customizeBlueprintContents($core, $form);

        return mustBe(\Statamic\Fields\Blueprint::class, Blueprint::makeFromFields([])->setContents($contents), 'the form settings blueprint');
    }
}
