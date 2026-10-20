<?php

declare(strict_types=1);

use UAMSWeb\FormSettings\CpForms\EmailGridValuesFilter;

covers(EmailGridValuesFilter::class);

/**
 * Behavior of {@see EmailGridValuesFilter}: which columns of each email grid row are
 * stored when the CP form settings are saved (UAMS-Web/uams-statamic#3089).
 *
 * @command  vendor/bin/pest tests/Integration/CpForms/EmailGridValuesFilterTest.php
 */

/**
 * Filter email grid rows through the container-built filter.
 *
 * @param  array<int, mixed>  $rows
 * @return array<int, mixed>
 */
function emailGridValuesFiltered(array $rows): array
{
    return app(EmailGridValuesFilter::class)->filter($rows);
}

/**
 * A routing rule with every part routing needs.
 *
 * @return list<array<string, string>>
 */
function emailGridValuesRouting(string $email): array
{
    return [['field' => 'topic', 'value' => 'billing', 'email' => $email]];
}

it('keeps every core column in order and drops columns it does not know', function (): void {
    $core = [
        'id' => 'r1', 'name' => 'Staff', 'enabled' => true, 'to_type' => 'none', 'cc_type' => 'none',
        'bcc_type' => 'none', 'from' => 'web@x.test', 'from_name' => 'Web', 'reply_to_type' => 'none',
        'subject' => 'Hi', 'html' => 'h', 'text' => 't', 'message' => 'm', 'markdown' => false,
        'attachments' => true, 'mailer' => 'smtp', 'conditional_logic' => ['if' => []],
    ];

    expect(emailGridValuesFiltered([array_merge(['stray' => 'x'], array_reverse($core))]))->toBe([$core]);
});

it('keeps only the recipient column each type selects', function (): void {
    $every = [
        'to' => 'to@x.test', 'to_field' => 'to_f', 'to_routing' => emailGridValuesRouting('to-r@x.test'),
        'cc' => 'cc@x.test', 'cc_field' => 'cc_f', 'cc_routing' => emailGridValuesRouting('cc-r@x.test'),
        'bcc' => 'bcc@x.test', 'bcc_field' => 'bcc_f', 'bcc_routing' => emailGridValuesRouting('bcc-r@x.test'),
        'reply_to' => 'rt@x.test', 'reply_to_field' => 'rt_f', 'reply_to_routing' => emailGridValuesRouting('rt-r@x.test'),
    ];

    $first = ['to_type' => 'email', 'cc_type' => 'field', 'bcc_type' => 'routing', 'reply_to_type' => 'email'];
    $second = ['to_type' => 'field', 'cc_type' => 'routing', 'bcc_type' => 'email', 'reply_to_type' => 'field'];
    $third = ['to_type' => 'routing', 'cc_type' => 'email', 'bcc_type' => 'field', 'reply_to_type' => 'routing'];

    expect(emailGridValuesFiltered([$first + $every, $second + $every, $third + $every]))->toBe([
        $first + ['to' => 'to@x.test', 'cc_field' => 'cc_f', 'bcc_routing' => $every['bcc_routing'], 'reply_to' => 'rt@x.test'],
        $second + ['to_field' => 'to_f', 'cc_routing' => $every['cc_routing'], 'bcc' => 'bcc@x.test', 'reply_to_field' => 'rt_f'],
        $third + ['to_routing' => $every['to_routing'], 'cc' => 'cc@x.test', 'bcc_field' => 'bcc_f', 'reply_to_routing' => $every['reply_to_routing']],
    ]);
});

it('keeps no recipient column for a type that is not a type name', function (mixed $type): void {
    expect(emailGridValuesFiltered([['to_type' => $type, 'to' => 'a@x.test', 'to_field' => 'f']]))
        ->toBe([['to_type' => $type]]);
})->with([
    'unknown' => ['other'],
    'number' => [1],
    'list' => [['email']],
]);

it('skips a selected recipient column the row does not carry', function (): void {
    expect(emailGridValuesFiltered([['to_type' => 'field']]))->toBe([['to_type' => 'field']]);
});

it('keeps routing only when some rule has a field, a value and an email', function (mixed $routing, bool $kept): void {
    $row = ['to_type' => 'routing', 'to_routing' => $routing];

    expect(emailGridValuesFiltered([$row]))->toBe([$kept ? $row : ['to_type' => 'routing']]);
})->with([
    'complete' => [emailGridValuesRouting('a@x.test'), true],
    'after a broken rule' => [['not a rule', ['field' => 'topic'], ...emailGridValuesRouting('a@x.test')], true],
    'no field' => [[['value' => 'billing', 'email' => 'a@x.test']], false],
    'no value' => [[['field' => 'topic', 'email' => 'a@x.test']], false],
    'no email' => [[['field' => 'topic', 'value' => 'billing']], false],
    'empty value' => [[['field' => 'topic', 'value' => '', 'email' => 'a@x.test']], false],
    'not a list' => ['routing', false],
    'no rules' => [[], false],
]);

it('passes a row that is not an array through at its position and carries on', function (): void {
    expect(emailGridValuesFiltered(['raw', ['subject' => 'Hi', 'stray' => 1]]))
        ->toBe(['raw', ['subject' => 'Hi']]);
});
