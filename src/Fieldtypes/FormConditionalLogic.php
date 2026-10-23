<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Fieldtypes;

use Illuminate\Support\Collection;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\Field;
use Statamic\Fields\Fieldtype;
use Statamic\Forms\Form;

/**
 * Custom fieldtype for form conditional logic configuration.
 *
 * This fieldtype provides a UI for configuring conditional logic for form
 * confirmations, allowing users to specify when certain confirmations should
 * be triggered based on form field values.
 *
 * The fieldtype uses a forked version of Statamic's field-conditions Builder
 * component, customized for form-specific use cases.
 */
class FormConditionalLogic extends Fieldtype
{
    /**
     * The categories this fieldtype belongs to in the Statamic fieldtype selector.
     *
     * @var array<string> Categories this fieldtype belongs to in the Statamic fieldtype selector
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
    protected $component = 'form_conditional_logic';

    /**
     * The icon for this fieldtype.
     *
     * @return string The icon name
     */
    #[\Override]
    public function icon(): string
    {
        return 'edit-design-compare-ab-testing';
    }

    /**
     * Defines the configuration fields available for this fieldtype in the blueprint.
     *
     * @return list<array<string, mixed>> The configuration field items
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
                        'instructions' => __('Instructions to display to users configuring conditional logic.'),
                        'type' => 'textarea',
                        'default' => __('Configure when this confirmation should be triggered based on form field values.'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Preload config data for the Vue component.
     *
     * This method provides the form fields to the Vue component so it can
     * populate the field selection dropdowns.
     *
     * @return array<string, mixed> Preload data
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
        if ($formHandle) {
            // Narrow the form handle to a string for the facade lookup.
            // RemoveStringCast on the cast stays untested under PCOV (the
            // covering int→string path runs; the cast node is not attributed).
            if (\is_scalar($formHandle)) {
                // @pest-mutate-ignore: RemoveStringCast
                $formHandle = (string) $formHandle;
            } else {
                // Clear the form handle when the config value is non-scalar
                $formHandle = '';
            }

            // Find the form by handle (the facade returns the concrete form)
            /** @var Form $form */
            $form = FormFacade::find($formHandle);

            // Get form fields formatted for conditions
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
        /** @var Collection<string, Field> $formFields */
        $formFields = $form->fields();

        // Loop through each form field
        foreach ($formFields as $field) {
            // Get the field handle
            $handle = $field->handle();

            // Get the field display name or fallback to handle
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

        // Check if field config exists
        if ($field->config()) {
            // Get the field configuration
            /** @var array<string, mixed> $fieldConfig */
            $fieldConfig = $field->config();

            // Check if options exist in field config
            if (isset($fieldConfig['options'])) {
                // Get options from field config
                /** @var array<string, mixed> $options */
                $options = $fieldConfig['options'];
            }
        }

        // Clean up variables to free up memory
        unset(
            $fieldConfig
        );

        // Return the options array
        return $options;
    }
}
