<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Fieldtypes;

use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\Field;
use Statamic\Fields\Fieldtype;
use Statamic\Forms\Form;

/**
 * Custom fieldtype for selecting form fields that contain email addresses.
 *
 * This fieldtype provides a dropdown of form fields that have the 'email' type
 * and are marked as required, allowing users to select which form field should
 * be used as the email recipient for form notifications.
 *
 * The fieldtype uses a select component populated with required email-type fields
 * from the current form blueprint. Only required fields are included to ensure
 * that email notifications will only be sent to fields that are guaranteed to
 * have values when the form is submitted.
 */
class FormEmailFieldSelect extends Fieldtype
{
    /**
     * The categories this fieldtype belongs to in the Statamic fieldtype selector.
     *
     * @var array<string> Categories this fieldtype belongs to in the Statamic fieldtype selector.
     */
    protected $categories = [
        'forms',
        'special',
    ];

    /**
     * Indicates whether the fieldtype is selectable in collection blueprints.
     *
     * @var bool Indicates whether the fieldtype is selectable in collection blueprints
     */
    protected $selectable = false;

    /**
     * Indicates whether the fieldtype is selectable in form blueprints.
     *
     * @var bool Indicates whether the fieldtype is selectable in form blueprints
     */
    protected $selectableInForms = false;

    /**
     * Indicates whether the fieldtype's value can be localized.
     *
     * @var bool Indicates whether the fieldtype's value can be localized
     */
    protected $localizable = false;

    /**
     * The name of the Vue component to use for this fieldtype.
     *
     * @var string The name of the Vue component to use for this fieldtype
     */
    protected $component = 'form_email_field_select';

    /**
     * The icon for this fieldtype.
     *
     * @return string The icon name
     */
    #[\Override]
    public function icon(): string
    {
        return 'mail-sign-at';
    }

    /**
     * Defines the configuration fields available for this fieldtype in the blueprint.
     *
     * @return array<int, array{display: string, fields: array<string, array<string, mixed>>}> The configuration field items
     */
    #[\Override]
    protected function configFieldItems(): array
    {
        return [
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
        ];
    }

    /**
     * Preload config data for the Vue component.
     *
     * This method provides the form fields to the Vue component so it can
     * populate the field selection dropdown with only required email-type fields.
     *
     * @return array<string, mixed> The preloaded config data
     */
    #[\Override]
    public function preload(): array
    {
        // Get the fieldtype configuration, which carries the field's own form_handle
        /** @var array<string, mixed> $config */
        $config = $this->config();
        $formHandle = $config['form_handle'] ?? null;

        // Initialize the email fields array
        $emailFields = [];

        // List the email fields only for a usable handle naming a form that exists
        if (\is_scalar($formHandle) && $formHandle) {
            $form = FormFacade::find((string) $formHandle);

            if ($form instanceof Form) {
                $emailFields = $this->getEmailFieldsForSelection($form);
            }
        }

        // Return merged config with email fields
        return \array_merge(
            $config,
            [
                'email_fields' => $emailFields,
            ]
        );
    }

    /**
     * Get email fields formatted for the select component.
     *
     * This method filters form fields to only include email-type fields that
     * are marked as required. This ensures that email notifications will only
     * be sent to fields that are guaranteed to have values when the form is
     * submitted.
     *
     * @param  Form  $form  The form to get fields from
     * @return array<array<string, mixed>> Array of field objects in Statamic's expected format
     */
    private function getEmailFieldsForSelection(
        Form $form
    ): array {
        // Initialize the fields array
        $fields = [];

        // Get all fields from the form
        $formFields = $form->fields();

        // Loop through each form field
        foreach ($formFields as $field) {
            /** @var Field $field */
            // Get the field handle
            $handle = $field->handle();

            // Get the field display name or fallback to handle
            $display = $field->display() ?: $handle;

            // Get the field type
            $type = $field->type();

            // Get the field validation rules
            /** @var array<string> $validation */
            $validation = $field->get(
                'validate',
                []
            );

            // Check if the field has the 'email' type and is required
            if (
                $type === 'email'
                && $this->isFieldRequired($validation)
            ) {
                // Include the field
                $fields[] = [
                    'value' => $handle,
                    'label' => $display,
                ];
            }
        }

        // Clean up variables to free up memory
        unset(
            $formFields,
            $field,
            $handle,
            $display,
            $type,
            $validation
        );

        // Return the filtered fields array
        return $fields;
    }

    /**
     * Check if a field is required based on its validation rules.
     *
     * This method examines the field's validation rules to determine if it
     * is marked as required. It checks for various forms of required validation
     * including 'required', 'required_if', 'required_unless', etc.
     *
     * @param  array<string>  $validation  The field's validation rules
     * @return bool True if the field is required, false otherwise
     */
    private function isFieldRequired(
        array $validation
    ): bool {
        // Loop through each validation rule
        foreach ($validation as $rule) {
            // Check for simple 'required' rule
            if ($rule === 'required') {
                // Field is required, return true
                return true;
            }

            // ---
            // For conditional required rules, we'll include them as they might be required
            // depending on the form state, which is better than excluding potentially valid
            // fields
            // ---

            // Check for conditional required rules that might be required in some cases
            if (
                str_starts_with($rule, 'required_')
            ) {
                // Field has a conditional required rule, return true
                return true;
            }
        }

        // No required rules found, return false
        return false;
    }
}
