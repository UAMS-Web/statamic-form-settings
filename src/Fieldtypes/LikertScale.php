<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Fieldtypes;

use Illuminate\Support\Collection;
use Statamic\Fields\Fieldtype;
use UAMSWeb\FormSettings\Rules\LikertRatingOnScale;
use UAMSWeb\FormSettings\Scoring\SurveyScorer;

/**
 * Statamic fieldtype offering preset Likert-scale answer sets (grouped by scale type and
 * number of points) for survey-style questions.
 */
class LikertScale extends Fieldtype
{
    /**
     * The categories this fieldtype belongs to in the Statamic fieldtype selector.
     *
     * @var array<string> Categories this fieldtype belongs to in the Statamic fieldtype selector.
     */
    protected $categories = [];

    /**
     * Indicates whether the fieldtype can have a default value.
     *
     * @var bool Indicates whether the fieldtype can have a default value
     */
    protected $defaultable = false;

    /**
     * The name of the Vue component to use for this fieldtype.
     *
     * A dedicated component (registered as `likert_scale` in resources/js/cp.js)
     * renders the single-row scale and the multi-row matrix; the `radio` control
     * it previously borrowed cannot represent a matrix.
     *
     * @var string The name of the Vue component to use for this fieldtype
     */
    protected $component = 'likert_scale';

    /**
     * The icon for this fieldtype.
     *
     * @return string The icon name
     */
    #[\Override]
    public function icon(): string
    {
        return 'sliders-horizontal';
    }

    /**
     * Get Likert scale options based on type and number of points.
     *
     * @param  string  $type  The type of Likert scale to use (e.g., 'agreement', 'experience', 'frequency',
     *                        'likelihood', 'quality', 'satisfaction')
     * @param  int  $points  The number of points/options in the scale (2-10)
     * @return array<int, string> Likert scale options
     */
    protected function likertOptions(
        string $type,
        int $points
    ): array {
        // ---
        // Define available Likert scale options organized by type and number of points
        // ---
        $scales = [
            'agreement' => [
                2 => [
                    2 => 'Agree',
                    1 => 'Disagree',
                ],
                3 => [
                    3 => 'Agree',
                    2 => 'Neutral',
                    1 => 'Disagree',
                ],
                4 => [
                    4 => 'Strongly Agree',
                    3 => 'Agree',
                    2 => 'Disagree',
                    1 => 'Strongly Disagree',
                ],
                5 => [
                    5 => 'Strongly Agree',
                    4 => 'Agree',
                    3 => 'Neutral',
                    2 => 'Disagree',
                    1 => 'Strongly Disagree',
                ],
                6 => [
                    6 => 'Strongly Agree',
                    5 => 'Moderately Agree',
                    4 => 'Slightly Agree',
                    3 => 'Slightly Disagree',
                    2 => 'Moderately Disagree',
                    1 => 'Strongly Disagree',
                ],
                7 => [
                    7 => 'Strongly Agree',
                    6 => 'Moderately Agree',
                    5 => 'Slightly Agree',
                    4 => 'Neutral',
                    3 => 'Slightly Disagree',
                    2 => 'Moderately Disagree',
                    1 => 'Strongly Disagree',
                ],
                8 => [
                    8 => 'Very Strongly Agree',
                    7 => 'Strongly Agree',
                    6 => 'Moderately Agree',
                    5 => 'Slightly Agree',
                    4 => 'Slightly Disagree',
                    3 => 'Moderately Disagree',
                    2 => 'Strongly Disagree',
                    1 => 'Very Strongly Disagree',
                ],
                9 => [
                    9 => 'Very Strongly Agree',
                    8 => 'Strongly Agree',
                    7 => 'Moderately Agree',
                    6 => 'Slightly Agree',
                    5 => 'Neutral',
                    4 => 'Slightly Disagree',
                    3 => 'Moderately Disagree',
                    2 => 'Strongly Disagree',
                    1 => 'Very Strongly Disagree',
                ],
                10 => [
                    10 => 'Extremely Strongly Agree',
                    9 => 'Very Strongly Agree',
                    8 => 'Strongly Agree',
                    7 => 'Moderately Agree',
                    6 => 'Slightly Agree',
                    5 => 'Slightly Disagree',
                    4 => 'Moderately Disagree',
                    3 => 'Strongly Disagree',
                    2 => 'Very Strongly Disagree',
                    1 => 'Extremely Strongly Disagree',
                ],
            ],
            'experience' => [
                2 => [
                    2 => 'Positive',
                    1 => 'Negative',
                ],
                3 => [
                    3 => 'Positive',
                    2 => 'Neutral',
                    1 => 'Negative',
                ],
                4 => [
                    4 => 'Very Positive',
                    3 => 'Somewhat Positive',
                    2 => 'Somewhat Negative',
                    1 => 'Very Negative',
                ],
                5 => [
                    5 => 'Very Positive',
                    4 => 'Somewhat Positive',
                    3 => 'Neutral',
                    2 => 'Somewhat Negative',
                    1 => 'Very Negative',
                ],
                6 => [
                    6 => 'Very Positive',
                    5 => 'Moderately Positive',
                    4 => 'Somewhat Positive',
                    3 => 'Somewhat Negative',
                    2 => 'Moderately Negative',
                    1 => 'Very Negative',
                ],
                7 => [
                    7 => 'Very Positive',
                    6 => 'Moderately Positive',
                    5 => 'Somewhat Positive',
                    4 => 'Neutral',
                    3 => 'Somewhat Negative',
                    2 => 'Moderately Negative',
                    1 => 'Very Negative',
                ],
                8 => [
                    8 => 'Extremely Positive',
                    7 => 'Very Positive',
                    6 => 'Moderately Positive',
                    5 => 'Somewhat Positive',
                    4 => 'Somewhat Negative',
                    3 => 'Moderately Negative',
                    2 => 'Very Negative',
                    1 => 'Extremely Negative',
                ],
                9 => [
                    9 => 'Extremely Positive',
                    8 => 'Very Positive',
                    7 => 'Moderately Positive',
                    6 => 'Somewhat Positive',
                    5 => 'Neutral',
                    4 => 'Somewhat Negative',
                    3 => 'Moderately Negative',
                    2 => 'Very Negative',
                    1 => 'Extremely Negative',
                ],
                10 => [
                    10 => 'Completely Positive',
                    9 => 'Extremely Positive',
                    8 => 'Very Positive',
                    7 => 'Moderately Positive',
                    6 => 'Somewhat Positive',
                    5 => 'Somewhat Negative',
                    4 => 'Moderately Negative',
                    3 => 'Very Negative',
                    2 => 'Extremely Negative',
                    1 => 'Completely Negative',
                ],
            ],
            'frequency' => [
                2 => [
                    2 => 'Often (at least 50% of the time)',
                    1 => 'Rarely (less than 50% of the time)',
                ],
                3 => [
                    3 => 'Often (about 70% of the time)',
                    2 => 'Sometimes (about 50% of the time)',
                    1 => 'Rarely (about 30% of the time)',
                ],
                4 => [
                    4 => 'Always (100% of the time)',
                    3 => 'Often (about 70% of the time)',
                    2 => 'Rarely (about 30% of the time)',
                    1 => 'Never (0% of the time)',
                ],
                5 => [
                    5 => 'Always (100% of the time)',
                    4 => 'Often (about 70% of the time)',
                    3 => 'Sometimes (about 50% of the time)',
                    2 => 'Rarely (about 30% of the time)',
                    1 => 'Never (0% of the time)',
                ],
                6 => [
                    6 => 'Always (100% of the time)',
                    5 => 'Very Often (about 80% of the time)',
                    4 => 'Often (about 70% of the time)',
                    3 => 'Rarely (about 30% of the time)',
                    2 => 'Very Rarely (about 15% of the time)',
                    1 => 'Never (0% of the time)',
                ],
                7 => [
                    7 => 'Always (100% of the time)',
                    6 => 'Very Often (about 80% of the time)',
                    5 => 'Often (about 70% of the time)',
                    4 => 'Sometimes (about 50% of the time)',
                    3 => 'Rarely (about 30% of the time)',
                    2 => 'Very Rarely (about 15% of the time)',
                    1 => 'Never (0% of the time)',
                ],
                8 => [
                    8 => 'Always (100% of the time)',
                    7 => 'Very Often (about 80% of the time)',
                    6 => 'Often (about 70% of the time)',
                    5 => 'Fairly Often (about 60% of the time)',
                    4 => 'Rarely (about 30% of the time)',
                    3 => 'Very Rarely (about 15% of the time)',
                    2 => 'Hardly Ever (about 5% of the time)',
                    1 => 'Never (0% of the time)',
                ],
                9 => [
                    9 => 'Always (100% of the time)',
                    8 => 'Very Often (about 80% of the time)',
                    7 => 'Often (about 70% of the time)',
                    6 => 'Fairly Often (about 60% of the time)',
                    5 => 'Sometimes (about 50% of the time)',
                    4 => 'Rarely (about 30% of the time)',
                    3 => 'Very Rarely (about 15% of the time)',
                    2 => 'Hardly Ever (about 5% of the time)',
                    1 => 'Never (0% of the time)',
                ],
                10 => [
                    10 => 'Always (100% of the time)',
                    9 => 'Fairly Often (about 90% of the time)',
                    8 => 'Very Often (about 80% of the time)',
                    7 => 'Often (about 70% of the time)',
                    6 => 'Fairly Often (about 60% of the time)',
                    5 => 'Sometimes (about 50% of the time)',
                    4 => 'Rarely (about 30% of the time)',
                    3 => 'Very Rarely (about 15% of the time)',
                    2 => 'Hardly Ever (about 5% of the time)',
                    1 => 'Never (0% of the time)',
                ],
            ],
            'frequency_no_always' => [
                2 => [
                    2 => 'Often (at least 50% of the time)',
                    1 => 'Rarely (less than 50% of the time)',
                ],
                3 => [
                    3 => 'Often (about 70% of the time)',
                    2 => 'Sometimes (about 50% of the time)',
                    1 => 'Rarely (about 30% of the time)',
                ],
                4 => [
                    4 => 'Often (about 70% of the time)',
                    3 => 'Sometimes (about 50% of the time)',
                    2 => 'Rarely (about 30% of the time)',
                    1 => 'Never (0% of the time)',
                ],
                5 => [
                    5 => 'Very Often (about 80% of the time)',
                    4 => 'Often (about 70% of the time)',
                    3 => 'Sometimes (about 50% of the time)',
                    2 => 'Rarely (about 30% of the time)',
                    1 => 'Never (0% of the time)',
                ],
                6 => [
                    6 => 'Very Often (about 80% of the time)',
                    5 => 'Often (about 70% of the time)',
                    4 => 'Sometimes (about 50% of the time)',
                    3 => 'Rarely (about 30% of the time)',
                    2 => 'Very Rarely (about 15% of the time)',
                    1 => 'Never (0% of the time)',
                ],
                7 => [
                    7 => 'Very Often (about 80% of the time)',
                    6 => 'Often (about 70% of the time)',
                    5 => 'Sometimes (about 50% of the time)',
                    4 => 'Rarely (about 30% of the time)',
                    3 => 'Very Rarely (about 15% of the time)',
                    2 => 'Hardly Ever (about 5% of the time)',
                    1 => 'Never (0% of the time)',
                ],
                8 => [
                    8 => 'Very Often (about 80% of the time)',
                    7 => 'Often (about 70% of the time)',
                    6 => 'Fairly Often (about 60% of the time)',
                    5 => 'Sometimes (about 50% of the time)',
                    4 => 'Rarely (about 30% of the time)',
                    3 => 'Very Rarely (about 15% of the time)',
                    2 => 'Hardly Ever (about 5% of the time)',
                    1 => 'Never (0% of the time)',
                ],
                9 => [
                    9 => 'Almost Always (about 90% of the time)',
                    8 => 'Very Often (about 80% of the time)',
                    7 => 'Often (about 70% of the time)',
                    6 => 'Fairly Often (about 60% of the time)',
                    5 => 'Sometimes (about 50% of the time)',
                    4 => 'Rarely (about 30% of the time)',
                    3 => 'Very Rarely (about 15% of the time)',
                    2 => 'Hardly Ever (about 5% of the time)',
                    1 => 'Never (0% of the time)',
                ],
                10 => [
                    10 => 'Almost Always (about 90% of the time)',
                    9 => 'Very Often (about 80% of the time)',
                    8 => 'Often (about 70% of the time)',
                    7 => 'Fairly Often (about 60% of the time)',
                    6 => 'Sometimes (about 50% of the time)',
                    5 => 'Occasionally (about 40% of the time)',
                    4 => 'Rarely (about 30% of the time)',
                    3 => 'Very Rarely (about 15% of the time)',
                    2 => 'Hardly Ever (about 5% of the time)',
                    1 => 'Never (0% of the time)',
                ],
            ],
            'likelihood' => [
                2 => [
                    2 => 'Likely',
                    1 => 'Unlikely',
                ],
                3 => [
                    3 => 'Likely',
                    2 => 'Neutral',
                    1 => 'Unlikely',
                ],
                4 => [
                    4 => 'Very Likely',
                    3 => 'Likely',
                    2 => 'Unlikely',
                    1 => 'Very Unlikely',
                ],
                5 => [
                    5 => 'Very Likely',
                    4 => 'Likely',
                    3 => 'Neutral',
                    2 => 'Unlikely',
                    1 => 'Very Unlikely',
                ],
                6 => [
                    6 => 'Extremely Likely',
                    5 => 'Very Likely',
                    4 => 'Somewhat Likely',
                    3 => 'Somewhat Unlikely',
                    2 => 'Very Unlikely',
                    1 => 'Extremely Unlikely',
                ],
                7 => [
                    7 => 'Extremely Likely',
                    6 => 'Very Likely',
                    5 => 'Somewhat Likely',
                    4 => 'Neutral',
                    3 => 'Somewhat Unlikely',
                    2 => 'Very Unlikely',
                    1 => 'Extremely Unlikely',
                ],
                8 => [
                    8 => 'Extremely Likely',
                    7 => 'Very Likely',
                    6 => 'Moderately Likely',
                    5 => 'Slightly Likely',
                    4 => 'Slightly Unlikely',
                    3 => 'Moderately Unlikely',
                    2 => 'Very Unlikely',
                    1 => 'Extremely Unlikely',
                ],
                9 => [
                    9 => 'Extremely Likely',
                    8 => 'Very Likely',
                    7 => 'Moderately Likely',
                    6 => 'Slightly Likely',
                    5 => 'Neutral',
                    4 => 'Slightly Unlikely',
                    3 => 'Moderately Unlikely',
                    2 => 'Very Unlikely',
                    1 => 'Extremely Unlikely',
                ],
                10 => [
                    10 => 'Completely Likely',
                    9 => 'Extremely Likely',
                    8 => 'Very Likely',
                    7 => 'Moderately Likely',
                    6 => 'Slightly Likely',
                    5 => 'Slightly Unlikely',
                    4 => 'Moderately Unlikely',
                    3 => 'Very Unlikely',
                    2 => 'Extremely Unlikely',
                    1 => 'Completely Unlikely',
                ],
            ],
            'quality' => [
                2 => [
                    2 => 'Good',
                    1 => 'Poor',
                ],
                3 => [
                    3 => 'Good',
                    2 => 'Fair',
                    1 => 'Poor',
                ],
                4 => [
                    4 => 'Very Good',
                    3 => 'Good',
                    2 => 'Poor',
                    1 => 'Very Poor',
                ],
                5 => [
                    5 => 'Very Good',
                    4 => 'Good',
                    3 => 'Fair',
                    2 => 'Poor',
                    1 => 'Very Poor',
                ],
                6 => [
                    6 => 'Excellent',
                    5 => 'Very Good',
                    4 => 'Good',
                    3 => 'Poor',
                    2 => 'Very Poor',
                    1 => 'Terrible',
                ],
                7 => [
                    7 => 'Excellent',
                    6 => 'Very Good',
                    5 => 'Good',
                    4 => 'Fair',
                    3 => 'Poor',
                    2 => 'Very Poor',
                    1 => 'Terrible',
                ],

                8 => [
                    8 => 'Outstanding',
                    7 => 'Excellent',
                    6 => 'Very Good',
                    5 => 'Good',
                    4 => 'Poor',
                    3 => 'Very Poor',
                    2 => 'Terrible',
                    1 => 'Extremely Poor',
                ],
                9 => [
                    9 => 'Outstanding',
                    8 => 'Excellent',
                    7 => 'Very Good',
                    6 => 'Good',
                    5 => 'Fair',
                    4 => 'Poor',
                    3 => 'Very Poor',
                    2 => 'Terrible',
                    1 => 'Extremely Poor',
                ],

                10 => [
                    10 => 'Perfect',
                    9 => 'Outstanding',
                    8 => 'Excellent',
                    7 => 'Very Good',
                    6 => 'Good',
                    5 => 'Poor',
                    4 => 'Very Poor',
                    3 => 'Terrible',
                    2 => 'Extremely Poor',
                    1 => 'Awful',
                ],
            ],
            'satisfaction' => [
                2 => [
                    2 => 'Satisfied',
                    1 => 'Dissatisfied',
                ],
                3 => [
                    3 => 'Satisfied',
                    2 => 'Neutral',
                    1 => 'Dissatisfied',
                ],
                4 => [
                    4 => 'Very Satisfied',
                    3 => 'Satisfied',
                    2 => 'Dissatisfied',
                    1 => 'Very Dissatisfied',
                ],
                5 => [
                    5 => 'Very Satisfied',
                    4 => 'Satisfied',
                    3 => 'Neutral',
                    2 => 'Dissatisfied',
                    1 => 'Very Dissatisfied',
                ],
                6 => [
                    6 => 'Extremely Satisfied',
                    5 => 'Very Satisfied',
                    4 => 'Satisfied',
                    3 => 'Dissatisfied',
                    2 => 'Very Dissatisfied',
                    1 => 'Extremely Dissatisfied',
                ],
                7 => [
                    7 => 'Extremely Satisfied',
                    6 => 'Very Satisfied',
                    5 => 'Satisfied',
                    4 => 'Neutral',
                    3 => 'Dissatisfied',
                    2 => 'Very Dissatisfied',
                    1 => 'Extremely Dissatisfied',
                ],
                8 => [
                    8 => 'Completely Satisfied',
                    7 => 'Extremely Satisfied',
                    6 => 'Very Satisfied',
                    5 => 'Satisfied',
                    4 => 'Dissatisfied',
                    3 => 'Very Dissatisfied',
                    2 => 'Extremely Dissatisfied',
                    1 => 'Completely Dissatisfied',
                ],
                9 => [
                    9 => 'Completely Satisfied',
                    8 => 'Extremely Satisfied',
                    7 => 'Very Satisfied',
                    6 => 'Satisfied',
                    5 => 'Neutral',
                    4 => 'Dissatisfied',
                    3 => 'Very Dissatisfied',
                    2 => 'Extremely Dissatisfied',
                    1 => 'Completely Dissatisfied',
                ],
                10 => [
                    10 => 'Perfectly Satisfied',
                    9 => 'Completely Satisfied',
                    8 => 'Extremely Satisfied',
                    7 => 'Very Satisfied',
                    6 => 'Satisfied',
                    5 => 'Dissatisfied',
                    4 => 'Very Dissatisfied',
                    3 => 'Extremely Dissatisfied',
                    2 => 'Completely Dissatisfied',
                    1 => 'Not at All Satisfied',
                ],
            ],
        ];

        // ---
        // Get the scale type array for the selected type, or default to 'agreement' if
        // not found
        // ---
        $type = $scales[$type] ?? $scales['agreement'];

        // ---
        // Return the options array for the selected number of points, or an empty array
        // if not found
        // ---
        return $type[$points] ?? [];
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
                // Likert scale configuration section for blueprint field options
                'display' => __('Likert Scale Options'),
                'fields' => [
                    // Measurement config
                    'scale_type' => [
                        'display' => __('Scale Type'),
                        'instructions' => __('Choose the type of scale to display.'),
                        'type' => 'radio',
                        'options' => [
                            'agreement' => __('Agreement'),
                            'experience' => __('Experience'),
                            'frequency' => __('Frequency (With "Always")'),
                            'frequency_no_always' => __('Frequency (Without "Always")'),
                            'likelihood' => __('Likelihood'),
                            'quality' => __('Quality'),
                            'satisfaction' => __('Satisfaction'),
                        ],
                        'default' => 'agreement',
                        'validate' => ['required'],
                    ],
                    // Number of Points config
                    'number_of_points' => [
                        'display' => __('Number of Points'),
                        'instructions' => __('Select how many points the scale should have.'),
                        'type' => 'select',
                        'options' => [
                            2 => __('Two Points'),
                            3 => __('Three Points'),
                            4 => __('Four Points'),
                            5 => __('Five Points'),
                            6 => __('Six Points'),
                            7 => __('Seven Points'),
                            8 => __('Eight Points'),
                            9 => __('Nine Points'),
                            10 => __('Ten Points'),
                        ],
                        'default' => 5,
                        'validate' => ['required'],
                    ],
                    // Enable "Not Applicable" Option config
                    'enable_not_applicable' => [
                        'display' => __('Enable Zero-Point Option'),
                        'instructions' => __('Allow users to select a zero-point option.'),
                        'type' => 'toggle',
                        'default' => false,
                    ],
                    // Enable "Not Applicable" Option config
                    'not_applicable_label' => [
                        'type' => 'text',
                        'display' => __('Zero-Point Option Label'),
                        'instructions' => __('Define the label for the zero-point option.'),
                        'default' => 'Not Applicable',
                        'if' => [
                            'enable_not_applicable' => 'equals true',
                        ],
                        'validate' => [
                            'required_if:enable_not_applicable,true',
                            'exclude_unless:enable_not_applicable,true',
                        ],
                    ],
                    // Scale Display Order config
                    'display_order' => [
                        'type' => 'radio',
                        'display' => __('Scale Display Order'),
                        'instructions' => __('Choose whether the worst or lowest rating is listed first, or vice-versa.'),
                        'options' => [
                            'asc' => __('Low to High'),
                            'desc' => __('High to Low'),
                        ],
                        'default' => 'asc',
                    ],
                    // Reverse Scale config
                    'reverse_scale' => [
                        'display' => __('Reverse Scale'),
                        'instructions' => __('Reverse the scale so that the lowest conceptual value is the highest numeric value.'),
                        'type' => 'toggle',
                        'default' => false,
                    ],
                    // Enable Multiple Rows config
                    'enable_multiple_rows' => [
                        'display' => __('Enable Multiple Rows'),
                        'instructions' => __('Add multiple rows to the field.'),
                        'type' => 'toggle',
                        'default' => false,
                    ],
                    // Enable Multiple Rows config
                    'multiple_rows' => [
                        'display' => __('Row Labels'),
                        'instructions' => __('Set the text for each row.'),
                        'type' => 'list',
                        'if' => [
                            'enable_multiple_rows' => 'equals true',
                        ],
                        'validate' => [
                            'required_if:enable_multiple_rows,true',
                            'exclude_unless:enable_multiple_rows,true',
                            'array',
                            'min:1',
                            function (
                                $attribute,
                                $value,
                                callable $fail
                            ): void {
                                if (\is_array($value)) {
                                    foreach ($value as $item) {
                                        $label = \is_scalar($item) ? (string) $item : '';
                                        if (in_array(\trim($label), ['', '0'], true)) {
                                            $fail(__('statamic-form-settings::validation.likert_scale.row_label_required'));

                                            return;
                                        }
                                    }
                                }
                            },
                        ],
                    ],
                ],
            ],
            [
                // Appearance and behavior configuration section for Likert scale field
                'display' => __('Appearance & Behavior'),
                'fields' => [
                    // Whether to display Likert options inline (horizontally)
                    'inline' => [
                        'display' => __('Inline'),
                        'instructions' => __('statamic::fieldtypes.radio.config.inline'),
                        'type' => 'toggle',
                    ],
                ],
            ],
        ];
    }

    /**
     * Preload config data for the Vue component.
     *
     * Exposes everything the LikertScale component needs to render both the
     * single-row scale and the multi-row matrix: the ordered scale `options`
     * (with `display_order` and `reverse_scale` already applied and the
     * zero-point option carrying its configured label), `inline`, and the
     * matrix row labels (`multiple_rows` / `enable_multiple_rows`).
     *
     * @return array<string, mixed> Preload data
     */
    #[\Override]
    public function preload(): array
    {
        // Get the selected scale type from config, defaulting to 'agreement'
        $typeConfig = $this->config('scale_type', 'agreement');
        $type = \is_scalar($typeConfig) ? (string) $typeConfig : 'agreement';

        // Get the selected number of points from config (an unset select reads as its
        // configured default of 5, so no fallback is needed)
        $pointsConfig = $this->config('number_of_points');
        $points = \is_numeric($pointsConfig) ? (int) $pointsConfig : 5;

        // Build the ordered scale options (display order + reverse scale applied).
        // (an unset toggle reads as its configured default, so no fallback is needed)
        $reverse = (bool) $this->config('reverse_scale');
        $orderConfig = $this->config('display_order', 'asc');
        $order = ($orderConfig === 'desc') ? 'desc' : 'asc';
        $optionsArray = $this->buildOptions($this->likertOptions($type, $points), $reverse, $order);

        // Append the zero-point option with its CONFIGURED label (not a hardcoded one).
        if ($this->config('enable_not_applicable')) {
            $labelConfig = $this->config('not_applicable_label', 'Not Applicable');
            $naLabel = (\is_scalar($labelConfig) && \trim((string) $labelConfig) !== '')
                ? (string) $labelConfig
                : __('Not Applicable');

            $optionsArray[] = ['value' => 'not_applicable', 'label' => $naLabel];
        }

        // Matrix row labels (only when multiple rows are enabled).
        $rows = $this->rowLabels();

        // Return everything the component consumes.
        return [
            'options' => $optionsArray,
            'inline' => (bool) $this->config('inline'),
            'enable_multiple_rows' => $rows !== [],
            'multiple_rows' => $rows,
        ];
    }

    /**
     * Validation rules for a submitted rating.
     *
     * A single-row question accepts one value from the scale; a matrix question
     * accepts a map of configured row labels to values from the scale (UAMS-Web/uams-statamic-archive#3105).
     * The scale is the one {@see preload()} offers, so `not_applicable` is valid
     * only where that option is enabled. Rows left unrated are not rejected:
     * whether a rating is required is the field's own `required` rule.
     *
     * @return list<LikertRatingOnScale> The scale-membership check. The base fieldtype
     *                                   declares no rules of its own for this type,
     *                                   so there are none to merge.
     */
    #[\Override]
    public function rules(): array
    {
        /** @var list<array{value: int|string, label: string}> $options */
        $options = $this->preload()['options'];

        // Check each rating against the scale preload() offers and the matrix rows
        return [new LikertRatingOnScale(array_column($options, 'value'), $this->rowLabels())];
    }

    /**
     * Front-end form-render data.
     *
     * A Likert field has no `options` config key of its own, so the public
     * form-field template (`resources/views/vendor/statamic/forms/fields/likert_scale.antlers.html`)
     * would render an empty scale without this. Exposing {@see preload()}
     * verbatim gives that template the same computed contract the CP component
     * consumes: the ordered `options` (display order + reverse scale applied,
     * plus the zero-point option with its configured label), `inline`, and the
     * matrix rows (`multiple_rows` / `enable_multiple_rows`).
     *
     * @return array<string, mixed> Front-end render data.
     */
    #[\Override]
    public function extraRenderableFieldData(): array
    {
        return $this->preload();
    }

    /**
     * Build the ordered `{value, label}` scale options.
     *
     * `reverse_scale` flips the numeric value assigned to each label (the lowest
     * conceptual rating gets the highest numeric value); `display_order` sets the
     * conceptual low-to-high (`asc`) or high-to-low (`desc`) display order. The
     * two are independent: reversing changes the stored value, ordering changes
     * only the visible sequence.
     *
     * @param  array<int, string>  $options  Point => label, from {@see likertOptions()}.
     * @return list<array{value: int|string, label: string}>
     */
    protected function buildOptions(array $options, bool $reverse, string $order): array
    {
        if ($options === []) {
            return [];
        }

        $points = array_keys($options);
        $bound = max($points) + min($points);

        /** @var Collection<int, array{value: int, label: string, position: int}> $built */
        $built = collect($options)->map(fn (string $label, int $point): array => [
            'value' => $reverse ? ($bound - $point) : $point,
            'label' => $label,
            'position' => $point,
        ]);

        return array_values($built
            ->when(
                $order === 'desc',
                fn (Collection $c): Collection => $c->sortByDesc('position'),
                fn (Collection $c): Collection => $c->sortBy('position'),
            )
            ->map(fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']])
            ->all());
    }

    /**
     * The configured matrix row labels, normalized to a list of non-empty strings.
     * Empty when the field is not in multi-row mode.
     *
     * @return list<string>
     */
    protected function rowLabels(): array
    {
        if (! $this->config('enable_multiple_rows')) {
            return [];
        }

        return self::normalizeRowLabels($this->config('multiple_rows', []));
    }

    /**
     * Normalize configured matrix row labels to the canonical list used as BOTH
     * the front-end display label AND the `name="{handle}[label]"` submit key.
     *
     * Each label is coerced to a string, has `[`/`]` removed (PHP truncates an
     * array-input `name` at the first `]`, so a bracketed label would submit
     * under a key that no longer matches this list), trimmed, dropped when
     * blank, and de-duplicated (two identical labels would collide onto one
     * submit key and double-count). {@see SurveyScorer}
     * scores against this same list, so the two MUST agree: both call this
     * method, which is the single source of truth for the row-key contract.
     *
     * @param  mixed  $rows  The raw `multiple_rows` config value.
     * @return list<string>
     */
    public static function normalizeRowLabels(mixed $rows): array
    {
        if ($rows instanceof Collection) {
            $rows = $rows->all();
        }

        return array_values(collect(\is_array($rows) ? $rows : [])
            ->map(fn ($row): string => \is_scalar($row) ? (string) $row : '')
            ->map(fn (string $row): string => \trim(str_replace(['[', ']'], '', $row)))
            ->filter(fn (string $row): bool => $row !== '')
            ->unique()
            ->all());
    }
}
