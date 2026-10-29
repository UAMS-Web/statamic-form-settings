<?php

declare(strict_types=1);

use Statamic\Facades\Blueprint;
use Statamic\Fields\Value;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Scoring\Concerns\ResolvesScoredSubmission;
use UAMSWeb\FormSettings\Tags\Quiz;
use UAMSWeb\FormSettings\Tags\Survey;

/**
 * Pins the exact output of the {{ quiz }} and {{ survey }} tags and the shared
 * ResolvesScoredSubmission plumbing they run on.
 *
 * The form is a Mockery double carrying a real blueprint, so nothing is written
 * to disk: the scorers run for real against it, and every assertion compares
 * whole strings rather than fragments.
 *
 * @command  vendor/bin/pest tests/Integration/Tags/ScoredSubmissionTagsTest.php
 */
covers(Quiz::class, Survey::class, ResolvesScoredSubmission::class);

/**
 * A form double with a real blueprint and the given form-level config.
 *
 * @param  array<string, array<string, mixed>>  $fields
 * @param  array<string, mixed>  $config  Form-level values, such as `quiz_grades`.
 */
function scoredTagForm(array $fields, array $config = []): Form
{
    $blueprint = Blueprint::makeFromFields($fields);

    $form = Mockery::mock(Form::class);
    $form->shouldReceive('blueprint')->andReturn($blueprint);
    $form->shouldReceive('get')->andReturnUsing(fn (string $key): mixed => $config[$key] ?? null);

    return $form;
}

/**
 * A radio quiz question with Paris correct and an explanation.
 *
 * @return array<string, mixed>
 */
function scoredTagQuizField(): array
{
    return [
        'type' => 'quiz',
        'display' => 'Capital of France?',
        'question_fieldtype' => 'radio',
        'options' => [
            ['label' => 'Paris', 'is_correct' => true],
            ['label' => 'London', 'is_correct' => false],
        ],
        'answer_explanation_query' => true,
        'answer_explanation' => 'Paris is the capital of France.',
    ];
}

/**
 * A satisfaction Likert question, optionally with rows.
 *
 * @param  array<int, string>  $rows
 * @return array<string, mixed>
 */
function scoredTagLikertField(array $rows = []): array
{
    return array_filter([
        'type' => 'likert_scale',
        'display' => 'How satisfied are you?',
        'scale_type' => 'satisfaction',
        'number_of_points' => 5,
        'enable_multiple_rows' => $rows !== [],
        'multiple_rows' => $rows,
    ]);
}

/**
 * @param  array<string, mixed>  $context
 * @param  array<string, mixed>  $params
 */
function scoredQuizTag(array $context, array $params = []): Quiz
{
    $tag = new Quiz;
    $tag->setContext($context);
    $tag->setParameters($params);

    return $tag;
}

/**
 * @param  array<string, mixed>  $context
 * @param  array<string, mixed>  $params
 */
function scoredSurveyTag(array $context, array $params = []): Survey
{
    $tag = new Survey;
    $tag->setContext($context);
    $tag->setParameters($params);

    return $tag;
}

it('renders a quiz question exactly, marking the correct and the chosen answers', function (): void {
    $form = scoredTagForm(['q1' => scoredTagQuizField()]);

    $wrong = scoredQuizTag(['form' => $form, 'q1' => 'London'])->index();
    $right = scoredQuizTag(['form' => $form, 'q1' => 'Paris'])->index();

    expect($wrong)->toBe(implode("\n", [
        '<div class="quiz-result" data-handle="q1">',
        '<p class="quiz-question">Capital of France?</p>',
        '<ul class="quiz-answers">',
        '<li class="quiz-answer is-correct">Paris (correct answer)</li>',
        '<li class="quiz-answer is-selected">London (your answer)</li>',
        '</ul>',
        '<p class="quiz-explanation">Paris is the capital of France.</p>',
        '</div>',
    ]))->and($right)->toBe(implode("\n", [
        '<div class="quiz-result" data-handle="q1">',
        '<p class="quiz-question">Capital of France?</p>',
        '<ul class="quiz-answers">',
        '<li class="quiz-answer is-correct is-selected">Paris (correct answer, your answer)</li>',
        '<li class="quiz-answer">London</li>',
        '</ul>',
        '<p class="quiz-explanation">Paris is the capital of France.</p>',
        '</div>',
    ]));
});

it('renders every quiz question, one block per line, and omits a disabled explanation', function (): void {
    $plain = [...scoredTagQuizField(), 'display' => 'Second?', 'answer_explanation_query' => false];
    $form = scoredTagForm(['q1' => scoredTagQuizField(), 'q2' => $plain]);

    $html = scoredQuizTag(['form' => $form])->index();

    expect($html)->toBe(implode("\n", [
        '<div class="quiz-result" data-handle="q1">',
        '<p class="quiz-question">Capital of France?</p>',
        '<ul class="quiz-answers">',
        '<li class="quiz-answer is-correct">Paris (correct answer)</li>',
        '<li class="quiz-answer">London</li>',
        '</ul>',
        '<p class="quiz-explanation">Paris is the capital of France.</p>',
        '</div>',
        '<div class="quiz-result" data-handle="q2">',
        '<p class="quiz-question">Second?</p>',
        '<ul class="quiz-answers">',
        '<li class="quiz-answer is-correct">Paris (correct answer)</li>',
        '<li class="quiz-answer">London</li>',
        '</ul>',
        '</div>',
    ]));
});

it('renders nothing for a quiz handle that names no question', function (): void {
    $form = scoredTagForm(['q1' => scoredTagQuizField()]);

    expect(scoredQuizTag(['form' => $form], ['handle' => 'missing'])->index())->toBe('')
        ->and(scoredQuizTag(['form' => $form], ['handle' => 'missing'])->score())->toBe('');
});

it('reports the quiz grade, pass flag and percentage from the form config', function (mixed $answer, string $grade, string $passfail, string $percent): void {
    $form = scoredTagForm(['q1' => scoredTagQuizField()], [
        'quiz_grades' => [['text' => 'A', 'value' => 100], ['text' => 'F', 'value' => 0]],
        'quiz_pass_percent' => 50,
    ]);
    $context = ['form' => $form, 'q1' => $answer];

    expect(scoredQuizTag($context)->grade())->toBe($grade)
        ->and(scoredQuizTag($context)->passfail())->toBe($passfail)
        ->and(scoredQuizTag($context)->percent())->toBe($percent);
})->with([
    'right' => ['Paris', 'A', 'Passed', '100'],
    'wrong' => ['London', 'F', 'Failed', '0'],
]);

it('reports no pass flag when the form sets no pass threshold', function (): void {
    $form = scoredTagForm(['q1' => scoredTagQuizField()]);

    expect(scoredQuizTag(['form' => $form, 'q1' => 'Paris'])->passfail())->toBe('');
});

it('reports nothing from a quiz form with no quiz questions, whatever its config', function (): void {
    $form = scoredTagForm(['name' => ['type' => 'text']], [
        'quiz_grades' => [['text' => 'F', 'value' => 0]],
        'quiz_pass_percent' => 0,
    ]);
    $context = ['form' => $form];

    expect(scoredQuizTag($context)->grade())->toBe('')
        ->and(scoredQuizTag($context)->passfail())->toBe('')
        ->and(scoredQuizTag($context)->percent())->toBe('')
        ->and(scoredQuizTag($context)->score())->toBe('')
        ->and(scoredQuizTag($context)->index())->toBe('');
});

it('reports nothing from either tag when the context holds no form', function (mixed $form): void {
    $context = ['form' => $form];

    expect(scoredQuizTag($context)->index())->toBe('')
        ->and(scoredQuizTag($context)->score())->toBe('')
        ->and(scoredQuizTag($context)->percent())->toBe('')
        ->and(scoredQuizTag($context)->grade())->toBe('')
        ->and(scoredQuizTag($context)->passfail())->toBe('')
        ->and(scoredSurveyTag($context)->index())->toBe('')
        ->and(scoredSurveyTag($context)->score())->toBe('');
})->with([
    'missing' => [null],
    'a string' => ['contact'],
    'a value holding no form' => [new Value('contact')],
]);

it('reads a form and answers wrapped in values', function (): void {
    $form = scoredTagForm(['q1' => scoredTagQuizField()]);
    $context = ['form' => new Value($form), 'q1' => new Value('Paris')];

    expect(scoredQuizTag($context)->score())->toBe('1')
        ->and(scoredSurveyTag($context)->score())->toBe('');
});

it('reads only the answers the blueprint names', function (): void {
    $form = scoredTagForm(['q1' => scoredTagQuizField()]);

    // An answer under another handle is not read for q1
    expect(scoredQuizTag(['form' => $form, 'q2' => 'Paris'])->score())->toBe('0');
});

it('reports nothing for a form without a blueprint', function (): void {
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('blueprint')->andReturn(null);
    $form->shouldReceive('get')->andReturn(null);

    expect(scoredQuizTag(['form' => $form])->score())->toBe('')
        ->and(scoredSurveyTag(['form' => $form])->score())->toBe('');
});

it('reads a scalar handle parameter as text and ignores any other', function (): void {
    $form = scoredTagForm([
        'q1' => scoredTagQuizField(),
        'q2' => [...scoredTagQuizField(), 'display' => 'Second?'],
    ]);
    $context = ['form' => $form, 'q1' => 'London', 'q2' => 'Paris'];

    // A number names no question here, so it reads as the text '7' and matches nothing
    expect(scoredQuizTag($context, ['handle' => 7])->score())->toBe('')
        ->and(scoredQuizTag($context, ['handle' => ['q2']])->score())->toBe('1')
        ->and(scoredQuizTag($context, ['handle' => 'q2'])->score())->toBe('1')
        ->and(scoredQuizTag($context, ['handle' => 'q1'])->score())->toBe('0');
});

it('formats a score compactly', function (float $weight, string $expected): void {
    $form = scoredTagForm(['q1' => [
        'type' => 'quiz',
        'question_fieldtype' => 'radio',
        'weighted_score_query' => true,
        'options' => [['label' => 'Pick', 'is_correct' => true, 'weighted_score' => $weight]],
    ]]);

    expect(scoredQuizTag(['form' => $form, 'q1' => 'Pick'])->score())->toBe($expected)
        ->and(scoredQuizTag(['form' => $form, 'q1' => 'Pick'], ['handle' => 'q1'])->score())->toBe($expected);
})->with([
    'a whole number' => [10.0, '10'],
    'one decimal' => [2.5, '2.5'],
    'two decimals' => [1.25, '1.25'],
    'more than two decimals' => [1.256, '1.26'],
    'rounding to a whole number' => [100.001, '100'],
    'rounding away a trailing zero' => [1.104, '1.1'],
    'thousands, with no separator' => [1234.5, '1234.5'],
]);

it('renders a single-row survey question exactly', function (): void {
    $form = scoredTagForm(['q1' => scoredTagLikertField()]);

    expect(scoredSurveyTag(['form' => $form, 'q1' => '4'], ['handle' => 'q1'])->index())->toBe(implode("\n", [
        '<div class="survey-result" data-handle="q1">',
        '<p class="survey-question">How satisfied are you?</p>',
        '<ul class="survey-answers">',
        '<li class="survey-answer">Satisfied</li>',
        '</ul>',
        '</div>',
    ]));
});

it('renders survey rows with their escaped row label, and omits the list for an unanswered question', function (): void {
    $form = scoredTagForm([
        'q1' => scoredTagLikertField(['Staff & crew', 'Value']),
        'q2' => [...scoredTagLikertField(), 'display' => 'Again?'],
    ]);
    $context = ['form' => $form, 'q1' => ['Staff & crew' => '5', 'Value' => '2']];

    expect(scoredSurveyTag($context)->index())->toBe(implode("\n", [
        '<div class="survey-result" data-handle="q1">',
        '<p class="survey-question">How satisfied are you?</p>',
        '<ul class="survey-answers">',
        '<li class="survey-answer">Staff &amp; crew: Very Satisfied</li>',
        '<li class="survey-answer">Value: Dissatisfied</li>',
        '</ul>',
        '</div>',
        '<div class="survey-result" data-handle="q2">',
        '<p class="survey-question">Again?</p>',
        '</div>',
    ]))->and(scoredSurveyTag($context)->score())->toBe('7')
        ->and(scoredSurveyTag($context, ['handle' => 'q1'])->score())->toBe('7')
        ->and(scoredSurveyTag($context, ['handle' => 'q2'])->score())->toBe('0');
});

it('renders nothing for a survey handle that names no question', function (): void {
    $form = scoredTagForm(['q1' => scoredTagLikertField()]);

    expect(scoredSurveyTag(['form' => $form], ['handle' => 'missing'])->index())->toBe('')
        ->and(scoredSurveyTag(['form' => $form], ['handle' => 'missing'])->score())->toBe('');
});

it('reports nothing from a survey form with no survey questions', function (): void {
    $form = scoredTagForm(['name' => ['type' => 'text']]);

    expect(scoredSurveyTag(['form' => $form])->index())->toBe('')
        ->and(scoredSurveyTag(['form' => $form])->score())->toBe('');
});
