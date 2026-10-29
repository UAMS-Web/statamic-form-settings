<?php

declare(strict_types=1);

use UAMSWeb\FormSettings\Scoring\Quiz\QuizQuestionResult;
use UAMSWeb\FormSettings\Scoring\Quiz\QuizResults;

covers(QuizResults::class, QuizQuestionResult::class);

/**
 * The quiz result value objects (UAMS-Web/uams-statamic-archive#209, UAMS-Web/uams-statamic-archive#3091): `QuizResults` reports whether it
 * holds any question and finds one by handle, returning the first match and null
 * for an unknown handle; `QuizQuestionResult` carries what the scorer resolved.
 *
 * @command  vendor/bin/pest tests/Unit/Scoring/Quiz/QuizResultsTest.php
 */

/**
 * A question result with the given handle and score.
 */
function quizResultsQuestion(string $handle, float $score = 1.0): QuizQuestionResult
{
    return new QuizQuestionResult(
        handle: $handle,
        label: 'Label for '.$handle,
        score: $score,
        isCorrect: true,
        answered: true,
        submitted: ['Paris'],
        correctAnswers: ['Paris'],
        explanation: null,
        choices: [],
    );
}

/**
 * Results holding the given questions.
 *
 * @param  list<QuizQuestionResult>  $questions
 */
function quizResultsWith(array $questions): QuizResults
{
    return new QuizResults(
        score: 0.0,
        maxScore: 0.0,
        percent: 0,
        rawPercent: 0.0,
        grade: '',
        pass: null,
        questions: $questions,
    );
}

it('reports whether it holds any question', function (): void {
    expect(quizResultsWith([])->hasQuestions())->toBeFalse()
        ->and(quizResultsWith([quizResultsQuestion('q1')])->hasQuestions())->toBeTrue();
});

it('finds a question by handle, wherever it sits in the list', function (): void {
    $first = quizResultsQuestion('q1');
    $second = quizResultsQuestion('q2');
    $results = quizResultsWith([$first, $second]);

    expect($results->question('q1'))->toBe($first)
        ->and($results->question('q2'))->toBe($second);
});

it('returns the first question when two share a handle', function (): void {
    $first = quizResultsQuestion('q1', 1.0);
    $results = quizResultsWith([$first, quizResultsQuestion('q1', 0.0)]);

    expect($results->question('q1'))->toBe($first);
});

it('returns null for a handle it does not hold', function (): void {
    expect(quizResultsWith([quizResultsQuestion('q1')])->question('q9'))->toBeNull()
        ->and(quizResultsWith([])->question('q1'))->toBeNull();
});
