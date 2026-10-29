<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use UAMSWeb\FormSettings\Rules\QuizAnswerValidation;

/**
 * The quiz answer rule is implicit: Laravel runs a non-implicit rule only on a value that is present
 * and not an empty string, so without it a missing answer grid, or one submitted as `''`, would pass
 * because the rule was skipped. A present empty array is validated either way.
 *
 * @command  vendor/bin/pest tests/Integration/Rules/QuizAnswerValidationImplicitTest.php
 */
covers(QuizAnswerValidation::class);

it('runs on a missing answer grid, or one submitted as an empty string', function (array $data, string $message): void {
    $validator = Validator::make($data, ['options' => [new QuizAnswerValidation]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('options'))->toBe(__($message));
})->with([
    'an empty string' => [['question_fieldtype' => 'radio', 'options' => ''], 'statamic-form-settings::validation.quiz_answer.array'],
    'no grid at all' => [['question_fieldtype' => 'radio'], 'statamic-form-settings::validation.quiz_answer.array'],
]);
