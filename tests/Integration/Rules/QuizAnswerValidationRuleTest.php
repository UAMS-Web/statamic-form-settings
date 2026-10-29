<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use UAMSWeb\FormSettings\Rules\QuizAnswerValidation;

covers(QuizAnswerValidation::class);

/**
 * Behavior of the quiz answer rule (UAMSWeb\FormSettings\Rules\QuizAnswerValidation): it skips when
 * the question has no fieldtype, rejects a value that is not a list of options,
 * and requires exactly one option marked correct for a radio or select question
 * and at least one for a checkbox or any other question. Only an `is_correct` of
 * boolean true counts as marked.
 *
 * @command  vendor/bin/pest tests/Integration/Rules/QuizAnswerValidationRuleTest.php
 */

/**
 * Run the rule with the given sibling data and return every message it failed with.
 *
 * @param  array<string, mixed>  $data  Data under validation, read for `question_fieldtype`.
 * @return list<mixed>
 */
function quizAnswerMessages(array $data, mixed $value): array
{
    $messages = [];

    (new QuizAnswerValidation)->setData($data)->validate('options', $value, function (mixed $message) use (&$messages): void {
        $messages[] = $message;
    });

    return $messages;
}

/**
 * Build a list of options, one per flag, each carrying that `is_correct` value.
 *
 * @param  list<mixed>  $flags  The `is_correct` value of each option.
 * @return list<array{label: string, is_correct: mixed}>
 */
function quizAnswerOptions(array $flags): array
{
    return array_map(static fn (mixed $flag, int $index): array => ['label' => "Option {$index}", 'is_correct' => $flag], $flags, array_keys($flags));
}

it('skips validation when the question has no fieldtype', function (array $data): void {
    $data = mustBeStringKeyed($data, 'question data');

    expect(quizAnswerMessages($data, 'not a list'))->toBe([])
        ->and(quizAnswerMessages($data, quizAnswerOptions([false])))->toBe([]);
})->with([
    'no fieldtype key' => [[]],
    'null fieldtype' => [['question_fieldtype' => null]],
    'empty fieldtype' => [['question_fieldtype' => '']],
]);

it('rejects a value that is not a list of options', function (mixed $value): void {
    expect(quizAnswerMessages(['question_fieldtype' => 'radio'], $value))->toBe([__('statamic-form-settings::validation.quiz_answer.array')]);
})->with([
    'string' => ['answer'],
    'null' => [null],
    'integer' => [1],
]);

it('requires exactly one correct option for a single-answer question', function (string $fieldtype, array $flags, bool $passes): void {
    $expected = $passes ? [] : [__('statamic-form-settings::validation.quiz_answer.marked_correct.exactly_one')];

    expect(quizAnswerMessages(['question_fieldtype' => $fieldtype], quizAnswerOptions(array_values($flags))))->toBe($expected);
})->with([
    'radio, none correct' => ['radio', [false, false], false],
    'radio, one correct' => ['radio', [false, true], true],
    'radio, two correct' => ['radio', [true, true], false],
    'select, none correct' => ['select', [false], false],
    'select, one correct' => ['select', [true, false], true],
    'select, two correct' => ['select', [true, true, false], false],
]);

it('requires at least one correct option for any other question', function (string $fieldtype, array $flags, bool $passes): void {
    $expected = $passes ? [] : [__('statamic-form-settings::validation.quiz_answer.marked_correct.at_least_one')];

    expect(quizAnswerMessages(['question_fieldtype' => $fieldtype], quizAnswerOptions(array_values($flags))))->toBe($expected);
})->with([
    'checkboxes, none correct' => ['checkboxes', [false, false], false],
    'checkboxes, one correct' => ['checkboxes', [true, false], true],
    'checkboxes, two correct' => ['checkboxes', [true, true], true],
    'unknown fieldtype, none correct' => ['text', [false], false],
    'unknown fieldtype, one correct' => ['text', [true], true],
    'no options at all' => ['checkboxes', [], false],
]);

it('counts only an is_correct of boolean true as marked', function (array $options): void {
    expect(quizAnswerMessages(['question_fieldtype' => 'checkboxes'], $options))->toBe([__('statamic-form-settings::validation.quiz_answer.marked_correct.at_least_one')]);
})->with([
    'string true' => [quizAnswerOptions(['true'])],
    'string one' => [quizAnswerOptions(['1'])],
    'integer one' => [quizAnswerOptions([1])],
    'no is_correct key' => [[['label' => 'Option 0']]],
    'an option that is not an array' => [['Option 0']],
]);

it('counts a correct option beside options that are not arrays or lack the key', function (): void {
    $options = ['Option 0', ['label' => 'Option 1'], ['label' => 'Option 2', 'is_correct' => true]];

    expect(quizAnswerMessages(['question_fieldtype' => 'radio'], $options))->toBe([]);
});

it('matches the single-answer fieldtypes strictly', function (): void {
    // A boolean fieldtype would loosely equal 'radio'; matched strictly it takes
    // the at-least-one branch, so two correct options pass.
    expect(quizAnswerMessages(['question_fieldtype' => true], quizAnswerOptions([true, true])))->toBe([]);
});

it('runs on an empty grid through the validator, since the rule is implicit', function (): void {
    $validator = Validator::make(
        ['question_fieldtype' => 'radio', 'options' => []],
        ['options' => [new QuizAnswerValidation]]
    );

    expect($validator->errors()->get('options'))->toBe([__('statamic-form-settings::validation.quiz_answer.marked_correct.exactly_one')]);
});

it('returns the same instance from setData for chaining', function (): void {
    $rule = new QuizAnswerValidation;

    expect($rule->setData([]))->toBe($rule);
});
