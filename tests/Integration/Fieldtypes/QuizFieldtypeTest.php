<?php

declare(strict_types=1);

use Statamic\Fields\Field;
use UAMSWeb\FormSettings\Fieldtypes\Quiz;
use UAMSWeb\FormSettings\Rules\QuizAnswerValidation;

/**
 * Unit tests for {@see Quiz}.
 *
 * Mutation: 100% on UAMSWeb\FormSettings\Fieldtypes\Quiz (90 mutants, --covered-only).
 *
 * @command  vendor/bin/pest tests/Integration/Fieldtypes/QuizFieldtypeTest.php
 */

/**
 * Build a configured Quiz fieldtype for the given question type.
 *
 * @param  string|null  $questionFieldtype  The `question_fieldtype` config value
 * @param  bool  $randomize  Whether to enable the randomize-order toggle
 * @return Quiz The configured fieldtype instance
 */
function quizFieldtypeForQuestionType(
    ?string $questionFieldtype = 'radio',
    bool $randomize = false
): Quiz {
    // Assemble a representative answers grid with scoring metadata
    $config = [
        'type' => 'quiz',
        'question_fieldtype' => $questionFieldtype,
        'randomize_order' => $randomize,
        'options' => [
            ['is_correct' => true,  'label' => 'Balance loss',    'weighted_score' => 2.0],
            ['is_correct' => false, 'label' => 'Sudden numbness', 'weighted_score' => 0.0],
            ['is_correct' => true,  'label' => 'Face drooping',   'weighted_score' => 1.5],
        ],
    ];

    // Attach the config to a fresh fieldtype instance
    /** @var Quiz $fieldtype */
    $fieldtype = (new Quiz)->setField(new Field('question', $config));

    return $fieldtype;
}

it('resolves the quiz handle to the app class', function (): void {
    expect(app('statamic.fieldtypes')->get('quiz'))->toBe(Quiz::class);
});

it('is selectable in collections and forms', function (): void {
    $fieldtype = new Quiz;

    expect($fieldtype->selectable())->toBeTrue();
    expect($fieldtype->selectableInForms())->toBeTrue();
});

it('maps the component to the built-in control for the question type', function (): void {
    expect(quizFieldtypeForQuestionType('radio')->component())->toBe('radio');
    expect(quizFieldtypeForQuestionType('select')->component())->toBe('select');
    expect(quizFieldtypeForQuestionType('checkboxes')->component())->toBe('checkboxes');
});

it('falls back to radio when the question type is unset', function (): void {
    expect(quizFieldtypeForQuestionType(null)->component())->toBe('radio');
});

it('exposes answers as value and label pairs on preload', function (): void {
    $options = quizFieldtypeForQuestionType()->preload()['options'];

    expect($options)->toBe([
        ['value' => 'Balance loss',    'label' => 'Balance loss'],
        ['value' => 'Sudden numbness', 'label' => 'Sudden numbness'],
        ['value' => 'Face drooping',   'label' => 'Face drooping'],
    ]);
});

it('withholds correct answer and scoring metadata on preload', function (): void {
    $options = quizFieldtypeForQuestionType()->preload()['options'];

    // The rendered control must never receive scoring fields that reveal answers
    foreach (collect(is_array($options) ? $options : []) as $option) {
        $option = is_array($option) ? $option : [];
        expect(array_keys($option))->toBe(['value', 'label']);
        expect($option)->not->toHaveKey('is_correct');
        expect($option)->not->toHaveKey('weighted_score');
    }
});

it('skips unlabelled and malformed answer rows on preload', function (): void {
    /** @var Quiz $fieldtype */
    $fieldtype = (new Quiz)->setField(new Field('question', [
        'type' => 'quiz',
        'options' => [
            ['label' => 'Kept', 'is_correct' => true],
            ['label' => ''],
            ['is_correct' => true],
            'not-an-array',
        ],
    ]));

    $options = $fieldtype->preload()['options'];

    expect($options)->toHaveCount(1);
    expect(data_get($options, '0.label'))->toBe('Kept');
});

it('preserves the configured order when randomize order is disabled', function (): void {
    $options = quizFieldtypeForQuestionType('radio', false)->preload()['options'];
    $labels = array_column(is_array($options) ? $options : [], 'label');

    expect($labels)->toBe(['Balance loss', 'Sudden numbness', 'Face drooping']);
});

it('keeps the same answer set when randomize order is enabled', function (): void {
    $options = quizFieldtypeForQuestionType('radio', true)->preload()['options'];
    $labels = array_column(is_array($options) ? $options : [], 'label');

    // Order may differ, but every configured answer must still be present exactly once
    expect($labels)->toEqualCanonicalizing(
        ['Balance loss', 'Sudden numbness', 'Face drooping']
    );
});

it('exposes answers as a value => label map for the public form template', function (): void {
    $data = quizFieldtypeForQuestionType()->extraRenderableFieldData();

    expect($data['options'])->toBe([
        'Balance loss' => 'Balance loss',
        'Sudden numbness' => 'Sudden numbness',
        'Face drooping' => 'Face drooping',
    ]);
});

it('withholds correct answer and scoring metadata from the public form template', function (): void {
    $data = quizFieldtypeForQuestionType()->extraRenderableFieldData();

    // The flattened map must be pure label => label; no scoring config may leak
    $serialized = json_encode($data['options']);
    expect($serialized)->not->toContain('is_correct');
    expect($serialized)->not->toContain('weighted_score');
    expect($serialized)->not->toContain('answer_explanation');

    /** @var array<string, string> $options */
    $options = $data['options'];
    foreach ($options as $value => $label) {
        expect($value)->toBe($label);
    }
});

it('passes the configured question fieldtype to the template', function (): void {
    expect(quizFieldtypeForQuestionType('checkboxes')->extraRenderableFieldData()['question_fieldtype'])
        ->toBe('checkboxes');
    expect(quizFieldtypeForQuestionType('select')->extraRenderableFieldData()['question_fieldtype'])
        ->toBe('select');
});

it('falls back to a radio control when the question fieldtype is unset for the template', function (): void {
    expect(quizFieldtypeForQuestionType(null)->extraRenderableFieldData()['question_fieldtype'])
        ->toBe('radio');
});

it('keeps the full answer set for the template when randomize order is enabled', function (): void {
    /** @var array<string, string> $options */
    $options = quizFieldtypeForQuestionType('radio', true)->extraRenderableFieldData()['options'];

    // Order may differ, but every configured answer must still be present exactly once
    expect(array_keys($options))->toEqualCanonicalizing(
        ['Balance loss', 'Sudden numbness', 'Face drooping']
    );
});

it('wraps a single selection in an array on pre-process index', function (): void {
    expect(quizFieldtypeForQuestionType()->preProcessIndex('Face drooping'))->toBe(['Face drooping']);
});

it('drops empty selections from a multi-answer value on pre-process index', function (): void {
    $value = ['Balance loss', '', null, 'Face drooping'];

    expect(quizFieldtypeForQuestionType('checkboxes')->preProcessIndex($value))
        ->toBe(['Balance loss', 'Face drooping']);
});

it('defines the quiz question config fields', function (): void {
    $items = new ReflectionMethod(Quiz::class, 'configFieldItems')->invoke(new Quiz);

    expect($items)->toEqual([
        [
            'display' => 'Quiz Question Options',
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
                    'instructions' => "Enable this setting to assign unique scores to each choice. Otherwise, responses are scored `1` if correct and `0` if incorrect.\n\n"
                        ."Weighted scoring enables complex systems where each choice receives a distinct score, regardless of correctness.\n\n"
                        .'Ensure higher scores are assigned to correct answers.',
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
                            'field' => ['type' => 'toggle', 'display' => 'Correct Answer', 'default' => false],
                        ],
                        [
                            'handle' => 'label',
                            'field' => ['type' => 'textarea', 'display' => 'Answer Label', 'validate' => ['required']],
                        ],
                        [
                            'handle' => 'weighted_score',
                            'field' => [
                                'type' => 'float',
                                'display' => 'Weighted Score',
                                'if' => ['$parent.weighted_score_query' => 'equals true'],
                                'validate' => [
                                    'required_if:$parent.weighted_score_query,true',
                                    'exclude_unless:$parent.weighted_score_query,true',
                                ],
                            ],
                        ],
                    ],
                ],
                'randomize_order' => [
                    'display' => 'Randomize Order of Answers',
                    'instructions' => 'Randomize the order in which the answers are displayed to the user.',
                    'type' => 'toggle',
                    'default' => false,
                ],
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
                    'if' => ['answer_explanation_query' => 'equals true'],
                    'validate' => [
                        'required_if:answer_explanation_query,true',
                        'exclude_unless:answer_explanation_query,true',
                    ],
                ],
            ],
        ],
    ])->and(array_keys(mustBeArray(data_get($items, '0.fields'), 'config fields')))->toBe([
        'question_fieldtype', 'weighted_score_query', 'options', 'randomize_order', 'answer_explanation_query', 'answer_explanation',
    ])->and(data_get($items, '0.fields.options.validate.0'))->toBeInstanceOf(QuizAnswerValidation::class);
});

it('reports the radio icon and a tags index component', function (): void {
    $quiz = new Quiz;

    expect($quiz->icon())->toBe('fieldtype-radio')
        ->and($quiz->indexComponent())->toBe('tags')
        ->and($quiz->defaultable())->toBeFalse()
        ->and(Quiz::title())->toBe('Quiz Question')
        ->and($quiz->categories())->toBe(['controls']);
});

it('reads answer labels as text, from a list or a collection, and drops a label that is not text', function (mixed $grid): void {
    /** @var Quiz $fieldtype */
    $fieldtype = (new Quiz)->setField(new Field('question', ['type' => 'quiz', 'options' => $grid]));

    expect($fieldtype->preload()['options'])->toBe([
        ['value' => '5', 'label' => '5'],
        ['value' => 'Kept', 'label' => 'Kept'],
    ]);
})->with([
    'a list' => [[['label' => 5], ['label' => ['nested']], ['label' => 'Kept']]],
    'a collection' => [collect([['label' => 5], ['label' => ['nested']], ['label' => 'Kept']])],
]);
