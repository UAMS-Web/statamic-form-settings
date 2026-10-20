<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Fieldtypes;

use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\Field;
use Statamic\Fields\Fieldtype;
use Statamic\Forms\Form;

/**
 * Custom fieldtype for configuring conditional email routing rules.
 *
 * This fieldtype provides a UI for configuring conditional routing rules for
 * email notifications, allowing users to specify different email recipients
 * based on form field values.
 *
 * The fieldtype uses a grid component with conditional logic rules plus
 * email address fields for each rule.
 */
class FormEmailRouting extends Fieldtype
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
    protected $component = 'form_email_routing';

    /**
     * The icon for this fieldtype.
     *
     * @return string The icon name
     */
    #[\Override]
    public function icon(): string
    {
        return 'mail-settings';
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
                        'instructions' => __('Instructions to display to users configuring email routing.'),
                        'type' => 'textarea',
                        'default' => __('Configure conditional routing rules for email recipients.'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Preload config data for the Vue component.
     *
     * This method provides the form fields to the Vue component so it can
     * populate the field selection dropdowns and configure routing rules.
     *
     * @return array<string, mixed> The preloaded config data
     */
    #[\Override]
    public function preload(): array
    {
        // Get the fieldtype configuration
        /** @var array<string, mixed> $config */
        $config = $this->config();

        // Narrow the fieldtype's field to the concrete Field class
        /** @var Field $field */
        $field = $this->field;

        // Try to get form handle from multiple sources. `$this->config()` already
        // merges field config with config-field defaults, so the coalesce's
        // right-hand `$field->get()` is equivalent when no default exists:
        // CoalesceRemoveLeft is inert here.
        // @pest-mutate-ignore: CoalesceRemoveLeft
        $formHandle = $config['form_handle'] ?? $field->get('form_handle');

        // ---
        // Get form fields if form handle is provided
        // ---

        // Initialize the suggestable fields array
        $suggestableFields = [];

        // Check if form handle is provided
        if (\is_scalar($formHandle) && $formHandle) {
            // Find the form by handle (the facade returns the concrete form).
            // RemoveStringCast on the cast stays untested under PCOV (the
            // covering int→string path runs; the cast node is not attributed).
            // @pest-mutate-ignore: RemoveStringCast
            /** @var Form $form */
            $form = FormFacade::find((string) $formHandle);

            // Get form fields formatted for conditional logic
            $suggestableFields = $this->getFormFieldsForConditions($form);
        }

        // Return merged config with suggestable fields
        return \array_merge(
            $config,
            [
                'suggestable_fields' => $suggestableFields,
            ]
        );
    }

    /**
     * Get form fields formatted for the conditional logic component.
     *
     * @param  Form  $form  The form to get fields from
     * @return array<array<string, mixed>> Array of field objects in Statamic's expected format
     */
    private function getFormFieldsForConditions(
        Form $form
    ): array {
        // Initialize the fields array
        $fields = [];

        // Get all fields from the form
        /** @var iterable<Field> $formFields */
        $formFields = $form->fields();

        // Loop through each form field
        foreach ($formFields as $field) {
            // Get the field handle
            $handle = $field->handle();

            // Get the field display name or fall back to handle
            $display = $field->display() ?: $handle;

            // Get the field type
            $type = $field->type();

            // Check if the field type is one that doesn't make sense for conditions
            if (
                \in_array(
                    $type,
                    [
                        'html',
                        'section',
                        'subsection',
                        'divider',
                    ]
                )
            ) {
                // Skip the field
                continue;
            }

            // Format the field as expected by Statamic's Condition.vue component
            $fields[] = [
                'handle' => $handle,
                'config' => [
                    'display' => $display,
                    'type' => $type,
                    'options' => $this->getFieldOptions($field),
                ],
            ];
        }

        // Clean up variables to free up memory
        unset(
            $formFields,
            $field,
            $handle,
            $display,
            $type
        );

        // Return the formatted fields array
        return $fields;
    }

    /**
     * Get field options for select-type fields.
     *
     * @param  Field  $field  The field to get options for
     * @return array<string, mixed> Array of field options
     */
    private function getFieldOptions(
        Field $field
    ): array {
        // Initialize the options array
        /** @var array<string, mixed> $options */
        $options = [];

        // ---
        // Get options from field config if available
        // ---

        // Get the field configuration
        $fieldConfig = $field->config();

        // Check if options are defined in field config
        if (isset($fieldConfig['options'])) {
            // Get the field options from config
            $fieldOptions = $fieldConfig['options'];

            // Check if field options is an array
            if (\is_array($fieldOptions)) {
                // Loop through each field option
                foreach ($fieldOptions as $key => $value) {
                    // Use 'value' key if array format, otherwise use value directly.
                    // RemoveStringCast on the key cast stays untested under PCOV
                    // (int keys are exercised; the cast node is not attributed).
                    // @pest-mutate-ignore: RemoveStringCast
                    $optionKey = (string) $key;
                    $options[$optionKey] = \is_array($value) ? ($value['value'] ?? $value) : $value;
                }
            }
        }

        // Clean up variables to free up memory
        unset(
            $fieldOptions,
            $key,
            $value
        );

        // Return the options array
        return $options;
    }
}
