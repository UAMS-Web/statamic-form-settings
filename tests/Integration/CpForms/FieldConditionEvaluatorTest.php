<?php

declare(strict_types=1);

use Statamic\Fields\Field;
use UAMSWeb\FormSettings\CpForms\FieldConditionEvaluator;

covers(FieldConditionEvaluator::class);

/**
 * Behavior of {@see FieldConditionEvaluator}: the presence checks the CP form settings
 * filters use to decide which stored values to keep, and the field-visibility check
 * against the posted values (UAMS-Web/uams-statamic-archive#3089).
 *
 * @command  vendor/bin/pest tests/Integration/CpForms/FieldConditionEvaluatorTest.php
 */
it('includes a present value only when it passes the requested check', function (mixed $value, string $check, bool $expected): void {
    expect((new FieldConditionEvaluator)->shouldIncludeField(['x' => $value], 'x', $check))->toBe($expected);
})->with([
    'not empty, filled' => ['a', '!empty', true],
    'not empty, empty string' => ['', '!empty', false],
    'not empty, zero' => [0, '!empty', false],
    'not null, false' => [false, '!==null', true],
    'exists, empty string' => ['', 'exists', true],
    'unknown check keeps it' => ['', 'other', true],
]);

it('excludes a missing or null value whatever the check', function (array $values, string $check): void {
    expect((new FieldConditionEvaluator)->shouldIncludeField(mustBeStringKeyed($values, 'values'), 'x', $check))->toBeFalse();
})->with([
    'missing, not null' => [[], '!==null'],
    'missing, exists' => [[], 'exists'],
    'missing, unknown' => [[], 'other'],
    'null, not null' => [['x' => null], '!==null'],
]);

/**
 * Whether a field with the given condition config is visible for the values.
 *
 * @param  array<string, mixed>  $config
 * @param  array<string, mixed>  $values
 */
function fieldConditionEvaluatorVisible(array $config, array $values): bool
{
    return (new FieldConditionEvaluator)->shouldFieldBeVisible(new Field('probe', array_merge(['type' => 'text'], $config)), $values);
}

it('shows a field only when every if and show_when condition is met', function (string $key): void {
    $config = [$key => ['type' => 'equals redirect', 'delay' => 'true']];

    expect(fieldConditionEvaluatorVisible($config, ['type' => 'redirect', 'delay' => true]))->toBeTrue()
        ->and(fieldConditionEvaluatorVisible($config, ['type' => 'redirect', 'delay' => false]))->toBeFalse()
        ->and(fieldConditionEvaluatorVisible($config, ['type' => 'message', 'delay' => true]))->toBeFalse();
})->with(['if', 'show_when']);

it('hides a field when an unless or hide_when condition is met', function (string $key): void {
    $config = [$key => ['type' => 'equals redirect']];

    expect(fieldConditionEvaluatorVisible($config, ['type' => 'redirect']))->toBeFalse()
        ->and(fieldConditionEvaluatorVisible($config, ['type' => 'message']))->toBeTrue();
})->with(['unless', 'hide_when']);

it('shows a field with no conditions', function (): void {
    expect(fieldConditionEvaluatorVisible([], []))->toBeTrue();
});

it('reads a dotted condition field from nested values, and a broken path as null', function (): void {
    $config = ['if' => ['group.type' => 'empty']];

    expect(fieldConditionEvaluatorVisible($config, ['group' => ['type' => '']]))->toBeTrue()
        ->and(fieldConditionEvaluatorVisible($config, ['group' => ['type' => 'set']]))->toBeFalse()
        ->and(fieldConditionEvaluatorVisible($config, ['group' => []]))->toBeTrue()
        ->and(fieldConditionEvaluatorVisible($config, ['group' => 'scalar']))->toBeTrue()
        ->and(fieldConditionEvaluatorVisible($config, []))->toBeTrue();
});

it('compares each special condition value as its own test', function (string $expected, mixed $actual, bool $met): void {
    expect(fieldConditionEvaluatorVisible(['if' => ['x' => $expected]], ['x' => $actual]))->toBe($met);
})->with([
    'not empty, filled' => ['not empty', 'a', true],
    'not empty, blank' => ['not empty', '', false],
    'empty, blank' => ['empty', '', true],
    'empty, filled' => ['empty', 'a', false],
    'true, true' => ['true', true, true],
    'true, string' => ['true', 'true', false],
    'false, false' => ['false', false, true],
    'false, null' => ['false', null, false],
    'equals prefix stripped' => ['equals gold', 'gold', true],
    'plain value matched exactly' => ['gold', 'gold', true],
    'plain value not loosely' => ['5', 5, false],
]);

it('compares a non-string condition value exactly, without reading it as text', function (): void {
    expect(fieldConditionEvaluatorVisible(['if' => ['x' => 5]], ['x' => 5]))->toBeTrue()
        ->and(fieldConditionEvaluatorVisible(['if' => ['x' => 5]], ['x' => '5']))->toBeFalse();
});
