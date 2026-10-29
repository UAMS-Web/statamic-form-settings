<?php

declare(strict_types=1);

use UAMSWeb\FormSettings\Scoring\Survey\SurveyQuestionResult;
use UAMSWeb\FormSettings\Scoring\Survey\SurveyResults;

covers(SurveyResults::class, SurveyQuestionResult::class);

/**
 * The survey result value objects (UAMS-Web/uams-statamic-archive#210, UAMS-Web/uams-statamic-archive#3091): `SurveyResults` reports whether
 * it holds any question and finds one by handle, returning the first match and
 * null for an unknown handle; `SurveyQuestionResult` carries what the scorer
 * resolved.
 *
 * @command  vendor/bin/pest tests/Unit/Scoring/Survey/SurveyResultsTest.php
 */

/**
 * A question result with the given handle and score.
 */
function surveyResultsQuestion(string $handle, float $score = 1.0): SurveyQuestionResult
{
    return new SurveyQuestionResult(
        handle: $handle,
        label: 'Label for '.$handle,
        score: $score,
        answered: true,
        multipleRows: false,
        rows: [],
    );
}

it('reports whether it holds any question', function (): void {
    expect(new SurveyResults(score: 0.0, questions: [])->hasQuestions())->toBeFalse()
        ->and(new SurveyResults(score: 1.0, questions: [surveyResultsQuestion('q1')])->hasQuestions())->toBeTrue();
});

it('finds a question by handle, wherever it sits in the list', function (): void {
    $first = surveyResultsQuestion('q1');
    $second = surveyResultsQuestion('q2');
    $results = new SurveyResults(score: 2.0, questions: [$first, $second]);

    expect($results->question('q1'))->toBe($first)
        ->and($results->question('q2'))->toBe($second);
});

it('returns the first question when two share a handle', function (): void {
    $first = surveyResultsQuestion('q1', 1.0);
    $results = new SurveyResults(score: 1.0, questions: [$first, surveyResultsQuestion('q1', 0.0)]);

    expect($results->question('q1'))->toBe($first);
});

it('returns null for a handle it does not hold', function (): void {
    expect(new SurveyResults(score: 1.0, questions: [surveyResultsQuestion('q1')])->question('q9'))->toBeNull()
        ->and(new SurveyResults(score: 0.0, questions: [])->question('q1'))->toBeNull();
});
