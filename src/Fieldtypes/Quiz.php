<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Fieldtypes;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Statamic\Fields\Fieldtype;
use Statamic\Fields\FieldtypeRepository;
use UAMSWeb\FormSettings\Fieldtypes\Traits\RandomizesOptions;
use UAMSWeb\FormSettings\Rules\QuizAnswerValidation;

/**
 * Custom Quiz fieldtype
 */
class Quiz extends Fieldtype
{
    /**
     * The display title of the fieldtype in the Control Panel.
     *
     * @var string The display title of the fieldtype in the Control Panel.
     */
    protected static $title = 'Quiz Question';

    /**
     * Trait for randomizing the order of options.
     *
     * Provides a method to randomize the order of options if the 'randomize_order'
     * toggle is enabled in the fieldtype's config.
     */
    use RandomizesOptions;

    /**
     * The categories this fieldtype belongs to in the Statamic fieldtype selector.
     *
     * @var array<string> Categories this fieldtype belongs to in the Statamic fieldtype selector.
     */
    protected $categories = [
        'controls',
    ];

    /**
     * Indicates whether the fieldtype is selectable in collection blueprints.
     *
     * @var bool Indicates whether the fieldtype is selectable in collection blueprints
     */
    protected $selectable = true;

    /**
     * Indicates whether the fieldtype is selectable in form blueprints.
     *
     * @var bool Indicates whether the fieldtype is selectable in form blueprints
     */
    protected $selectableInForms = true;

    /**
     * Indicates whether the fieldtype can have a default value.
     *
     * @var bool Indicates whether the fieldtype can have a default value
     */
    protected $defaultable = false;

    /**
     * The Vue component used to render this fieldtype's value in index listings.
     *
     * Mirrors the built-in `radio`/`checkboxes`/`select` fieldtypes, which render
     * stored selections as tags in tables such as the form-submission index.
     *
     * @var string The name of the Vue index component to use for this fieldtype
     */
    protected $indexComponent = 'tags';

    /**
     * The Vue component used to edit this fieldtype's value.
     *
     * The quiz question is presented to the user as a standard choice control, so
     * the value-editing UI delegates to the relevant built-in Statamic component
     * based on the `question_fieldtype` config. No bespoke component is required.
     *
     * @return string The built-in fieldtype component handle for the configured question type
     */
    #[\Override]
    public function component(): string
    {
        // Map the configured question fieldtype to the matching built-in component
        return match ($this->config('question_fieldtype')) {
            'checkboxes' => 'checkboxes',
            'select' => 'select',
            default => 'radio',
        };
    }

    /**
     * The icon for this fieldtype.
     *
     * @return string The icon name
     */
    #[\Override]
    public function icon(): string
    {
        return 'fieldtype-radio';
    }

    /**
     * Preload the answer options for the value-editing Vue component.
     *
     * The built-in `radio`/`checkboxes`/`select` components read their selectable
     * choices from `meta.options`, so the configured answers are surfaced here as
     * `value`/`label` pairs. Per-answer scoring config (`is_correct`,
     * `weighted_score`, `answer_explanation`) is intentionally withheld so correct
     * answers never reach the rendered control.
     *
     * @return array<string, mixed> Preload data
     */
    #[\Override]
    public function preload(): array
    {
        // Expose only the selectable answer options to the editing component
        return [
            'options' => $this->getOptions(),
        ];
    }

    /**
     * Expose the sanitized answer data to the public-form field template.
     *
     * `Statamic\Tags\Concerns\RendersForms::getRenderableField()` merges this
     * array last, overriding the raw `options` grid from the field config, so the
     * public template (`resources/field-views/forms/fields/quiz.antlers.html`)
     * sees only a `value => label` answer map — the per-answer `is_correct`,
     * `weighted_score`, and `answer_explanation` config never reaches the page
     * source (UAMS-Web/uams-statamic-archive#816). `question_fieldtype` selects which control the template
     * renders (`radio` | `checkboxes` | `select`), and `question_view` names the view that
     * renders it: the one Statamic itself would render that fieldtype with, so a host's
     * published copy is used where there is one and Statamic's own view otherwise.
     *
     * @return array<string, mixed> The renderable field data exposed to the form template
     */
    #[\Override]
    public function extraRenderableFieldData(): array
    {
        // The control the answers render as, and the view Statamic renders that control with
        $questionFieldtype = $this->config('question_fieldtype') ?? 'radio';
        $control = \in_array($questionFieldtype, ['checkboxes', 'select'], true) ? $questionFieldtype : 'radio';
        $controlFieldtype = app(FieldtypeRepository::class)->find($control);

        // The repository returns a fieldtype for each of the three core handles; the check
        // narrows the type.
        // @pest-mutate-ignore: InstanceOfToTrue
        $questionView = $controlFieldtype instanceof Fieldtype ? $controlFieldtype->view() : null;

        // Reduce the (possibly randomized) answer options to a value => label map
        return [
            'options' => collect($this->getOptions())
                ->mapWithKeys(fn (array $option): array => [$option['value'] => $option['label']])
                ->all(),
            'question_fieldtype' => $questionFieldtype,
            'question_view' => $questionView,
        ];
    }

    /**
     * Validation rules for a submitted answer.
     *
     * A submission is accepted only when every selected answer is one of the
     * configured answer labels, and only checkboxes may submit several (UAMS-Web/uams-statamic-archive#3105).
     * A value the question does not offer would otherwise be stored and scored.
     *
     * @return list<\Closure> The answer-membership check. The base fieldtype declares no
     *                        rules of its own for this type, so there are none to merge.
     */
    #[\Override]
    public function rules(): array
    {
        // Collect the answer labels the question offers
        $labels = array_column($this->getOptions(), 'label');

        // Only a checkboxes question submits a list of answers
        $isCheckbox = $this->config('question_fieldtype') === 'checkboxes';

        // Reject any submitted answer that is not one of those labels
        $rule = function (string $attribute, mixed $value, \Closure $fail) use ($labels, $isCheckbox): void {
            // Treat a single-answer question's value as a one-item list
            $answers = $isCheckbox && \is_array($value) ? $value : [$value];

            foreach ($answers as $answer) {
                // Fail on a non-string or unoffered answer
                if (! \in_array($answer, $labels, true)) {
                    $fail(__('statamic-form-settings::validation.quiz_answer.not_an_option'));

                    return;
                }
            }
        };

        return [$rule];
    }

    /**
     * Normalize each index value to an array of selected answer labels.
     *
     * The `tags` index component expects an array; single-select question types
     * store a scalar, so the value is wrapped and emptied entries are dropped.
     *
     * @param  mixed  $value  The stored submission value
     * @return array<int, mixed> The selected answer labels for index display
     */
    #[\Override]
    public function preProcessIndex(
        $value
    ): array {
        // Wrap scalar values and discard blank selections
        return collect(Arr::wrap($value))
            ->filter(fn ($answer): bool => $answer !== null && $answer !== '')
            ->values()
            ->all();
    }

    /**
     * Build the selectable answer options from the configured answers grid.
     *
     * Each answer row contributes a `value`/`label` pair keyed on its label, which
     * doubles as the stored submission value. The order is randomized when the
     * `randomize_order` toggle is enabled via the {@see RandomizesOptions} trait.
     *
     * @return array<int, array{value: string, label: string}> The (possibly randomized) answer options
     */
    protected function getOptions(): array
    {
        // Read the configured answers grid
        /** @var array<int, mixed>|Collection<int, mixed> $answers */
        $answers = $this->config('options') ?? [];

        // Map each labelled answer row to a value/label option pair (collect()
        // accepts the grid as an array or a Collection alike)
        $options = collect($answers)
            ->filter(fn ($answer): bool => \is_array($answer) && \is_scalar($answer['label'] ?? null) && filled($answer['label']))
            ->map(function (array $answer): array {
                // The filter above guarantees a filled scalar `label`; coerce it to a string
                $label = (string) $answer['label'];

                return [
                    'value' => $label,
                    'label' => $label,
                ];
            })
            ->values()
            ->all();

        // Randomize the answer order when the config toggle is enabled
        /** @var array<int, array{value: string, label: string}> $randomizedOptions */
        $randomizedOptions = $this->maybeRandomizeOptions($options);

        return $randomizedOptions;
    }

    /**
     * Defines the configuration fields available for this fieldtype in the blueprint.
     *
     * @return array<int, array<string, mixed>> The configuration field items
     */
    #[\Override]
    protected function configFieldItems(): array
    {
        return [
            [
                'display' => __('Quiz Question Options'),
                'fields' => [
                    'question_fieldtype' => [
                        'display' => 'Question Fieldtype',
                        'instructions' => 'Select the fieldtype to use for this quiz question.',
                        'type' => 'radio',
                        'options' => [
                            'checkboxes' => 'Checkboxes',
                            'select' => 'Drop-Down List',
                            'radio' => 'Radio Buttons',
                        ],
                        'default' => 'radio',
                    ],
                    'weighted_score_query' => [
                        'display' => 'Enable Weighted Score',
                        'instructions' => <<<'EOT'
                            Enable this setting to assign unique scores to each choice. Otherwise, responses are scored `1` if correct and `0` if incorrect.

                            Weighted scoring enables complex systems where each choice receives a distinct score, regardless of correctness.

                            Ensure higher scores are assigned to correct answers.
                            EOT,
                        'type' => 'toggle',
                        'default' => false,
                    ],
                    'options' => [
                        'display' => 'Answers',
                        'instructions' => 'Enter the answers for this quiz question.',
                        'type' => 'grid',
                        'mode' => 'stacked',
                        'min_rows' => 2,
                        'add_row' => 'Add Answer',
                        'validate' => [new QuizAnswerValidation],
                        'fields' => [
                            [
                                'handle' => 'is_correct',
                                'field' => [
                                    'type' => 'toggle',
                                    'display' => 'Correct Answer',
                                    'default' => false,
                                ],
                            ],
                            [
                                'handle' => 'label',
                                'field' => [
                                    'type' => 'textarea',
                                    'display' => 'Answer Label',
                                    'validate' => ['required'],
                                ],
                            ],
                            [
                                'handle' => 'weighted_score',
                                'field' => [
                                    'type' => 'float',
                                    'display' => 'Weighted Score',
                                    'if' => [
                                        '$parent.weighted_score_query' => 'equals true',
                                    ],
                                    'validate' => [
                                        'required_if:$parent.weighted_score_query,true',
                                        'exclude_unless:$parent.weighted_score_query,true',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    ...self::randomizeOrderConfigField(
                        'Randomize Order of Answers',
                        'Randomize the order in which the answers are displayed to the user.'
                    ),
                    'answer_explanation_query' => [
                        'display' => 'Enable Answer Explanation',
                        'instructions' => 'Enable this option to include an explanation of the answer when displaying the results of the quiz in confirmations or notifications via Antlers tags (e.g., `{{ quiz handle="foo" }}`).',
                        'type' => 'toggle',
                        'default' => false,
                    ],
                    'answer_explanation' => [
                        'display' => 'Answer Explanation',
                        'instructions' => 'Provide the explanation for the correct answer and/or incorrect answers.',
                        'type' => 'textarea',
                        'if' => [
                            'answer_explanation_query' => 'equals true',
                        ],
                        'validate' => [
                            'required_if:answer_explanation_query,true',
                            'exclude_unless:answer_explanation_query,true',
                        ],
                    ],
                ],
            ],
        ];
    }
}
