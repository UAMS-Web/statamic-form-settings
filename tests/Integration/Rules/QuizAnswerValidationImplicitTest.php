<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use UAMSWeb\FormSettings\Rules\QuizAnswerValidation;

/**
 * The quiz answer rule is implicit: Laravel runs it on an empty or missing answer grid, so a quiz
 * question saved with no answers still fails the "an answer is marked correct" check rather than
 * passing because the rule was skipped.
 *
 * @command  vendor/bin/pest tests/Integration/Rules/QuizAnswerValidationImplicitTest.php
 */
covers(QuizAnswerValidation::class);

it('runs on an empty or missing answer grid', function (array $data, string $message): void {
    $validator = Validator::make($data, ['options' => [new QuizAnswerValidation]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('options'))->toBe(__($message));
})->with([
    'an empty grid' => [['question_fieldtype' => 'radio', 'options' => []], 'statamic-form-settings::validation.quiz_answer.marked_correct.exactly_one'],
    'no grid at all' => [['question_fieldtype' => 'radio'], 'statamic-form-settings::validation.quiz_answer.array'],
]);
