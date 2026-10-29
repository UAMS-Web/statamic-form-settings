<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Form;
use Statamic\Forms\Submission;
use UAMSWeb\FormSettings\Tags\Quiz;

/**
 * Proves the {{ quiz }} tag (UAMS-Web/uams-statamic-archive#209) renders a scored submission the way a form
 * confirmation / email notification would: it reads the submission's augmented
 * context (the `form` plus the answers keyed by handle), scores it, and outputs
 * the score / percentage / grade / pass-fail and the per-question breakdown —
 * while a non-quiz form yields nothing.
 *
 * @covers \UAMSWeb\FormSettings\Tags\Quiz
 *
 * @command  vendor/bin/pest tests/Integration/Tags/QuizTagTest.php
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
 * Build a form + submission and return the augmented context the render paths use.
 *
 * @param  array<string, array<string, mixed>>  $fields
 * @param  array<string, mixed>  $answers
 * @return array<string, mixed>
 */
function quizSubmissionContext(array $fields, array $answers): array
{
    $handle = 'quiz_tag_'.str_replace('.', '', uniqid('', true));

    app(BlueprintRepository::class)->setFallback('forms.'.$handle, fn () => Blueprint::makeFromFields($fields));

    $form = makeForm($handle);
    $form->store(false);
    $form->save();

    $submission = mustBe(Submission::class, $form->makeSubmission(), 'submission');
    $submission->data($answers);

    /** @var array<string, mixed> $augmented */
    $augmented = $submission->toAugmentedArray();

    return $augmented;
}

/**
 * A radio quiz question (Paris correct) with an explanation.
 *
 * @return array<string, mixed>
 */
function tagRadioQuizField(): array
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
 * @param  array<string, mixed>  $context
 * @param  array<string, mixed>  $params
 */
function quizTag(array $context, array $params = []): Quiz
{
    $tag = new Quiz;
    $tag->setContext($context);
    $tag->setParameters($params);

    return $tag;
}

it('outputs the total score and percentage from the submission', function (): void {
    $context = quizSubmissionContext(
        ['q1' => tagRadioQuizField(), 'q2' => tagRadioQuizField()],
        ['q1' => 'Paris', 'q2' => 'London'],
    );

    expect(quizTag($context)->score())->toBe('1')
        ->and(quizTag($context)->percent())->toBe('50');
});

it('formats a fractional weighted score, trimming and rounding to two places', function (): void {
    $half = quizSubmissionContext(
        ['q1' => [
            'type' => 'quiz',
            'display' => 'Weighted',
            'question_fieldtype' => 'radio',
            'weighted_score_query' => true,
            'options' => [
                ['label' => 'Half', 'is_correct' => true, 'weighted_score' => 2.5],
                ['label' => 'Full', 'is_correct' => false, 'weighted_score' => 4],
            ],
        ]],
        ['q1' => 'Half'],
    );

    // A checkbox sum with float imprecision (0.1 + 0.2) must round to two places (0.3).
    $rounded = quizSubmissionContext(
        ['q1' => [
            'type' => 'quiz',
            'display' => 'Weighted checkbox',
            'question_fieldtype' => 'checkboxes',
            'weighted_score_query' => true,
            'options' => [
                ['label' => 'A', 'is_correct' => true, 'weighted_score' => 0.1],
                ['label' => 'B', 'is_correct' => true, 'weighted_score' => 0.2],
            ],
        ]],
        ['q1' => ['A', 'B']],
    );

    expect(quizTag($half)->score())->toBe('2.5')
        ->and(quizTag($rounded)->score())->toBe('0.3');
});

it('outputs a single question score with a handle parameter', function (): void {
    $context = quizSubmissionContext(['q1' => tagRadioQuizField()], ['q1' => 'Paris']);

    expect(quizTag($context, ['handle' => 'q1'])->score())->toBe('1');
});

it('renders the question, the chosen and correct answers, and the explanation', function (): void {
    $context = quizSubmissionContext(['q1' => tagRadioQuizField()], ['q1' => 'London']);

    $html = quizTag($context, ['handle' => 'q1'])->index();

    expect($html)->toContain('Capital of France?')
        ->toContain('Paris (correct answer)')
        ->toContain('London (your answer)')
        ->toContain('Paris is the capital of France.')
        ->toContain('data-handle="q1"');
});

it('escapes answer labels and explanations rendered into the confirmation/email HTML', function (): void {
    $context = quizSubmissionContext(
        ['q1' => [
            'type' => 'quiz',
            'display' => 'Pick one',
            'question_fieldtype' => 'radio',
            'options' => [
                ['label' => '<script>alert(1)</script>', 'is_correct' => true],
                ['label' => 'Safe', 'is_correct' => false],
            ],
            'answer_explanation_query' => true,
            'answer_explanation' => '<img src=x onerror=alert(2)>',
        ]],
        ['q1' => '<script>alert(1)</script>'],
    );

    $html = quizTag($context, ['handle' => 'q1'])->index();

    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->not->toContain('<img src=x onerror=alert(2)>');
    expect($html)->toContain('&lt;script&gt;');
});

it('emits the grade and pass/fail only when the form carries grading config', function (): void {
    $handle = 'quiz_grade_'.str_replace('.', '', uniqid('', true));
    app(BlueprintRepository::class)->setFallback('forms.'.$handle, fn () => Blueprint::makeFromFields([
        'q1' => tagRadioQuizField(),
        'q2' => tagRadioQuizField(),
    ]));
    $form = makeForm($handle);
    $form->store(false);
    $form->set('quiz_pass_percent', 50);
    $form->set('quiz_grades', [['text' => 'A', 'value' => 90], ['text' => 'B', 'value' => 50]]);
    $form->save();

    $submission = mustBe(Submission::class, $form->makeSubmission(), 'submission');
    $submission->data(['q1' => 'Paris', 'q2' => 'London']);
    /** @var array<string, mixed> $context */
    $context = $submission->toAugmentedArray();

    expect(quizTag($context)->grade())->toBe('B')
        ->and(quizTag($context)->passfail())->toBe('Passed');
});

it('outputs nothing for a form without quiz fields', function (): void {
    $context = quizSubmissionContext(['name' => ['type' => 'text', 'display' => 'Name']], ['name' => 'Pat']);

    expect(quizTag($context)->score())->toBe('')
        ->and(quizTag($context)->percent())->toBe('')
        ->and(quizTag($context)->grade())->toBe('')
        ->and(quizTag($context)->passfail())->toBe('')
        ->and(quizTag($context)->index())->toBe('');
});

it('outputs nothing when there is no form in context', function (): void {
    expect(quizTag([])->score())->toBe('')
        ->and(quizTag([])->index())->toBe('');
});
