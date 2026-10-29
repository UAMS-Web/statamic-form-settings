<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\CpForms;

use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Contracts\FormSettingsSection;
use UAMSWeb\FormSettings\Features;
use UAMSWeb\FormSettings\Rules\SubfieldsAny;

/**
 * Injects the add-on's blueprint sections into the Statamic CP form-configuration
 * blueprint.
 *
 * Adds the Heading/Description, Submit, Confirmations, Restrictions, and Quiz
 * Settings sections, then every section tagged {@see FormSettingsSection::TAG},
 * to the tab holding Statamic's built-in name section, which
 * is located through FormConfigurationSectionLocator by its `title` field. Uses
 * ConfirmationSettingsFieldBuilder for the shared confirmation field set that
 * appears in both the default group and the additional-confirmations replicator.
 *
 * Consumed from FormConfigurationBlueprintCustomizer, which orchestrates this
 * class together with EmailGridBlueprintCustomizer.
 */
final readonly class FormConfigurationTabsInjector
{
    /**
     * @param  ConfirmationSettingsFieldBuilder  $fieldBuilder  Builds the reusable confirmation settings fields.
     */
    public function __construct(
        private ConfirmationSettingsFieldBuilder $fieldBuilder,
    ) {}

    /**
     * Inject description, submit, confirmation, restrictions, and quiz
     * sections into blueprint contents.
     *
     * @param  array<string, mixed>  $contents  Blueprint contents; updated in place when the name section exists.
     * @param  Form  $form  Form instance whose handle feeds dynamic fieldtypes.
     * @return bool True when the sections were injected, false when the contents carry
     *              no section holding the `title` field and were left unchanged.
     */
    public function injectFormConfigurationTabs(
        array &$contents,
        Form $form
    ): bool {
        // Locate core's name section by the title field it carries
        $nameLocation = FormConfigurationSectionLocator::locateField($contents, 'title');
        if ($nameLocation === null) {
            // Return early, reporting that nothing was injected
            return false;
        }

        // ---
        // Create the heading and description section with the correct structure. The
        // fields need to be wrapped in a Collection and have handle/field structure.
        // ---
        $descriptionSection = [
            'description' => [
                'display' => __('Heading and Description'),
                'fields' => [
                    'heading' => [
                        'handle' => 'heading',
                        'field' => [
                            'type' => 'text',
                            'display' => __('Form Heading'),
                            'instructions' => __('Provide a brief heading for this form. This heading may be displayed with the form on the page.'),
                        ],
                    ],
                    'description' => [
                        'handle' => 'description',
                        'field' => [
                            'type' => 'textarea',
                            'instructions' => __("Provide a brief description of this form's purpose and what users can expect when submitting it. This description may be displayed with the form on the page. There is a 290-character limit."),
                            'character_limit' => 290,
                        ],
                    ],
                ],
            ],
        ];

        // Inject the description section after the name section
        $this->injectSection(
            $contents,
            $nameLocation,
            $descriptionSection,
            'after'
        );

        // Create the submit button label section
        $submitButtonSection = [
            'submit' => [
                'display' => __('Submit'),
                'fields' => [
                    'submit' => [
                        'handle' => 'submit',
                        'field' => [
                            'type' => 'text',
                            'display' => __('Submit Button Label'),
                            'instructions' => __('Customize the text displayed on the form submit button.'),
                            'validate' => [
                                'required',
                            ],
                            'default' => 'Submit',
                        ],
                    ],
                ],
            ],
        ];

        // Inject the submit button section at the end of all sections
        $this->injectSection(
            $contents,
            $nameLocation,
            $submitButtonSection,
            'end'
        );

        // Create the confirmation section
        $confirmationSection = [
            'confirmation' => [
                'display' => __('Confirmations'),
                'fields' => [
                    'section_confirmation_default' => [
                        'handle' => 'section_confirmation_default',
                        'field' => [
                            'type' => 'form_settings_subsection',
                            'display' => __('Default Confirmation'),
                        ],
                    ],
                    'confirmation_settings_default' => [
                        'handle' => 'confirmation_settings_default',
                        'field' => [
                            'type' => 'group',
                            'display' => __('Default Confirmation Settings'),
                            'replicator_preview' => false,
                            'fields' => $this->fieldBuilder->build('confirmation_settings_default'),
                        ],
                    ],
                    'section_confirmation_additional' => [
                        'handle' => 'section_confirmation_additional',
                        'field' => [
                            'type' => 'form_settings_subsection',
                            'display' => __('Additional Confirmations'),
                        ],
                    ],
                    'confirmation_additional' => [
                        'handle' => 'confirmation_additional',
                        'field' => [
                            'type' => 'replicator',
                            'display' => __('Additional Confirmations'),
                            'collapse' => true,
                            'button_label' => __('Add Confirmation'),
                            'sets' => [
                                'confirmation' => [
                                    'display' => __('Confirmation'),
                                    'fields' => [
                                        'confirmation_name' => [
                                            'handle' => 'confirmation_name',
                                            'field' => [
                                                'type' => 'text',
                                                'display' => __('Confirmation Name'),
                                            ],
                                        ],
                                        'confirmation_settings' => [
                                            'handle' => 'confirmation_settings',
                                            'field' => [
                                                'type' => 'group',
                                                'display' => __('Confirmation Settings'),
                                                'replicator_preview' => false,
                                                'fields' => $this->fieldBuilder->build('confirmation_settings'),
                                            ],
                                        ],
                                        'conditional_logic' => [
                                            'handle' => 'conditional_logic',
                                            'field' => [
                                                'type' => 'form_conditional_logic',
                                                'display' => __('Conditional Logic'),
                                                'instructions' => __('Configure when this confirmation should be triggered based on form field values.'),
                                                'form_handle' => $form->handle(),
                                                'config' => [
                                                    'form_handle' => $form->handle(),
                                                    'instructions' => __('Set conditions for when this confirmation should be triggered. Leave empty to always trigger.'),
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // ---
        // Inject the confirmation section at the end of all sections (after the submit
        // section)
        // ---
        $this->injectSection(
            $contents,
            $nameLocation,
            $confirmationSection,
            'end'
        );

        // Create the restrictions section
        $restrictionsSection = [
            'restrictions' => [
                'display' => __('Restrictions'),
                'fields' => [
                    'limit_entries' => [
                        'handle' => 'limit_entries',
                        'field' => [
                            'type' => 'toggle',
                            'display' => __('Limit the Number of Entries'),
                            'instructions' => __('The form will become inactive when that limit is reached.'),
                        ],
                    ],
                    'limit_entries_count' => [
                        'handle' => 'limit_entries_count',
                        'field' => [
                            'type' => 'integer',
                            'display' => __('Number of Entries Allowed'),
                            'if' => [
                                'limit_entries' => 'equals true',
                            ],
                            'validate' => [
                                'exclude_unless:limit_entries,true',
                                'required_if:limit_entries,true',
                            ],
                        ],
                    ],
                    'limit_entries_period' => [
                        'handle' => 'limit_entries_period',
                        'field' => [
                            'type' => 'select',
                            'display' => __('Type of Limit'),
                            'options' => [
                                [
                                    'key' => 'total',
                                    'value' => __('Total Entries'),
                                ],
                                [
                                    'key' => 'day',
                                    'value' => __('Per Day'),
                                ],
                                [
                                    'key' => 'week',
                                    'value' => __('Per Week'),
                                ],
                                [
                                    'key' => 'month',
                                    'value' => __('Per Month'),
                                ],
                                [
                                    'key' => 'year',
                                    'value' => __('Per Year'),
                                ],
                            ],
                            'default' => 'total',
                            'if' => [
                                'limit_entries' => 'equals true',
                            ],
                            'validate' => [
                                'exclude_unless:limit_entries,true',
                                'required_if:limit_entries,true',
                            ],
                        ],
                    ],
                    'limit_entries_message' => [
                        'handle' => 'limit_entries_message',
                        'field' => [
                            'type' => 'textarea',
                            'display' => __('Message to Display When the Entry Limit is Reached'),
                            'if' => [
                                'limit_entries' => 'equals true',
                            ],
                            'validate' => [
                                'exclude_unless:limit_entries,true',
                                'required_if:limit_entries,true',
                            ],
                        ],
                    ],
                    'schedule_form_query' => [
                        'handle' => 'schedule_form_query',
                        'field' => [
                            'type' => 'toggle',
                            'display' => __('Schedule the Form'),
                            'instructions' => __('Schedule a time period the form is active.'),
                        ],
                    ],
                    'schedule_form' => [
                        'handle' => 'schedule_form',
                        'field' => [
                            'type' => 'group',
                            'display' => __('Form Schedule'),
                            'if' => [
                                'schedule_form_query' => 'equals true',
                            ],
                            'validate' => [
                                'exclude_unless:schedule_form_query,true',
                                new SubfieldsAny,
                            ],
                            'fields' => [
                                'start_datetime' => [
                                    'handle' => 'start_datetime',
                                    'field' => [
                                        'type' => 'date',
                                        'display' => __('Start Date and Time'),
                                        'time_enabled' => true,
                                    ],
                                ],
                                'end_datetime' => [
                                    'handle' => 'end_datetime',
                                    'field' => [
                                        'type' => 'date',
                                        'display' => __('End Date and Time'),
                                        'time_enabled' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'schedule_pending_message' => [
                        'handle' => 'schedule_pending_message',
                        'field' => [
                            'type' => 'textarea',
                            'display' => __('Message to Display When the Form is Pending'),
                            'if' => [
                                'schedule_form_query' => 'equals true',
                            ],
                            'validate' => [
                                'exclude_unless:schedule_form_query,true',
                                'required_if:schedule_form_query,true',
                            ],
                        ],
                    ],
                    'schedule_expired_message' => [
                        'handle' => 'schedule_expired_message',
                        'field' => [
                            'type' => 'textarea',
                            'display' => __('Message to Display When the Form Has Expired'),
                            'if' => [
                                'schedule_form_query' => 'equals true',
                            ],
                            'validate' => [
                                'exclude_unless:schedule_form_query,true',
                                'required_if:schedule_form_query,true',
                            ],
                        ],
                    ],
                    'require_login_query' => [
                        'handle' => 'require_login_query',
                        'field' => [
                            'type' => 'toggle',
                            'display' => __('Require the User to Be Logged In'),
                            'instructions' => __('Require the user to be logged in to view this form.'),
                        ],
                    ],
                    'require_login_message' => [
                        'handle' => 'require_login_message',
                        'field' => [
                            'type' => 'textarea',
                            'display' => __('Message to Display When the User is Not Logged In'),
                            'if' => [
                                'require_login_query' => 'equals true',
                            ],
                            'validate' => [
                                'exclude_unless:require_login_query,true',
                                'required_if:require_login_query,true',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // ---
        // Inject the restrictions section at the end of all sections (after the
        // confirmation section)
        // ---
        $this->injectSection(
            $contents,
            $nameLocation,
            $restrictionsSection,
            'end'
        );

        // Create the quiz settings section (UAMS-Web/uams-statamic-archive#1146). Drives the {{ quiz:grade }} /
        // {{ quiz:passfail }} merge tags via QuizScorer, which reads the
        // quiz_pass_percent integer and the quiz_grades [{text, value}] rows.
        $quizSettingsSection = [
            'quiz_settings' => [
                'display' => __('Quiz Settings'),
                'fields' => [
                    'quiz_grading' => [
                        'handle' => 'quiz_grading',
                        'field' => [
                            'type' => 'radio',
                            'display' => __('Grading'),
                            'instructions' => __('How to grade a quiz submission. Applies only to forms that use the quiz fieldtype; leave on "No grading" for non-quiz forms.'),
                            'options' => [
                                [
                                    'key' => 'none',
                                    'value' => __('No grading'),
                                ],
                                [
                                    'key' => 'passfail',
                                    'value' => __('Pass / Fail'),
                                ],
                                [
                                    'key' => 'letter',
                                    'value' => __('Letter grades'),
                                ],
                            ],
                            'default' => 'none',
                        ],
                    ],
                    'quiz_pass_percent' => [
                        'handle' => 'quiz_pass_percent',
                        'field' => [
                            'type' => 'integer',
                            'display' => __('Passing Percentage'),
                            'instructions' => __('The minimum score percentage (0–100) required to pass.'),
                            'if' => [
                                'quiz_grading' => 'equals passfail',
                            ],
                            'validate' => [
                                'exclude_unless:quiz_grading,passfail',
                                'required_if:quiz_grading,passfail',
                                'integer',
                                'between:0,100',
                            ],
                        ],
                    ],
                    'quiz_grades' => [
                        'handle' => 'quiz_grades',
                        'field' => [
                            'type' => 'grid',
                            'display' => __('Letter Grades'),
                            'instructions' => __('Define each grade and the minimum score percentage that earns it. The highest grade whose minimum the score meets or exceeds is awarded.'),
                            'add_row' => __('Add Grade'),
                            'if' => [
                                'quiz_grading' => 'equals letter',
                            ],
                            'validate' => [
                                'exclude_unless:quiz_grading,letter',
                            ],
                            'fields' => [
                                [
                                    'handle' => 'text',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('Grade'),
                                        'instructions' => __('e.g. A, B, C, F.'),
                                    ],
                                ],
                                [
                                    'handle' => 'value',
                                    'field' => [
                                        'type' => 'integer',
                                        'display' => __('Minimum %'),
                                        'instructions' => __('The lowest score percentage that earns this grade.'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Inject the quiz settings section at the end (after restrictions), only
        // when the host scores quizzes.
        if (Features::quizScoring()) {
            $this->injectSection(
                $contents,
                $nameLocation,
                $quizSettingsSection,
                'end'
            );
        }

        // Inject each section another package registered, after the add-on's own
        /** @var iterable<FormSettingsSection> $registered */
        $registered = app()->tagged(FormSettingsSection::TAG);
        foreach ($registered as $registeredSection) {
            $this->injectSection(
                $contents,
                $nameLocation,
                $registeredSection->section($form),
                'end'
            );
        }

        // Report that the sections were injected
        return true;
    }

    /**
     * Inject a section into the tab that holds the name section.
     *
     * The section is given keyed by a name for readability; core's tab holds a
     * positional list of sections, so the key is dropped and the section's
     * keyed fields become the `['handle' => …, 'field' => …]` list core uses.
     *
     * Positioning options:
     *   - 'after': Insert directly after the name section
     *   - 'end': Insert after every section in the name section's tab
     *
     * @param  array<string, mixed>  &$contents  The blueprint contents array (passed by reference)
     * @param  array{tab: string, section: int, field: int}  $nameLocation  Where the name section sits.
     * @param  array<string, array{display: string, fields: array<array-key, mixed>}>  $section  The section to inject, keyed by name.
     * @param  'after'|'end'  $position  Where to insert the section.
     */
    private function injectSection(
        array &$contents,
        array $nameLocation,
        array $section,
        string $position
    ): void {
        // Read the name section's tab sections, which the locator found as a list
        /** @var array{tabs: array<string, array{sections: list<mixed>}>} $contents */
        $sections = $contents['tabs'][$nameLocation['tab']]['sections'];

        // Convert the keyed section definition into core's positional shape. array_splice() renumbers
        // the inserted sections, so the name keys array_map() would keep are dropped either way.
        // @pest-mutate-ignore: UnwrapArrayValues
        $converted = array_map(
            static fn (array $definition): array => [
                'display' => $definition['display'],
                'fields' => array_values($definition['fields']),
            ],
            array_values($section)
        );

        // Determine the insertion index for the requested position; appending at
        // the end never shifts the name section, so its located index stays valid
        $insertIndex = $position === 'after' ? $nameLocation['section'] + 1 : \count($sections);

        // Splice the converted section into the tab's section list
        array_splice($sections, $insertIndex, 0, $converted);

        // Write the updated section list back to the contents
        $contents['tabs'][$nameLocation['tab']]['sections'] = $sections;
    }
}
