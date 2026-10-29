<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Scoring\QuizScorer;

/**
 * Proves the quiz scoring engine (UAMS-Web/uams-statamic-archive#209) matches the Gravity Forms Quiz add-on's
 * scoring: unweighted "completely correct" 1-point questions, weighted per-answer
 * sums, correct max-score derivation (sum for checkboxes, highest for radio), and
 * optional form-level grade/pass thresholds.
 *
 * @covers \UAMSWeb\FormSettings\Scoring\QuizScorer
 *
 * @command  vendor/bin/pest tests/Integration/Scoring/QuizScorerTest.php
 */
$formsDir = '';

beforeEach(function () use (&$formsDir): void {
    $formsDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($formsDir);
    Config::set('statamic.forms.forms', $formsDir);
});

afterEach(function () use (&$formsDir): void {
    if ($formsDir !== '' && File::isDirectory($formsDir)) {
        File::deleteDirectory($formsDir);
    }
});

/**
 * Build + save a form whose blueprint carries the given fields.
 *
 * @param  array<string, array<string, mixed>>  $fields
 */
function makeQuizForm(array $fields): Form
{
    $handle = 'quiz_probe_'.str_replace('.', '', uniqid('', true));

    app(BlueprintRepository::class)->setFallback('forms.'.$handle, fn () => Blueprint::makeFromFields($fields));

    $form = makeForm($handle);
    $form->store(false);
    $form->save();

    return $form;
}

/**
 * A radio quiz question with a single correct answer.
 *
 * @return array<string, mixed>
 */
function radioQuizField(bool $weighted = false): array
{
    return [
        'type' => 'quiz',
        'display' => 'Capital of France?',
        'question_fieldtype' => 'radio',
        'weighted_score_query' => $weighted,
        'options' => [
            ['label' => 'Paris', 'is_correct' => true, 'weighted_score' => 5],
            ['label' => 'London', 'is_correct' => false, 'weighted_score' => 1],
            ['label' => 'Berlin', 'is_correct' => false, 'weighted_score' => 0],
        ],
    ];
}

it('scores an unweighted radio question 1 when the correct answer is chosen', function (): void {
    $form = makeQuizForm(['q1' => radioQuizField()]);

    $results = app(QuizScorer::class)->score($form, ['q1' => 'Paris']);

    expect($results->score)->toBe(1.0)
        ->and($results->maxScore)->toBe(1.0)
        ->and($results->percent)->toBe(100)
        ->and($results->question('q1')?->isCorrect)->toBeTrue()
        ->and($results->question('q1')?->correctAnswers)->toBe(['Paris'])
        ->and($results->question('q1')?->submitted)->toBe(['Paris']);
});

it('scores an unweighted radio question 0 when a wrong answer is chosen', function (): void {
    $form = makeQuizForm(['q1' => radioQuizField()]);

    $results = app(QuizScorer::class)->score($form, ['q1' => 'London']);

    expect($results->score)->toBe(0.0)
        ->and($results->percent)->toBe(0)
        ->and($results->question('q1')?->isCorrect)->toBeFalse();
});

it('scores an answer matching no option as incorrect', function (string $type): void {
    $field = [...radioQuizField(), 'question_fieldtype' => $type];

    $results = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => 'Bogus']);

    // An answer the question does not offer earns no credit (UAMS-Web/uams-statamic-archive#3105)
    expect($results->score)->toBe(0.0)
        ->and($results->question('q1')?->answered)->toBeTrue()
        ->and($results->question('q1')?->isCorrect)->toBeFalse();
})->with(['radio', 'select']);

it('matches a submitted answer to an option label exactly, not by loose comparison', function (): void {
    $field = [
        'type' => 'quiz',
        'display' => 'Select the right numbers',
        'question_fieldtype' => 'checkboxes',
        'options' => [
            ['label' => 'Seven', 'is_correct' => true],
            ['label' => '10', 'is_correct' => false],
        ],
    ];

    // '1e1' compares loosely equal to '10', but it is not an offered answer
    $question = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => ['Seven', '1e1']])->question('q1');

    expect($question?->isCorrect)->toBeFalse();
});

it('scores a checkbox answer set carrying an unknown answer as incorrect', function (): void {
    $field = [
        'type' => 'quiz',
        'display' => 'Select the primary colors',
        'question_fieldtype' => 'checkboxes',
        'options' => [
            ['label' => 'Red', 'is_correct' => true],
            ['label' => 'Blue', 'is_correct' => true],
            ['label' => 'Green', 'is_correct' => false],
        ],
    ];

    $question = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => ['Red', 'Blue', 'Bogus']])->question('q1');

    // Every correct answer is selected, but the unknown one keeps it from being completely correct
    expect($question?->isCorrect)->toBeFalse()
        ->and($question?->score)->toBe(0.0);
});

it('scores an unanswered question 0', function (): void {
    $form = makeQuizForm(['q1' => radioQuizField()]);

    $results = app(QuizScorer::class)->score($form, []);

    expect($results->score)->toBe(0.0)
        ->and($results->question('q1')?->answered)->toBeFalse()
        ->and($results->question('q1')?->isCorrect)->toBeFalse();
});

it('scores a weighted radio question with the selected answer weight and highest-weight max', function (): void {
    $form = makeQuizForm(['q1' => radioQuizField(weighted: true)]);

    $results = app(QuizScorer::class)->score($form, ['q1' => 'London']);

    // Earned = London's weight (1); max = highest weight among answers (Paris = 5).
    expect($results->score)->toBe(1.0)
        ->and($results->maxScore)->toBe(5.0)
        ->and($results->percent)->toBe(20);
});

it('gives an unweighted checkbox question full marks only when every correct answer (and no wrong one) is chosen', function (): void {
    $field = [
        'type' => 'quiz',
        'display' => 'Select the primary colors',
        'question_fieldtype' => 'checkboxes',
        'weighted_score_query' => false,
        'options' => [
            ['label' => 'Red', 'is_correct' => true],
            ['label' => 'Blue', 'is_correct' => true],
            ['label' => 'Green', 'is_correct' => false],
        ],
    ];

    $bothCorrect = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => ['Red', 'Blue']]);
    $partial = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => ['Red']]);
    $withWrong = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => ['Red', 'Blue', 'Green']]);

    expect($bothCorrect->score)->toBe(1.0)
        ->and($partial->score)->toBe(0.0)
        ->and($withWrong->score)->toBe(0.0);
});

it('sums weighted checkbox answers and uses the sum of positive weights as the max', function (): void {
    $field = [
        'type' => 'quiz',
        'display' => 'Weighted checkbox',
        'question_fieldtype' => 'checkboxes',
        'weighted_score_query' => true,
        'options' => [
            ['label' => 'A', 'is_correct' => true, 'weighted_score' => 3],
            ['label' => 'B', 'is_correct' => true, 'weighted_score' => 2],
            ['label' => 'C', 'is_correct' => false, 'weighted_score' => 0],
        ],
    ];

    $results = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => ['A', 'B']]);

    // Earned = 3 + 2 = 5; max = 3 + 2 + 0 = 5.
    expect($results->score)->toBe(5.0)
        ->and($results->maxScore)->toBe(5.0)
        ->and($results->percent)->toBe(100);
});

it('totals scores and percentage across multiple questions', function (): void {
    $form = makeQuizForm([
        'q1' => radioQuizField(),
        'q2' => [
            'type' => 'quiz',
            'display' => 'Second question',
            'question_fieldtype' => 'radio',
            'options' => [
                ['label' => 'Right', 'is_correct' => true],
                ['label' => 'Wrong', 'is_correct' => false],
            ],
        ],
    ]);

    $results = app(QuizScorer::class)->score($form, ['q1' => 'Paris', 'q2' => 'Wrong']);

    expect($results->score)->toBe(1.0)
        ->and($results->maxScore)->toBe(2.0)
        ->and($results->percent)->toBe(50)
        ->and($results->questions)->toHaveCount(2);
});

it('captures the answer explanation only when enabled', function (): void {
    $enabled = makeQuizForm(['q1' => [
        ...radioQuizField(),
        'answer_explanation_query' => true,
        'answer_explanation' => 'Paris is the capital of France.',
    ]]);
    $disabled = makeQuizForm(['q1' => [
        ...radioQuizField(),
        'answer_explanation_query' => false,
        'answer_explanation' => 'Ignored because disabled.',
    ]]);

    expect(app(QuizScorer::class)->score($enabled, ['q1' => 'Paris'])->question('q1')?->explanation)
        ->toBe('Paris is the capital of France.')
        ->and(app(QuizScorer::class)->score($disabled, ['q1' => 'Paris'])->question('q1')?->explanation)
        ->toBeNull();
});

it('derives an optional letter grade and pass flag from form-level config', function (): void {
    $form = makeQuizForm(['q1' => radioQuizField(), 'q2' => radioQuizField()]);
    $form->set('quiz_pass_percent', 50);
    $form->set('quiz_grades', [
        ['text' => 'A', 'value' => 90],
        ['text' => 'B', 'value' => 50],
        ['text' => 'F', 'value' => 0],
    ]);
    $form->save();

    // One of two correct → 50%.
    $results = app(QuizScorer::class)->score($form, ['q1' => 'Paris', 'q2' => 'London']);

    expect($results->percent)->toBe(50)
        ->and($results->grade)->toBe('B')
        ->and($results->pass)->toBeTrue();
});

it('leaves grade empty and pass null when no grading config exists', function (): void {
    $form = makeQuizForm(['q1' => radioQuizField()]);

    $results = app(QuizScorer::class)->score($form, ['q1' => 'Paris']);

    expect($results->grade)->toBe('')
        ->and($results->pass)->toBeNull();
});

it('reports no questions for a form without quiz fields', function (): void {
    $form = makeQuizForm(['name' => ['type' => 'text', 'display' => 'Name']]);

    $results = app(QuizScorer::class)->score($form, ['name' => 'Pat']);

    expect($results->hasQuestions())->toBeFalse()
        ->and($results->questions)->toBe([])
        ->and($results->maxScore)->toBe(0.0)
        ->and($results->percent)->toBe(0);
});

/**
 * A form carrying the given grading config on top of two radio questions.
 *
 * @param  array<string, mixed>  $config  Form-level values, such as `quiz_grades`.
 */
function gradedQuizForm(array $config): Form
{
    $form = makeQuizForm(['q1' => radioQuizField(), 'q2' => radioQuizField()]);

    foreach ($config as $key => $value) {
        $form->set($key, $value);
    }

    $form->save();

    return $form;
}

it('reports every choice with whether it is correct and whether it was selected', function (): void {
    $form = makeQuizForm(['q1' => radioQuizField()]);

    $question = app(QuizScorer::class)->score($form, ['q1' => 'London'])->question('q1');

    expect($question?->choices)->toBe([
        ['label' => 'Paris', 'is_correct' => true, 'is_selected' => false],
        ['label' => 'London', 'is_correct' => false, 'is_selected' => true],
        ['label' => 'Berlin', 'is_correct' => false, 'is_selected' => false],
    ])
        ->and($question?->label)->toBe('Capital of France?')
        ->and($question?->handle)->toBe('q1')
        ->and($question?->answered)->toBeTrue();
});

it('lists every correct answer of a checkbox question', function (): void {
    $field = [
        'type' => 'quiz',
        'display' => 'Primary colors',
        'question_fieldtype' => 'checkboxes',
        'options' => [
            ['label' => 'Red', 'is_correct' => true],
            ['label' => 'Green', 'is_correct' => false],
            ['label' => 'Blue', 'is_correct' => true],
        ],
    ];

    $question = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => ['Red']])->question('q1');

    expect($question?->correctAnswers)->toBe(['Red', 'Blue'])
        ->and($question?->isCorrect)->toBeFalse();
});

it('keeps only non-empty scalar answers, as strings', function (): void {
    $field = [
        'type' => 'quiz',
        'display' => 'Pick numbers',
        'question_fieldtype' => 'checkboxes',
        'options' => [
            ['label' => '1', 'is_correct' => true],
            ['label' => '2', 'is_correct' => true],
        ],
    ];

    $question = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => [1, '', null, ['2'], '2']])->question('q1');

    // An integer answer still matches its label, and the blank, null and nested entries drop out
    expect($question?->submitted)->toBe(['1', '2'])
        ->and($question?->isCorrect)->toBeTrue()
        ->and($question?->score)->toBe(1.0);
});

it('treats an answer list of only blanks as unanswered', function (): void {
    $question = app(QuizScorer::class)->score(makeQuizForm(['q1' => radioQuizField()]), ['q1' => ['', null]])->question('q1');

    expect($question?->answered)->toBeFalse()
        ->and($question?->submitted)->toBe([]);
});

it('uses weighted scoring whichever truthy form the toggle is stored as', function (mixed $enabled): void {
    $form = makeQuizForm(['q1' => [...radioQuizField(), 'weighted_score_query' => $enabled]]);

    $results = app(QuizScorer::class)->score($form, ['q1' => 'London']);

    expect($results->score)->toBe(1.0)
        ->and($results->maxScore)->toBe(5.0);
})->with([
    'true' => [true],
    'integer 1' => [1],
    'string 1' => ['1'],
]);

it('scores unweighted when the toggle holds any other value', function (mixed $stored): void {
    $form = makeQuizForm(['q1' => [...radioQuizField(), 'weighted_score_query' => $stored]]);

    $results = app(QuizScorer::class)->score($form, ['q1' => 'Paris']);

    expect($results->score)->toBe(1.0)
        ->and($results->maxScore)->toBe(1.0);
})->with([
    'string true' => ['true'],
    'integer 2' => [2],
]);

it('treats a question with no stored type as a radio question', function (): void {
    $field = radioQuizField(weighted: true);
    unset($field['question_fieldtype']);
    $form = makeQuizForm(['q1' => $field]);

    $results = app(QuizScorer::class)->score($form, ['q1' => 'Paris']);

    // Radio max is the single highest weight, not the sum of the positive weights
    expect($results->maxScore)->toBe(5.0)
        ->and($results->score)->toBe(5.0);
});

it('marks an unweighted radio question without a stored type correct on the right answer', function (): void {
    $field = radioQuizField();
    unset($field['question_fieldtype']);

    $question = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => 'Paris'])->question('q1');

    expect($question?->isCorrect)->toBeTrue();
});

it('ignores negative weights in a checkbox maximum and never lets the total go below zero', function (): void {
    $field = [
        'type' => 'quiz',
        'display' => 'Weighted checkbox',
        'question_fieldtype' => 'checkboxes',
        'weighted_score_query' => true,
        'options' => [
            ['label' => 'A', 'is_correct' => true, 'weighted_score' => 2],
            ['label' => 'B', 'is_correct' => false, 'weighted_score' => -5],
        ],
    ];

    $results = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => ['A', 'B']]);

    // Earned 2 - 5 = -3, clamped to 0; the maximum counts only the positive weight
    expect($results->maxScore)->toBe(2.0)
        ->and($results->score)->toBe(0.0)
        ->and($results->question('q1')?->score)->toBe(-3.0);
});

it('caps a radio maximum at zero when every weight is negative', function (): void {
    $field = [
        'type' => 'quiz',
        'display' => 'All negative',
        'question_fieldtype' => 'radio',
        'weighted_score_query' => true,
        'options' => [
            ['label' => 'A', 'is_correct' => true, 'weighted_score' => -1],
            ['label' => 'B', 'is_correct' => false, 'weighted_score' => -2],
        ],
    ];

    $results = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => 'A']);

    expect($results->maxScore)->toBe(0.0)
        ->and($results->percent)->toBe(0)
        ->and($results->rawPercent)->toBe(0.0);
});

it('scores a weighted answer with a missing or non-numeric weight as zero', function (): void {
    $field = [
        'type' => 'quiz',
        'display' => 'Weights',
        'question_fieldtype' => 'checkboxes',
        'weighted_score_query' => true,
        'options' => [
            ['label' => 'A', 'is_correct' => true, 'weighted_score' => '1.5'],
            ['label' => 'B', 'is_correct' => true],
            ['label' => 'C', 'is_correct' => true, 'weighted_score' => 'lots'],
        ],
    ];

    $results = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => ['A', 'B', 'C']]);

    expect($results->score)->toBe(1.5)
        ->and($results->maxScore)->toBe(1.5);
});

it('reads answer options held in collections and skips malformed rows', function (): void {
    $field = [
        'type' => 'quiz',
        'display' => 'Collections',
        'question_fieldtype' => 'radio',
        'options' => new Collection([
            new Collection(['label' => 'Paris', 'is_correct' => true]),
            'not a row',
            ['label' => ['nested'], 'is_correct' => false],
            ['label' => '', 'is_correct' => false],
            ['is_correct' => false],
            ['label' => 7, 'is_correct' => '1'],
        ]),
    ];

    $question = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => 'Paris'])->question('q1');

    // Only the two labelled rows survive; the integer label is read as text, and its `'1'` marks it correct
    expect($question?->choices)->toBe([
        ['label' => 'Paris', 'is_correct' => true, 'is_selected' => true],
        ['label' => '7', 'is_correct' => true, 'is_selected' => false],
    ]);
});

it('matches an answer to its label exactly, not as a number', function (): void {
    $field = [
        'type' => 'quiz',
        'display' => 'Exact',
        'question_fieldtype' => 'radio',
        'options' => [
            ['label' => '1', 'is_correct' => true],
            ['label' => '1.0', 'is_correct' => false],
        ],
    ];

    $question = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => '1'])->question('q1');

    // A loose comparison would also select '1.0', the wrong answer
    expect($question?->choices)->toBe([
        ['label' => '1', 'is_correct' => true, 'is_selected' => true],
        ['label' => '1.0', 'is_correct' => false, 'is_selected' => false],
    ])->and($question?->isCorrect)->toBeTrue();
});

it('skips an answer row whose label is false and treats a row without a flag as incorrect', function (): void {
    $field = [
        'type' => 'quiz',
        'display' => 'Defaults',
        'question_fieldtype' => 'radio',
        'options' => [
            ['label' => false, 'is_correct' => true],
            ['label' => 'Unflagged'],
        ],
    ];

    $question = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), [])->question('q1');

    expect($question?->choices)->toBe([
        ['label' => 'Unflagged', 'is_correct' => false, 'is_selected' => false],
    ])->and($question?->correctAnswers)->toBe([]);
});

it('marks an answer correct whichever truthy form the flag is stored as', function (mixed $flag, bool $correct): void {
    $field = [
        'type' => 'quiz',
        'display' => 'Flag',
        'question_fieldtype' => 'radio',
        'options' => [['label' => 'Only', 'is_correct' => $flag]],
    ];

    $question = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), [])->question('q1');

    expect($question?->correctAnswers)->toBe($correct ? ['Only'] : []);
})->with([
    'true' => [true, true],
    'integer 1' => [1, true],
    'string 1' => ['1', true],
    'false' => [false, false],
    'string yes' => ['yes', false],
]);

it('reports no answer options when the stored options are not a list', function (): void {
    $field = ['type' => 'quiz', 'display' => 'Broken', 'question_fieldtype' => 'radio', 'options' => 'Paris'];

    $question = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => 'Paris'])->question('q1');

    // With no options nothing can be wrong, so an answered question is completely correct
    expect($question?->choices)->toBe([])
        ->and($question?->correctAnswers)->toBe([]);
});

it('rounds the percentage and keeps the unrounded value', function (): void {
    $form = makeQuizForm(['q1' => radioQuizField(), 'q2' => radioQuizField(), 'q3' => radioQuizField()]);

    $results = app(QuizScorer::class)->score($form, ['q1' => 'Paris', 'q2' => 'Paris', 'q3' => 'London']);

    expect($results->percent)->toBe(67)
        ->and($results->rawPercent)->toBe(2 / 3 * 100);
});

it('rounds a percentage down when it falls below the half', function (): void {
    $form = makeQuizForm(['q1' => radioQuizField(), 'q2' => radioQuizField(), 'q3' => radioQuizField()]);

    expect(app(QuizScorer::class)->score($form, ['q1' => 'Paris'])->percent)->toBe(33);
});

it('treats a false answer as unanswered', function (): void {
    $question = app(QuizScorer::class)->score(makeQuizForm(['q1' => radioQuizField()]), ['q1' => false])->question('q1');

    expect($question?->answered)->toBeFalse()
        ->and($question?->submitted)->toBe([]);
});

it('reports no explanation when the explanation toggle is absent', function (): void {
    $field = [...radioQuizField(), 'answer_explanation' => 'Because.'];
    unset($field['answer_explanation_query']);

    $question = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => 'Paris'])->question('q1');

    expect($question?->explanation)->toBeNull();
});

it('reads a weight stored as numeric text as a number', function (): void {
    $field = [
        'type' => 'quiz',
        'display' => 'Weights',
        'question_fieldtype' => 'radio',
        'weighted_score_query' => true,
        'options' => [
            ['label' => 'A', 'is_correct' => true, 'weighted_score' => '10'],
            ['label' => 'B', 'is_correct' => false, 'weighted_score' => '2'],
        ],
    ];

    $results = app(QuizScorer::class)->score(makeQuizForm(['q1' => $field]), ['q1' => 'B']);

    expect($results->score)->toBe(2.0)
        ->and($results->maxScore)->toBe(10.0)
        ->and($results->percent)->toBe(20);
});

it('awards a grade whose threshold the percentage meets exactly, whatever order the grades are stored in', function (): void {
    $form = gradedQuizForm(['quiz_grades' => [
        ['text' => 'F', 'value' => 0],
        ['text' => 'A', 'value' => 100],
        ['text' => 'C', 'value' => '50'],
    ]]);

    expect(app(QuizScorer::class)->score($form, ['q1' => 'Paris', 'q2' => 'London'])->grade)->toBe('C')
        ->and(app(QuizScorer::class)->score($form, ['q1' => 'Paris', 'q2' => 'Paris'])->grade)->toBe('A')
        ->and(app(QuizScorer::class)->score($form, [])->grade)->toBe('F');
});

it('awards no grade when the percentage meets no threshold', function (): void {
    $form = gradedQuizForm(['quiz_grades' => [['text' => 'A', 'value' => 90]]]);

    expect(app(QuizScorer::class)->score($form, ['q1' => 'Paris'])->grade)->toBe('');
});

it('reads grades held in collections and skips malformed grade rows', function (): void {
    $form = gradedQuizForm(['quiz_grades' => [
        new Collection(['text' => 'B', 'value' => 50]),
        'not a row',
        ['text' => 'A', 'value' => 'high'],
        ['text' => ['nested'], 'value' => 40],
        ['value' => 30],
        ['text' => 99, 'value' => 10],
    ]]);

    expect(app(QuizScorer::class)->score($form, ['q1' => 'Paris', 'q2' => 'London'])->grade)->toBe('B')
        ->and(app(QuizScorer::class)->score($form, [])->grade)->toBe('');

    $low = gradedQuizForm(['quiz_grades' => [['text' => 99, 'value' => 0], ['text' => 'A', 'value' => 'high']]]);

    // A numeric grade text is read as text
    expect(app(QuizScorer::class)->score($low, [])->grade)->toBe('99');
});

it('keeps reading grade rows after a malformed one', function (mixed $malformed): void {
    $form = gradedQuizForm(['quiz_grades' => [$malformed, ['text' => 'D', 'value' => 0]]]);

    expect(app(QuizScorer::class)->score($form, [])->grade)->toBe('D');
})->with([
    'not a row' => ['not a row'],
    'a non-numeric threshold' => [['text' => 'A', 'value' => 'high']],
    'a non-text grade' => [['text' => ['nested'], 'value' => 0]],
]);

it('awards no grade when the grades are not a list', function (): void {
    $form = gradedQuizForm(['quiz_grades' => 'A']);

    expect(app(QuizScorer::class)->score($form, ['q1' => 'Paris', 'q2' => 'Paris'])->grade)->toBe('');
});

it('passes at exactly the pass threshold and fails below it', function (): void {
    $form = gradedQuizForm(['quiz_pass_percent' => '50']);

    expect(app(QuizScorer::class)->score($form, ['q1' => 'Paris', 'q2' => 'London'])->pass)->toBeTrue()
        ->and(app(QuizScorer::class)->score($form, [])->pass)->toBeFalse();
});

it('leaves pass empty when the pass threshold is not a number', function (): void {
    $form = gradedQuizForm(['quiz_pass_percent' => 'half']);

    expect(app(QuizScorer::class)->score($form, ['q1' => 'Paris'])->pass)->toBeNull();
});

it('captures the answer explanation whichever truthy form the toggle is stored as', function (mixed $enabled): void {
    $form = makeQuizForm(['q1' => [...radioQuizField(), 'answer_explanation_query' => $enabled, 'answer_explanation' => 'Because.']]);

    expect(app(QuizScorer::class)->score($form, [])->question('q1')?->explanation)->toBe('Because.');
})->with([
    'integer 1' => [1],
    'string 1' => ['1'],
]);

it('reports no explanation when the enabled explanation is empty or not text', function (mixed $explanation, ?string $expected): void {
    $form = makeQuizForm(['q1' => [...radioQuizField(), 'answer_explanation_query' => true, 'answer_explanation' => $explanation]]);

    expect(app(QuizScorer::class)->score($form, [])->question('q1')?->explanation)->toBe($expected);
})->with([
    'empty' => ['', null],
    'list' => [['Because.'], null],
    'number' => [42, '42'],
]);

it('falls back to the field handle when the display label is empty', function (): void {
    $form = makeQuizForm(['q1' => [...radioQuizField(), 'display' => '']]);

    expect(app(QuizScorer::class)->score($form, [])->question('q1')?->label)->toBe('q1');
});

it('reports no questions for a form without a blueprint', function (): void {
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('blueprint')->andReturn(null);
    $form->shouldReceive('get')->andReturn(null);

    $results = app(QuizScorer::class)->score($form, ['q1' => 'Paris']);

    expect($results->hasQuestions())->toBeFalse()
        ->and($results->maxScore)->toBe(0.0);
});
