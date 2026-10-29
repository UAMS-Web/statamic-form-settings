<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Scoring\SurveyScorer;

/**
 * Proves the survey scoring engine (UAMS-Web/uams-statamic-archive#210) sums the numeric Likert points a
 * respondent selected, a single-row Likert contributes its one point, a
 * multi-row Likert matrix sums its per-row points, and `not_applicable` scores
 * zero (but counts as answered).
 *
 * @covers \UAMSWeb\FormSettings\Scoring\SurveyScorer
 *
 * @command  vendor/bin/pest tests/Integration/Scoring/SurveyScorerTest.php
 */
$surveyFormsDir = '';

beforeEach(function () use (&$surveyFormsDir): void {
    $surveyFormsDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($surveyFormsDir);
    Config::set('statamic.forms.forms', $surveyFormsDir);
});

afterEach(function () use (&$surveyFormsDir): void {
    if ($surveyFormsDir !== '' && File::isDirectory($surveyFormsDir)) {
        File::deleteDirectory($surveyFormsDir);
    }
});

/**
 * Build + save a form whose blueprint carries the given fields.
 *
 * @param  array<string, array<string, mixed>>  $fields
 */
function makeSurveyForm(array $fields): Form
{
    $handle = 'survey_probe_'.str_replace('.', '', uniqid('', true));

    app(BlueprintRepository::class)->setFallback('forms.'.$handle, fn () => Blueprint::makeFromFields($fields));

    $form = makeForm($handle);
    $form->store(false);
    $form->save();

    return $form;
}

/**
 * A single-row Likert question.
 *
 * @param  array<int, string>  $rows
 * @return array<string, mixed>
 */
function likertField(bool $multipleRows = false, array $rows = []): array
{
    return array_filter([
        'type' => 'likert_scale',
        'display' => 'How satisfied are you?',
        'scale_type' => 'satisfaction',
        'number_of_points' => 5,
        'enable_not_applicable' => true,
        'enable_multiple_rows' => $multipleRows,
        'multiple_rows' => $rows,
    ]);
}

it('scores a single-row Likert question as the selected numeric point', function (): void {
    $form = makeSurveyForm(['q1' => likertField()]);

    $results = app(SurveyScorer::class)->score($form, ['q1' => '4']);

    expect($results->score)->toBe(4.0)
        ->and($results->question('q1')?->answered)->toBeTrue()
        ->and($results->question('q1')?->rows[0]['score'])->toBe(4.0)
        ->and($results->question('q1')?->rows[0]['label'])->not->toBe('');
});

it('scores an unanswered Likert question 0 and marks it unanswered', function (): void {
    $form = makeSurveyForm(['q1' => likertField()]);

    $results = app(SurveyScorer::class)->score($form, []);

    expect($results->score)->toBe(0.0)
        ->and($results->question('q1')?->answered)->toBeFalse()
        ->and($results->question('q1')?->rows)->toBe([]);
});

it('scores not_applicable as 0 but counts it as answered', function (): void {
    $form = makeSurveyForm(['q1' => likertField()]);

    $results = app(SurveyScorer::class)->score($form, ['q1' => 'not_applicable']);

    expect($results->score)->toBe(0.0)
        ->and($results->question('q1')?->answered)->toBeTrue()
        ->and($results->question('q1')?->rows[0]['score'])->toBe(0.0);
});

it('sums per-row points for a multi-row Likert matrix', function (): void {
    $form = makeSurveyForm(['q1' => likertField(multipleRows: true, rows: ['Cleanliness', 'Staff', 'Value'])]);

    $results = app(SurveyScorer::class)->score($form, [
        'q1' => ['Cleanliness' => '5', 'Staff' => '3', 'Value' => 'not_applicable'],
    ]);

    expect($results->score)->toBe(8.0)
        ->and($results->question('q1')?->multipleRows)->toBeTrue()
        ->and($results->question('q1')?->rows)->toHaveCount(3);
});

it('skips a configured multi-row row left blank and ignores an unknown submitted row', function (): void {
    $form = makeSurveyForm(['q1' => likertField(multipleRows: true, rows: ['A', 'B', 'C'])]);

    // Only row A answered; B and C left blank; 'D' is not a configured row.
    $results = app(SurveyScorer::class)->score($form, ['q1' => ['A' => '5', 'D' => '4']]);

    $question = $results->question('q1');

    expect($results->score)->toBe(5.0)
        ->and($question?->answered)->toBeTrue()
        ->and($question?->rows)->toHaveCount(1)
        ->and($question?->rows[0]['row'])->toBe('A');
});

it('reports a fully-blank multi-row Likert question as unanswered', function (): void {
    $form = makeSurveyForm(['q1' => likertField(multipleRows: true, rows: ['A', 'B'])]);

    $results = app(SurveyScorer::class)->score($form, ['q1' => []]);

    expect($results->score)->toBe(0.0)
        ->and($results->question('q1')?->answered)->toBeFalse()
        ->and($results->question('q1')?->rows)->toBe([]);
});

it('totals the score across multiple Likert questions', function (): void {
    $form = makeSurveyForm(['q1' => likertField(), 'q2' => likertField()]);

    $results = app(SurveyScorer::class)->score($form, ['q1' => '4', 'q2' => '2']);

    expect($results->score)->toBe(6.0)
        ->and($results->questions)->toHaveCount(2);
});

it('scores a matrix row whose label contains brackets under its normalized key', function (): void {
    $form = makeSurveyForm(['q1' => likertField(multipleRows: true, rows: ['Wait [min]'])]);

    // The front-end template writes name="q1[Wait min]" (brackets stripped so PHP's
    // array-name parser keeps the whole key), so the submission is keyed by the
    // normalized label; the scorer must find it via the same normalization.
    $results = app(SurveyScorer::class)->score($form, ['q1' => ['Wait min' => '4']]);

    expect($results->score)->toBe(4.0)
        ->and($results->question('q1')?->rows)->toHaveCount(1)
        ->and($results->question('q1')?->rows[0]['row'])->toBe('Wait min');
});

it('does not double-count a duplicated matrix row label', function (): void {
    $form = makeSurveyForm(['q1' => likertField(multipleRows: true, rows: ['Food', 'Food'])]);

    // Duplicate labels collapse to one row → scored once, not twice.
    $results = app(SurveyScorer::class)->score($form, ['q1' => ['Food' => '4']]);

    expect($results->score)->toBe(4.0)
        ->and($results->question('q1')?->rows)->toHaveCount(1);
});

it('reports no questions for a form without Likert fields', function (): void {
    $form = makeSurveyForm(['name' => ['type' => 'text', 'display' => 'Name']]);

    $results = app(SurveyScorer::class)->score($form, ['name' => 'Pat']);

    expect($results->hasQuestions())->toBeFalse()
        ->and($results->score)->toBe(0.0);
});

it('records each answered row with its row label, option label and score', function (): void {
    $single = makeSurveyForm(['q1' => likertField()]);
    $matrix = makeSurveyForm(['q1' => likertField(multipleRows: true, rows: ['Staff', 'Value'])]);

    $singleRows = app(SurveyScorer::class)->score($single, ['q1' => '4'])->question('q1')?->rows;
    $matrixRows = app(SurveyScorer::class)->score($matrix, ['q1' => ['Staff' => '5', 'Value' => 'not_applicable']])->question('q1')?->rows;

    // The option label comes from the fieldtype's preload, and the N/A option keeps its label
    expect($singleRows)->toBe([['row' => null, 'label' => 'Satisfied', 'score' => 4.0]])
        ->and($matrixRows)->toBe([
            ['row' => 'Staff', 'label' => 'Very Satisfied', 'score' => 5.0],
            ['row' => 'Value', 'label' => 'Not Applicable', 'score' => 0.0],
        ]);
});

it('gives no points for a single-row value outside the scale', function (mixed $submitted): void {
    $form = makeSurveyForm(['q1' => likertField()]);

    // A point the scale does not offer is not a rating, so it scores nothing (UAMS-Web/uams-statamic-archive#3105)
    $results = app(SurveyScorer::class)->score($form, ['q1' => $submitted]);

    expect($results->score)->toBe(0.0)
        ->and($results->question('q1')?->answered)->toBeFalse()
        ->and($results->question('q1')?->rows)->toBe([]);
})->with([
    'an integer past the top' => 9,
    'numeric text past the top' => '9',
    'zero' => '0',
    'text' => 'bogus',
    'a fraction between two points' => 4.5,
]);

it('gives no points for a matrix row whose value is outside the scale', function (): void {
    $form = makeSurveyForm(['q1' => likertField(multipleRows: true, rows: ['Staff', 'Value'])]);

    // The in-scale row scores; the out-of-scale row is dropped (UAMS-Web/uams-statamic-archive#3105)
    $results = app(SurveyScorer::class)->score($form, ['q1' => ['Staff' => '4', 'Value' => '9']]);

    expect($results->score)->toBe(4.0)
        ->and($results->question('q1')?->rows)->toBe([['row' => 'Staff', 'label' => 'Satisfied', 'score' => 4.0]]);
});

it('treats an empty, false or non-scalar single-row submission as unanswered', function (mixed $submitted): void {
    $form = makeSurveyForm(['q1' => likertField()]);

    $question = app(SurveyScorer::class)->score($form, ['q1' => $submitted])->question('q1');

    expect($question?->answered)->toBeFalse()
        ->and($question?->rows)->toBe([])
        ->and($question?->score)->toBe(0.0);
})->with([
    'empty string' => [''],
    'false' => [false],
    'array' => [['4']],
]);

it('reads a matrix submission held in a collection', function (): void {
    $form = makeSurveyForm(['q1' => likertField(multipleRows: true, rows: ['Staff'])]);

    $results = app(SurveyScorer::class)->score($form, ['q1' => new Collection(['Staff' => '3'])]);

    expect($results->score)->toBe(3.0)
        ->and($results->question('q1')?->rows)->toBe([['row' => 'Staff', 'label' => 'Neutral', 'score' => 3.0]]);
});

it('scores the matrix whichever truthy form the multiple-rows toggle is stored as', function (mixed $enabled): void {
    $field = [...likertField(), 'enable_multiple_rows' => $enabled, 'multiple_rows' => ['Staff']];
    $form = makeSurveyForm(['q1' => $field]);

    $question = app(SurveyScorer::class)->score($form, ['q1' => ['Staff' => '2']])->question('q1');

    expect($question?->multipleRows)->toBeTrue()
        ->and($question?->score)->toBe(2.0);
})->with([
    'true' => [true],
    'integer 1' => [1],
    'string 1' => ['1'],
]);

it('falls back to the field handle when the display label is empty', function (): void {
    $withoutDisplay = likertField();
    unset($withoutDisplay['display']);

    $missing = makeSurveyForm(['q1' => $withoutDisplay]);
    $empty = makeSurveyForm(['q1' => [...likertField(), 'display' => '']]);

    // A missing display is filled by Statamic from the handle; an empty one reaches the fallback
    expect(app(SurveyScorer::class)->score($missing, [])->question('q1')?->label)->toBe('Q1')
        ->and(app(SurveyScorer::class)->score($empty, [])->question('q1')?->label)->toBe('q1')
        ->and(app(SurveyScorer::class)->score(makeSurveyForm(['q1' => likertField()]), [])->question('q1')?->label)
        ->toBe('How satisfied are you?');
});

it('reports no questions for a form without a blueprint', function (): void {
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('blueprint')->andReturn(null);

    $results = app(SurveyScorer::class)->score($form, ['q1' => '4']);

    expect($results->hasQuestions())->toBeFalse()
        ->and($results->score)->toBe(0.0);
});
