<?php

declare(strict_types=1);

use UAMSWeb\FormSettings\CpForms\ConfirmationSettingsFieldBuilder;
use UAMSWeb\FormSettings\Tests\Support\ConfirmationSettingsValidation as Confirmation;

/*
 * Guards the conditional rule on the confirmation query-string field (UAMS-Web/uams-statamic-archive#3101,
 * UAMS-Web/uams-statamic-archive#3161). Laravel's `exclude_unless` takes a comma-separated value list, so
 * `redirect|entry` inside an array-form rule was one literal value that no
 * confirmation type equals. The field is optional by decision (UAMS-Web/uams-statamic-archive#3161): it is
 * kept for the two destination types, dropped for the others, and an empty
 * value passes, so it carries no `required_if`.
 *
 * These run against the nested Control Panel blueprint, in the default group
 * and in an additional confirmation, where the rule names the confirmation type
 * by its full path (UAMS-Web/uams-statamic-archive#3162).
 *
 * @command  vendor/bin/pest tests/Integration/CpForms/ConfirmationQueryStringRulesTest.php
 */

dataset('query string paths', [
    'default group' => [Confirmation::DEFAULT],
    'additional confirmation' => [Confirmation::ADDITIONAL],
]);

/**
 * Collect every conditional rule of the comparison family the builder emits.
 *
 * @return list<string> Rule strings such as `required_if:{this}.group.confirmation_type,entry`.
 */
function confirmationConditionalRules(): array
{
    $rules = [];

    // Read each field's rules, whether they sit on an inline field or a fieldset import's config
    foreach ((new ConfirmationSettingsFieldBuilder)->build('group') as $item) {
        /** @var array<string, mixed> $item */
        /** @var array<string, mixed> $definition */
        $definition = is_array($item['field']) ? $item['field'] : ($item['config'] ?? []);

        /** @var list<mixed> $validate */
        $validate = $definition['validate'] ?? [];

        // Keep only the rules whose parameters name a field and its values
        foreach ($validate as $rule) {
            if (is_string($rule) && preg_match('/^(required_if|exclude_unless|required_unless|exclude_if):/', $rule) === 1) {
                $rules[] = $rule;
            }
        }
    }

    return $rules;
}

it('keeps a supplied query string for each destination type', function (string $path, string $type, string $handle, mixed $value): void {
    $validator = Confirmation::validate($path, [
        'confirmation_type' => $type,
        $handle => $value,
        'confirmation_query_string' => 'foo=bar',
    ]);

    expect($validator->passes())->toBeTrue()
        ->and($validator->validated())->toHaveKey($path.'.confirmation_query_string', 'foo=bar');
})->with('query string paths')->with([
    'redirect' => ['redirect', 'confirmation_redirect_url', 'https://example.com/thanks'],
    'entry' => ['entry', 'confirmation_page', ['page-id']],
]);

it('accepts an empty or absent query string for each destination type', function (string $path, string $type, string $handle, mixed $value): void {
    // Check an empty value passes and is not reported against the field
    $empty = Confirmation::validate($path, [
        'confirmation_type' => $type,
        $handle => $value,
        'confirmation_query_string' => '',
    ]);

    expect($empty->passes())->toBeTrue()
        ->and($empty->errors()->has($path.'.confirmation_query_string'))->toBeFalse();

    // Check an absent value passes as well
    expect(Confirmation::validate($path, ['confirmation_type' => $type, $handle => $value])->passes())->toBeTrue();
})->with('query string paths')->with([
    'redirect' => ['redirect', 'confirmation_redirect_url', 'https://example.com/thanks'],
    'entry' => ['entry', 'confirmation_page', ['page-id']],
]);

it('drops a supplied query string for a type without a destination', function (string $path, string $type, string $handle, mixed $value): void {
    $validator = Confirmation::validate($path, [
        'confirmation_type' => $type,
        $handle => $value,
        'confirmation_query_string' => 'foo=bar',
    ]);

    expect($validator->passes())->toBeTrue()
        ->and($validator->validated())->not->toHaveKey($path.'.confirmation_query_string');
})->with('query string paths')->with([
    'message' => ['message', 'confirmation_message', [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Thanks']]]]],
    'replace' => ['replace', 'confirmation_replace_entry', ['entry-id']],
]);

it('carries no required_if on the query string field', function (string $path): void {
    expect(Confirmation::rules($path, 'confirmation_query_string'))
        ->toBe(['exclude_unless:'.$path.'.confirmation_type,redirect,entry', 'nullable']);
})->with('query string paths');

it('separates every conditional rule value with a comma, never a pipe', function (): void {
    $rules = confirmationConditionalRules();

    // Check the scan reached the rules, so an empty list cannot pass vacuously
    expect($rules)->toHaveCount(13);

    foreach ($rules as $rule) {
        expect(str_contains($rule, '|'))->toBeFalse();
    }
});
