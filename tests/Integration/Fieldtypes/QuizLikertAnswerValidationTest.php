<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Statamic\Fields\Fields;
use Statamic\Fields\Validator;

/**
 * A `quiz` or `likert_scale` submission must carry one of the field's configured
 * answers, or validation fails with a field error (UAMS-Web/uams-statamic-archive#3105).
 *
 * Each case runs through `Fields::validator()`, the validator a public form
 * submission is checked with, so the rules are exercised the way a submit
 * applies them.
 *
 * @command  vendor/bin/pest tests/Integration/Fieldtypes/QuizLikertAnswerValidationTest.php
 */

/**
 * Validate one submitted value against a single field and return its errors.
 *
 * @param  array<string, mixed>  $config  The field config, `type` included.
 * @param  mixed  $value  The submitted value.
 * @return list<string> The error messages raised for the field, empty when it passes.
 */
function answerValidationErrors(array $config, mixed $value): array
{
    // Build the one-field set and validate the submission against it
    $fields = new Fields([['handle' => 'q1', 'field' => $config]]);

    try {
        $withValues = mustBe(Fields::class, $fields->addValues(['q1' => $value]), 'fields');
        mustBe(Validator::class, $withValues->validator(), 'validator')->validate();
    } catch (ValidationException $validationException) {
        /** @var list<string> $messages */
        $messages = $validationException->errors()['q1'] ?? [];

        return $messages;
    }

    return [];
}

/**
 * A quiz question with three answers, Paris the correct one.
 *
 * @param  string  $type  The `question_fieldtype` config value.
 * @return array<string, mixed> The field config.
 */
function validationQuizField(string $type): array
{
    return [
        'type' => 'quiz',
        'display' => 'Capital of France?',
        'question_fieldtype' => $type,
        'options' => [
            ['label' => 'Paris', 'is_correct' => true],
            ['label' => 'London', 'is_correct' => false],
            ['label' => 'Berlin', 'is_correct' => false],
        ],
    ];
}

/**
 * A five-point satisfaction scale, optionally with Not Applicable and matrix rows.
 *
 * @param  bool  $notApplicable  Whether the Not Applicable option is enabled.
 * @param  list<string>  $rows  Matrix row labels; empty for a single-row question.
 * @return array<string, mixed> The field config.
 */
function validationLikertField(bool $notApplicable = false, array $rows = []): array
{
    return array_filter([
        'type' => 'likert_scale',
        'display' => 'How satisfied are you?',
        'scale_type' => 'satisfaction',
        'number_of_points' => 5,
        'enable_not_applicable' => $notApplicable,
        'enable_multiple_rows' => $rows !== [],
        'multiple_rows' => $rows,
    ]);
}

it('accepts a configured quiz answer', function (string $type): void {
    expect(answerValidationErrors(validationQuizField($type), 'London'))->toBe([]);
})->with(['radio', 'select']);

it('rejects a quiz answer the question does not offer', function (string $type, mixed $value): void {
    expect(answerValidationErrors(validationQuizField($type), $value))
        ->toBe([__('statamic-form-settings::validation.quiz_answer.not_an_option')]);
})->with(['radio', 'select'])->with([
    'an unknown label' => 'Madrid',
    'a label in the wrong case' => 'paris',
    'a list of answers' => [['Paris']],
    'a boolean, which compares loosely equal to any label' => true,
]);

it('accepts any set of configured checkbox answers', function (array $value): void {
    expect(answerValidationErrors(validationQuizField('checkboxes'), $value))->toBe([]);
})->with([
    'one' => [['Paris']],
    'several' => [['Paris', 'Berlin']],
]);

it('rejects a checkbox answer set carrying an unknown answer', function (array $value): void {
    expect(answerValidationErrors(validationQuizField('checkboxes'), $value))
        ->toBe([__('statamic-form-settings::validation.quiz_answer.not_an_option')]);
})->with([
    'unknown first' => [['Madrid', 'Paris']],
    'unknown last' => [['Paris', 'Madrid']],
    'a nested list' => [[['Paris']]],
]);

it('accepts a single-row rating on the scale', function (mixed $value): void {
    expect(answerValidationErrors(validationLikertField(notApplicable: true), $value))->toBe([]);
})->with(['the lowest point' => '1', 'the highest point' => '5', 'an integer' => 3, 'not applicable' => 'not_applicable']);

it('rejects a single-row rating off the scale', function (mixed $value): void {
    expect(answerValidationErrors(validationLikertField(), $value))
        ->toBe([__('statamic-form-settings::validation.likert_scale.not_on_scale')]);
})->with([
    'past the top' => '6',
    'zero' => '0',
    'not applicable while disabled' => 'not_applicable',
    'text' => 'bogus',
    'a point in another notation, which compares loosely equal' => '1e0',
    'a row map' => [['Staff' => '3']],
]);

it('accepts a matrix rating on the scale for listed rows', function (array $value): void {
    expect(answerValidationErrors(validationLikertField(rows: ['Staff', 'Value']), $value))->toBe([]);
})->with([
    'every row' => [['Staff' => '4', 'Value' => '2']],
    'one row, the other left out' => [['Value' => '5']],
    'one row submitted empty' => [['Staff' => '', 'Value' => '5']],
    'one row submitted null' => [['Staff' => null, 'Value' => '5']],
]);

it('rejects a matrix rating off the scale', function (array $value): void {
    expect(answerValidationErrors(validationLikertField(rows: ['Staff', 'Value']), $value))
        ->toBe([__('statamic-form-settings::validation.likert_scale.not_on_scale')]);
})->with([
    'first row' => [['Staff' => '9', 'Value' => '2']],
    'last row' => [['Staff' => '4', 'Value' => '9']],
    'a nested list' => [['Staff' => ['4']]],
    'after a row submitted empty' => [['Staff' => '', 'Value' => '9']],
    'after a row submitted null' => [['Staff' => null, 'Value' => '9']],
]);

it('accepts a matrix rating for a numeric row label', function (): void {
    // PHP turns a numeric row label into an integer array key
    expect(answerValidationErrors(validationLikertField(rows: ['2024', '2025']), ['2024' => '4']))->toBe([]);
});

it('rejects a matrix rating for a row the question does not list', function (array $rows, array $value): void {
    /** @var list<string> $rows */
    // Only the unknown-row error is raised, even when that row's value is also off the scale
    expect(answerValidationErrors(validationLikertField(rows: $rows), $value))
        ->toBe([__('statamic-form-settings::validation.likert_scale.unknown_row')]);
})->with([
    'an unlisted label' => [['Staff', 'Value'], ['Staff' => '4', 'Parking' => '2']],
    'an unlisted label with an off-scale value' => [['Staff', 'Value'], ['Parking' => '9']],
    'a key that compares loosely equal to a label' => [['10'], ['1e1' => '4']],
]);

it('rejects a matrix rating that is not a row map', function (): void {
    expect(answerValidationErrors(validationLikertField(rows: ['Staff', 'Value']), '4'))
        ->toBe([__('statamic-form-settings::validation.likert_scale.not_on_scale')]);
});
