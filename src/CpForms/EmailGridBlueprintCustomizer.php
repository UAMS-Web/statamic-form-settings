<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\CpForms;

use Statamic\Forms\Form;

/**
 * Augments the Statamic CP email-grid blueprint for the add-on's form configuration.
 *
 * Adds the name column, active toggle, per-recipient type selectors
 * (TO / CC / BCC / Reply To), required subject, from-name, Bard message,
 * conditional-logic, role-key recipient instructions, and merge-tag
 * instructions.
 *
 * Consumed from FormConfigurationBlueprintCustomizer, which orchestrates this
 * class together with FormConfigurationTabsInjector.
 *
 * The finished columns are also given to the `email` grid of each `site_overrides` row, the
 * section the multisite forms add-on appends (UAMS-Web/uams-statamic#3152, UAMS-Web/uams-statamic#3164). An override row replaces the form's
 * emails on its site, so it offers exactly the base row's columns, in the same order, whatever
 * adds a column to the base grid.
 *
 * A recipient field's rules name its type field as `{this}.<kind>_type` (UAMS-Web/uams-statamic#3162): Statamic
 * substitutes the token with the row's own path, such as `email.0.`, so the rule reads the type
 * chosen on the same row, in the base grid and in an override row alike.
 */
final class EmailGridBlueprintCustomizer
{
    /**
     * Augment the email grid inside blueprint contents and append merge-tag
     * instructions.
     *
     * The grid is located through FormConfigurationSectionLocator by its
     * `email` handle, since core's blueprint keeps no section keys.
     *
     * @param  array<string, mixed>  $contents  Blueprint contents; updated in place when the email grid exists
     * @param  Form  $form  Form instance whose handle feeds dynamic fieldtypes
     * @return bool True when the grid was augmented, false when the contents carry no
     *              email grid with editable subfields and were left unchanged
     */
    public function customizeEmailGrid(
        array &$contents,
        Form $form
    ): bool {
        // Locate core's email grid by its field handle
        $location = FormConfigurationSectionLocator::locateField($contents, 'email');
        if ($location === null) {
            // Return early, reporting that nothing was augmented
            return false;
        }

        // Read the email grid entry from its section
        /** @var array{tabs: array<string, array{sections: array<int, array{fields: array<int, array<string, mixed>>}>}>} $contents */
        $emailField = $contents['tabs'][$location['tab']]['sections'][$location['section']]['fields'][$location['field']];

        // Check if the email field has a fields array for modification
        if (! isset($emailField['field']) || ! is_array($emailField['field']) || ! isset($emailField['field']['fields'])) {
            // Return early, reporting that nothing was augmented
            return false;
        }

        // ---
        // Inject the custom 'name' field as the first field in the email grid. This
        // ensures it appears at the top of the email configuration form.
        // ---
        $this->injectNameField($emailField);

        // Add active toggle to control email notification status
        $this->injectActiveToggleField($emailField);

        // Configure TO recipient type with conditional validation
        $this->injectRecipientTypeConfiguration(
            $emailField,
            $form,
            'TO',
            'to'
        );

        // Configure CC recipient type with conditional validation
        $this->injectRecipientTypeConfiguration(
            $emailField,
            $form,
            'CC',
            'cc'
        );

        // Configure BCC recipient type with conditional validation
        $this->injectRecipientTypeConfiguration(
            $emailField,
            $form,
            'BCC',
            'bcc'
        );

        // Configure Reply To recipient type with conditional validation
        $this->injectRecipientTypeConfiguration(
            $emailField,
            $form,
            'Reply To',
            'reply_to'
        );

        // Make subject field required for proper email configuration
        $this->makeSubjectFieldRequired($emailField);

        // Add from name field with conditional display
        $this->injectFromNameField($emailField);

        // Add rich message field for email content
        $this->injectMessageField($emailField);

        // Add conditional logic for email triggering
        $this->injectConditionalLogicField(
            $emailField,
            $form
        );

        // ---
        // Append custom instruction about admin_email variable to the email field
        // instructions
        // ---
        $this->appendAdminEmailInstruction($emailField);

        // Write the modified email grid back to its section
        $contents['tabs'][$location['tab']]['sections'][$location['section']]['fields'][$location['field']] = $emailField;

        // Offer the same columns on each site override row's email grid
        /** @var array{fields: list<mixed>} $customized */
        $customized = $emailField['field'];
        $this->mirrorColumnsToSiteOverrides($contents, $customized['fields']);

        // Report that the grid was augmented
        return true;
    }

    /**
     * Give the `site_overrides` grid's `email` subfield the base email grid's columns.
     *
     * Leaves the contents unchanged when the multisite forms add-on's Sites section is absent,
     * or when its override grid carries no `email` subfield.
     *
     * @param  array<string, mixed>  $contents  Blueprint contents; updated in place.
     * @param  list<mixed>  $columns  The base email grid's columns after customization.
     */
    private function mirrorColumnsToSiteOverrides(
        array &$contents,
        array $columns
    ): void {
        // Locate the add-on's override grid by its field handle
        $location = FormConfigurationSectionLocator::locateField($contents, 'site_overrides');
        if ($location === null) {
            return;
        }

        // Read the override grid's own columns
        /** @var array{tabs: array<string, array{sections: array<int, array{fields: array<int, array<string, mixed>>}>}>} $contents */
        $overrideGrid = $contents['tabs'][$location['tab']]['sections'][$location['section']]['fields'][$location['field']];
        $overrideField = $overrideGrid['field'] ?? null;
        if (! is_array($overrideField) || ! is_array($overrideField['fields'] ?? null)) {
            return;
        }

        // Replace the columns of the override row's email grid, keeping its own display settings
        $overrideColumns = $overrideField['fields'];
        foreach ($overrideColumns as $index => $column) {
            if (is_array($column) && ($column['handle'] ?? null) === 'email' && is_array($column['field'] ?? null)) {
                $column['field']['fields'] = $columns;
                $overrideColumns[$index] = $column;
            }
        }

        // Write the override grid back to its section
        $overrideField['fields'] = $overrideColumns;
        $overrideGrid['field'] = $overrideField;
        $contents['tabs'][$location['tab']]['sections'][$location['section']]['fields'][$location['field']] = $overrideGrid;
    }

    /**
     * Inject Name Field
     *
     * Injects the custom 'name' field as the first field in the email grid.
     * This allows users to provide descriptive names for their email configurations.
     *
     * @param  array<string, mixed>  &$emailField  The email field configuration (passed by reference)
     */
    private function injectNameField(
        array &$emailField
    ): void {
        // ---
        // Inject the custom 'name' field as the first field in the email grid. This
        // ensures it appears at the top of the email configuration form.
        // ---

        // Prepend the descriptive name column definition to the email grid rows
        /** @var array{fields: array<int, mixed>} $fieldConfig */
        $fieldConfig = &$emailField['field'];
        array_unshift(
            $fieldConfig['fields'],
            [
                'handle' => 'name',
                'field' => [
                    'type' => 'text',
                    'display' => __('Name'),
                    'instructions' => __('A descriptive name for this email configuration.'),
                    'validate' => [
                        'required',
                    ],
                ],
            ]
        );
    }

    /**
     * Inject Active Toggle Field
     *
     * Adds 'enabled' toggle field as the second field in the email grid.
     * This allows users to control whether this email notification is active.
     *
     * @param  array<string, mixed>  &$emailField  The email field configuration (passed by reference)
     */
    private function injectActiveToggleField(
        array &$emailField
    ): void {
        // ---
        // Add 'enabled' toggle field as the second field in the email grid. This allows
        // users to control whether this email notification is active.
        // ---

        // Insert the Active toggle immediately after the name column
        /** @var array{fields: array<int, mixed>} $fieldConfig */
        $fieldConfig = &$emailField['field'];
        array_splice(
            $fieldConfig['fields'],
            1,
            0,
            [
                [
                    'handle' => 'enabled',
                    'field' => [
                        'type' => 'toggle',
                        'display' => __('Active'),
                        'instructions' => __('Enable or disable this email notification.'),
                        'default' => true,
                    ],
                ],
            ]
        );
    }

    /**
     * Inject Recipient Type Configuration
     *
     * Generic method to add recipient type configuration for email fields.
     * Adds a type radio field and configures the recipient field with conditional
     * validation and display. Also adds field selection and routing fields with
     * conditional display based on the selected recipient type.
     *
     * This method can be used for 'to', 'cc', and 'bcc' recipient types by
     * providing the appropriate base label and handle parameters.
     *
     * @param  array<string, mixed>  &$emailField  The email field configuration (passed by reference)
     * @param  Form  $form  The form object being configured
     * @param  string  $baseLabel  The base label for the recipient type (e.g., 'TO', 'CC', 'BCC')
     * @param  string  $baseHandle  The base handle for the recipient type (e.g., 'to', 'cc', 'bcc')
     */
    private function injectRecipientTypeConfiguration(
        array &$emailField,
        Form $form,
        string $baseLabel,
        string $baseHandle
    ): void {
        // Initialize the recipient index to -1
        $recipientIndex = -1;

        // Narrow the nested grid configuration so its fields list is typed
        /** @var array{fields: array<int, array{handle?: string, field?: array<string, mixed>}>} $fieldConfig */
        $fieldConfig = &$emailField['field'];

        // Loop through all fields in the email grid to find the recipient field
        foreach ($fieldConfig['fields'] as $index => $field) {
            // Check if this field matches the base handle (to, cc, bcc, etc.)
            if (($field['handle'] ?? null) === $baseHandle) {
                // Store the index of the recipient field
                $recipientIndex = $index;

                // Exit loop once the recipient field is found
                break;
            }
        }

        // Check if the recipient field was not found
        if ($recipientIndex === -1) {
            // Return early
            return;
        }

        // ---
        // Add type radio field before the recipient field. This allows users to choose
        // how to specify email recipients.
        // ---
        $typeField = [
            'handle' => $baseHandle.'_type',
            'field' => [
                'type' => 'radio',
                'display' => __($baseLabel.' Recipient Type'),
                'options' => [
                    [
                        'key' => 'email',
                        'value' => __('Enter One or More Email Addresses'),
                    ],
                    [
                        'key' => 'field',
                        'value' => __('Select a Form Field'),
                    ],
                    [
                        'key' => 'routing',
                        'value' => __('Configure Conditional Routing'),
                    ],
                ],
                'default' => 'email',
                'validate' => [
                    'required',
                ],
            ],
        ];

        // Insert the type field before the recipient field
        array_splice(
            $fieldConfig['fields'],
            $recipientIndex,
            0,
            [$typeField]
        );

        // Update recipient index since we inserted a field before it
        $recipientIndex++;

        // Modify the recipient field to have conditional validation and display
        $fieldConfig['fields'][$recipientIndex]['field']['display'] = __($baseLabel.' Recipient Email Address(es)');

        // Add conditional display logic for the recipient field
        $fieldConfig['fields'][$recipientIndex]['field']['if'] = [
            $baseHandle.'_type' => 'equals email',
        ];

        // ---
        // Add conditional validation rules for the recipient field. Only the TO address must be
        // entered; an empty CC, BCC or Reply-To address means that header is not sent.
        // ---
        $fieldConfig['fields'][$recipientIndex]['field']['validate'] = $baseHandle === 'to'
            ? [
                sprintf('exclude_unless:{this}.%s_type,email', $baseHandle),
                sprintf('required_if:{this}.%s_type,email', $baseHandle),
            ]
            : [
                sprintf('exclude_unless:{this}.%s_type,email', $baseHandle),
                'nullable',
            ];

        // Add field selection field after the recipient field with conditional display
        $fieldSelectionField = [
            'handle' => $baseHandle.'_field',
            'field' => [
                'type' => 'form_email_field_select',
                'display' => __($baseLabel.' Recipient(s) Field'),
                'instructions' => __('Select a required form field that contains email addresses.'),
                'form_handle' => $form->handle(),
                'if' => [
                    $baseHandle.'_type' => 'equals field',
                ],
                'validate' => [
                    sprintf('exclude_unless:{this}.%s_type,field', $baseHandle),
                    sprintf('required_if:{this}.%s_type,field', $baseHandle),
                ],
            ],
        ];

        // Add routing grid field after the recipient field with conditional display
        $routingField = [
            'handle' => $baseHandle.'_routing',
            'field' => [
                'type' => 'form_email_routing',
                'display' => __($baseLabel.' Recipients Conditional Routing'),
                'instructions' => __('Configure conditional routing rules for email recipients.'),
                'form_handle' => $form->handle(),
                'if' => [
                    $baseHandle.'_type' => 'equals routing',
                ],
                'validate' => [
                    sprintf('exclude_unless:{this}.%s_type,routing', $baseHandle),
                    sprintf('required_if:{this}.%s_type,routing', $baseHandle),
                ],
            ],
        ];

        // Insert the new fields after the recipient field
        array_splice(
            $fieldConfig['fields'],
            $recipientIndex + 1,
            0,
            [$fieldSelectionField, $routingField]
        );
    }

    /**
     * Make Subject Field Required
     *
     * Makes the 'subject' field required to ensure proper email configuration.
     *
     * @param  array<string, mixed>  &$emailField  The email field configuration (passed by reference)
     */
    private function makeSubjectFieldRequired(
        array &$emailField
    ): void {
        // Narrow the nested grid configuration so its fields list is typed
        /** @var array{fields: array<int, array{handle?: string, field?: array<string, mixed>}>} $fieldConfig */
        $fieldConfig = &$emailField['field'];

        // Loop through all fields in the email grid to find the subject field
        foreach ($fieldConfig['fields'] as $index => $field) {
            // Check if this field is the subject field
            if (($field['handle'] ?? null) === 'subject') {
                // Set the subject field as required
                $fieldConfig['fields'][$index]['field']['validate'] = ['required'];

                // Exit loop once the subject field is found and modified
                break;
            }
        }
    }

    /**
     * Inject From Name Field
     *
     * Adds 'from_name' field after 'from' field with conditional display.
     * This allows users to specify a display name for the sender.
     *
     * @param  array<string, mixed>  &$emailField  The email field configuration (passed by reference)
     */
    private function injectFromNameField(
        array &$emailField
    ): void {
        // ---
        // Add 'from_name' field after 'from' field with conditional display. This allows
        // users to specify a display name for the sender.
        // ---
        $fromNameField = [
            'handle' => 'from_name',
            'field' => [
                'type' => 'text',
                'display' => __('From Name'),
                'instructions' => __('Enter a concise, recognizable name (e.g., "Acme Support") to appear as the sender in the recipient\'s inbox. Avoid email addresses or long phrases.'),
                'if' => [
                    'from' => 'not empty',
                ],
            ],
        ];

        // Initialize the from index to -1
        $fromIndex = -1;

        // Narrow the nested grid configuration so its fields list is typed
        /** @var array{fields: array<int, array<string, mixed>>} $fieldConfig */
        $fieldConfig = &$emailField['field'];

        // Loop through all fields in the email grid to find the 'from' field
        foreach ($fieldConfig['fields'] as $index => $field) {
            // Check if this field is the 'from' field
            if ($field['handle'] === 'from') {
                // Store the index of the 'from' field
                $fromIndex = $index;

                // Exit loop once the 'from' field is found
                break;
            }
        }

        // ---
        // Insert the from_name field after the from field
        // ---

        // Check if the 'from' field was found before inserting the from_name field
        if ($fromIndex !== -1) {
            // Insert the from_name field after the 'from' field
            array_splice(
                $fieldConfig['fields'],
                $fromIndex + 1,
                0,
                [$fromNameField]
            );
        }
    }

    /**
     * Inject Message Field
     *
     * Adds email message field between 'text' and 'markdown' fields.
     * This provides a rich text editor for email content.
     *
     * @param  array<string, mixed>  &$emailField  The email field configuration (passed by reference)
     */
    private function injectMessageField(
        array &$emailField
    ): void {
        // Define the Bard-backed message column configuration for the grid
        $messageField = [
            'handle' => 'message',
            'field' => [
                ...FieldDefinitions::bardEmailMessage(),
                'display' => __('Message'),
                'instructions' => __('This is the content of the email message.'),
                'validate' => [
                    'required',
                ],
            ],
        ];

        // Initialize the text index to -1
        $textIndex = -1;

        // Narrow the nested grid configuration so its fields list is typed
        /** @var array{fields: array<int, array<string, mixed>>} $fieldConfig */
        $fieldConfig = &$emailField['field'];

        // Loop through all fields in the email grid to find the 'text' field
        foreach ($fieldConfig['fields'] as $index => $field) {
            // Check if this field is the 'text' field
            if ($field['handle'] === 'text') {
                // Store the index of the 'text' field
                $textIndex = $index;

                // Exit loop once the 'text' field is found
                break;
            }
        }

        // ---
        // Insert the 'message' field after the text field (before markdown)
        // ---

        // Check if the 'text' field was found before inserting the message field
        if ($textIndex !== -1) {
            array_splice(
                $fieldConfig['fields'],
                $textIndex + 1,
                0,
                [$messageField]
            );
        }
    }

    /**
     * Inject Conditional Logic Field
     *
     * Adds conditional logic field to the email grid. This allows users to
     * configure when emails should be sent based on form field values.
     *
     * @param  array<string, mixed>  &$emailField  The email field configuration (passed by reference)
     * @param  Form  $form  The form object being configured
     */
    private function injectConditionalLogicField(
        array &$emailField,
        Form $form
    ): void {
        // ---
        // Add conditional logic field to the email grid. This allows users to configure
        // when emails should be sent based on form field values.
        // ---

        // Define the conditional logic column bound to this form handle
        $conditionalLogicField = [
            'handle' => 'conditional_logic',
            'field' => [
                'type' => 'form_conditional_logic',
                'display' => __('Conditional Logic'),
                'instructions' => __('Configure when this email should be sent based on form field values.'),
                'form_handle' => $form->handle(),
                'config' => [
                    'form_handle' => $form->handle(),
                    'instructions' => __('Set conditions for when this email should be triggered. Leave empty to always send.'),
                ],
            ],
        ];

        // Add the conditional logic field at the end of the email grid
        /** @var array{fields: array<int, mixed>} $fieldConfig */
        $fieldConfig = &$emailField['field'];
        $fieldConfig['fields'][] = $conditionalLogicField;
    }

    /**
     * Append recipient-config and merge-tag instructions to the email grid.
     *
     * Documents role-key recipients (UAMS-Web/uams-statamic#2745) and the catalogue of available
     * merge tags so editors see both where they configure notifications.
     *
     * @param  array<string, mixed>  &$emailField  The email grid entry (passed by reference)
     */
    private function appendAdminEmailInstruction(
        array &$emailField
    ): void {
        // Check if the email field has an editable instructions string
        $fieldDef = $emailField['field'] ?? null;
        if (is_array($fieldDef) && isset($fieldDef['instructions'])) {
            // Append the merge tags instruction to existing instructions
            // The result is only concatenated, which casts a scalar exactly as `(string)` does.
            // @pest-mutate-ignore: RemoveStringCast
            $existingInstructions = is_scalar($fieldDef['instructions'])
                ? (string) $fieldDef['instructions']
                : '';

            // Document how recipients are set (UAMS-Web/uams-statamic#2818) before the merge-tag
            // catalogue: form configurations live in the private forms
            // submodule, so a real address can go directly into To.
            $recipientsInstruction = "\n\n**Notification recipients:**\n".
                '- Enter the address (or addresses) the notification goes to in To / CC / BCC. '.
                "Form settings are kept in a private repository, so an address here is not public.\n".
                '- A form may still name a role key such as '.
                "`{{ config:forms.recipients.institutional_relations }}`; it sends to that key's address ".
                "until it is replaced with an address.\n".
                '- How recipients are set is described in `docs/forms/notification-recipients.md`.';

            // Create comprehensive merge tags documentation
            $mergeTagsInstruction = "\n\n**Available Merge Tags:**\n".
                "- `{{ admin_email }}` - Site default email address\n".
                "- `{{ form:title }}` - Form title\n".
                "- `{{ form:handle }}` - Form handle/ID\n".
                "- `{{ submission:id }}` - Form submission/entry ID\n".
                "- `{{ submission:<handle> }}` - Access form field values (replace `<handle>` with field handle)\n".
                "  - Example: `{{ submission:email }}` for email field, `{{ submission:name }}` for name field\n".
                "- `{{ all_fields }}` - All form fields in a formatted table\n".
                "  - `{{ all_fields show_value=\"true\" }}` - Show actual values instead of labels\n".
                "  - `{{ all_fields show_empty=\"true\" }}` - Show fields with no submitted values\n".
                "  - `{{ all_fields hide_hidden=\"true\" }}` - Hide hidden field types\n".
                "- `{{ referrer }}` - Referring page URL\n".
                "- `{{ ip }}` - Submitter's IP address\n".
                "- `{{ today }}` - Current date (Jan. 1, 2025)\n".
                "- `{{ today:time }}` - Current time (1:23 a.m.)\n".
                "- `{{ today:timestamp }}` - Unix timestamp\n".
                '- `{{ today format="Y-m-d" }}` - Custom date format'.
                $this->quizMergeTagHelp();

            // Append recipient then merge-tag instructions to existing instructions
            $fieldDef['instructions'] = $existingInstructions
                .$recipientsInstruction
                .$mergeTagsInstruction;
            $emailField['field'] = $fieldDef;
        }
    }

    /**
     * The merge-tag help for quiz and survey scoring.
     *
     * @return string Markdown lines starting with a newline, or an empty
     *                string when the host does not score quizzes.
     */
    private function quizMergeTagHelp(): string
    {
        if (! config('statamic-form-settings.quiz_scoring', false)) {
            return '';
        }

        return
            "\n- `{{ quiz }}` - Quiz question with answer and correct answer\n".
            "  - `{{ quiz handle=\"*\" }}` - Specific quiz question\n".
            "- `{{ quiz:grade }}` - Quiz letter grade (A, B, C, D, F)\n".
            "- `{{ quiz:passfail }}` - Quiz pass/fail result\n".
            "- `{{ quiz:percent }}` - Quiz percentage score\n".
            "- `{{ quiz:score }}` - Quiz total score\n".
            "  - `{{ quiz:score handle=\"*\" }}` - Specific quiz question score\n".
            "- `{{ survey }}` - Survey question with answer\n".
            "  - `{{ survey handle=\"*\" }}` - Specific survey question\n".
            "- `{{ survey:score }}` - Survey total score\n".
            '  - `{{ survey:score handle="*" }}` - Specific survey question score';
    }
}
