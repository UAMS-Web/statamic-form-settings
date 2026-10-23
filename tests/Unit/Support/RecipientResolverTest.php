<?php

declare(strict_types=1);

use UAMSWeb\FormSettings\Support\RecipientResolver;

/**
 * Behavior of {@see RecipientResolver}, which resolves a form notification's
 * TO/CC/BCC/Reply-To from its configured source (literal `email`, a submitted `field`,
 * or conditional `routing`), filtering submission-derived addresses to valid emails (UAMS-Web/uams-statamic#201).
 *
 * Mutation (UAMS-Web/uams-statamic#3089, macOS, measured on main 287d8ef41, with FormEmailRecipientSourcesTest):
 * 57 mutants, all killed. Two equivalents are annotated `@pest-mutate-ignore` with their reasons
 * in the class: the `(string)` on the `data_get()` key, and the `array_values()` renumbering of
 * a list only ever imploded. An `=== ''` check that `FILTER_VALIDATE_EMAIL` already covered was
 * deleted as dead code.
 *
 * @command  vendor/bin/pest tests/Unit/Support/RecipientResolverTest.php
 */
/**
 * @param  array<array-key, mixed>  $config
 * @param  array<array-key, mixed>  $values
 */
function resolveRecipient(array $config, array $values, string $kind = 'to'): string
{
    /** @var array<string, mixed> $typedConfig */
    $typedConfig = $config;
    /** @var array<string, mixed> $typedValues */
    $typedValues = $values;

    return (new RecipientResolver)->resolve($typedConfig, $typedValues, $kind);
}

it('returns the literal address untouched for the email type (default)', function (mixed $config, string $expected): void {
    /** @var array<string, mixed> $config */
    expect(resolveRecipient($config, []))->toBe($expected);
})->with([
    'explicit email type' => [['to_type' => 'email', 'to' => 'admin@example.test'], 'admin@example.test'],
    'no type defaults to email' => [['to' => 'admin@example.test'], 'admin@example.test'],
    'merge-tag literal passes through unrendered' => [['to' => '{{ admin_email }}'], '{{ admin_email }}'],
    'missing literal is empty' => [['to_type' => 'email'], ''],
]);

it('resolves a field-type recipient from the submitted value of the configured field', function (): void {
    $config = ['to_type' => 'field', 'to_field' => 'contact_email'];

    expect(resolveRecipient($config, ['contact_email' => 'user@example.test']))->toBe('user@example.test');
});

it('joins multiple valid addresses from an array field value and drops invalid ones', function (): void {
    $config = ['to_type' => 'field', 'to_field' => 'emails'];
    $values = ['emails' => ['a@example.test', 'not-an-email', 'b@example.test']];

    expect(resolveRecipient($config, $values))->toBe('a@example.test, b@example.test');
});

it('splits a comma-delimited field value into valid addresses', function (): void {
    $config = ['to_type' => 'field', 'to_field' => 'emails'];
    $values = ['emails' => 'a@example.test, junk, b@example.test'];

    expect(resolveRecipient($config, $values))->toBe('a@example.test, b@example.test');
});

it('returns empty for a field type with no handle, missing value, or invalid value', function (mixed $config, array $values): void {
    /** @var array<string, mixed> $config */
    expect(resolveRecipient($config, $values))->toBe('');
})->with([
    'no field handle' => [['to_type' => 'field'], ['contact_email' => 'user@example.test']],
    'field not submitted' => [['to_type' => 'field', 'to_field' => 'contact_email'], []],
    'submitted value not an email' => [['to_type' => 'field', 'to_field' => 'contact_email'], ['contact_email' => 'nope']],
]);

it('resolves a routing-type recipient to every matching rule address', function (array $values, string $expected): void {
    $config = [
        'to_type' => 'routing',
        'to_routing' => [
            ['field' => 'department', 'operator' => 'equals', 'value' => 'cardiology', 'email' => 'cardio@example.test'],
            ['field' => 'department', 'operator' => 'equals', 'value' => 'oncology', 'email' => 'onco@example.test'],
            ['field' => 'vip', 'operator' => 'equals', 'value' => 'true', 'email' => 'vip@example.test'],
        ],
    ];

    expect(resolveRecipient($config, $values))->toBe($expected);
})->with([
    'one rule matches' => [['department' => 'cardiology', 'vip' => false], 'cardio@example.test'],
    'a different rule matches' => [['department' => 'oncology', 'vip' => false], 'onco@example.test'],
    'two rules match → both addresses' => [['department' => 'cardiology', 'vip' => true], 'cardio@example.test, vip@example.test'],
    'no rule matches → empty' => [['department' => 'neurology', 'vip' => false], ''],
]);

it('honors routing operators beyond equals (not / contains)', function (array $rule, array $values, string $expected): void {
    $config = ['to_type' => 'routing', 'to_routing' => [$rule + ['email' => 'hit@example.test']]];

    expect(resolveRecipient($config, $values))->toBe($expected);
})->with([
    'not operator matches when different' => [
        ['field' => 'status', 'operator' => 'not', 'value' => 'spam'],
        ['status' => 'ham'],
        'hit@example.test',
    ],
    'contains operator matches substring' => [
        ['field' => 'tags', 'operator' => 'contains', 'value' => 'vip'],
        ['tags' => 'a-vip-b'],
        'hit@example.test',
    ],
    'not operator fails when equal' => [
        ['field' => 'status', 'operator' => 'not', 'value' => 'spam'],
        ['status' => 'spam'],
        '',
    ],
]);

it('drops a matching routing rule whose configured email is invalid or missing', function (mixed $email, string $expected): void {
    $config = [
        'to_type' => 'routing',
        'to_routing' => [
            ['field' => 'department', 'operator' => 'equals', 'value' => 'cardiology', 'email' => $email],
        ],
    ];

    expect(resolveRecipient($config, ['department' => 'cardiology']))->toBe($expected);
})->with([
    'invalid email' => ['not-an-email', ''],
    'null email' => [null, ''],
    'valid email' => ['ok@example.test', 'ok@example.test'],
]);

it('rejects a field-resolved address carrying template delimiters or control chars (anti-SSTI)', function (mixed $fieldValue): void {
    // FILTER_VALIDATE_EMAIL accepts a quoted local part like "{{secret}}"@x.test, so the resolver
    // must additionally reject braces/control chars, otherwise the mailable's Antlers parseConfig
    // pass would execute the injected template or re-split injected commas into extra recipients.
    $config = ['to_type' => 'field', 'to_field' => 'contact_email'];

    expect(resolveRecipient($config, ['contact_email' => $fieldValue]))->toBe('');
})->with([
    'quoted Antlers local part' => ['"{{secret}}"@attacker.test'],
    'unquoted Antlers' => ['{{form_handle}}@x.test'],
    'expansion-injection payload' => ['{{evil}}@a.test'],
    'newline header injection' => ["a@b.test\nbcc:evil@x.test"],
]);

it('still resolves a clean field address alongside the anti-SSTI rejection', function (): void {
    // The rejection must not over-block: a normal address with no braces/control chars resolves.
    $config = ['to_type' => 'field', 'to_field' => 'contact_email'];

    expect(resolveRecipient($config, ['contact_email' => 'first.last+tag@example.co.uk']))->toBe('first.last+tag@example.co.uk');
});

it('routes via the imported Gravity Forms routing operators', function (array $rule, array $values, string $expected): void {
    $config = ['to_type' => 'routing', 'to_routing' => [$rule + ['email' => 'hit@example.test']]];

    expect(resolveRecipient($config, $values))->toBe($expected);
})->with([
    'greater_than above routes' => [['field' => 'age', 'operator' => 'greater_than', 'value' => '18'], ['age' => '25'], 'hit@example.test'],
    'greater_than at boundary does not' => [['field' => 'age', 'operator' => 'greater_than', 'value' => '18'], ['age' => '18'], ''],
    'less_than below routes' => [['field' => 'age', 'operator' => 'less_than', 'value' => '65'], ['age' => '40'], 'hit@example.test'],
    'starts_with present routes' => [['field' => 'name', 'operator' => 'starts_with', 'value' => 'Dr'], ['name' => 'Dr. Smith'], 'hit@example.test'],
    'ends_with present routes' => [['field' => 'dept', 'operator' => 'ends_with', 'value' => 'ology'], ['dept' => 'cardiology'], 'hit@example.test'],
]);

it('resolves each kind (to / cc / bcc / reply_to) independently from its own keys', function (): void {
    $config = [
        'to_type' => 'email', 'to' => 'to@example.test',
        'cc_type' => 'field', 'cc_field' => 'manager_email',
        'bcc_type' => 'routing', 'bcc_routing' => [
            ['field' => 'urgent', 'operator' => 'equals', 'value' => 'true', 'email' => 'oncall@example.test'],
        ],
    ];
    $values = ['manager_email' => 'boss@example.test', 'urgent' => true];

    expect(resolveRecipient($config, $values, 'to'))->toBe('to@example.test');
    expect(resolveRecipient($config, $values, 'cc'))->toBe('boss@example.test');
    expect(resolveRecipient($config, $values, 'bcc'))->toBe('oncall@example.test');
    // reply_to has no config of its own → empty.
    expect(resolveRecipient($config, $values, 'reply_to'))->toBe('');
});

it('renders a numeric literal address as text and a non-scalar one as nothing', function (): void {
    expect(resolveRecipient(['to' => 5], []))->toBe('5')
        ->and(resolveRecipient(['to' => ['a@x.test']], []))->toBe('');
});

it('resolves nothing for a field handle that is empty or false, even when a value sits under an empty key', function (mixed $handle): void {
    expect(resolveRecipient(['to_type' => 'field', 'to_field' => $handle], ['' => 'a@x.test']))->toBe('');
})->with([
    'an empty string' => [''],
    'false' => [false],
]);

it('skips a non-scalar submitted entry and keeps the addresses after it', function (): void {
    expect(resolveRecipient(['to_type' => 'field', 'to_field' => 'who'], ['who' => [['nested'], 'a@x.test']]))
        ->toBe('a@x.test');
});

it('ignores a submitted number rather than failing on it', function (): void {
    expect(resolveRecipient(['to_type' => 'field', 'to_field' => 'who'], ['who' => 5]))->toBe('');
});

it('drops an address carrying template delimiters and keeps the addresses after it', function (): void {
    expect(resolveRecipient(['to_type' => 'field', 'to_field' => 'who'], ['who' => '"{{x}}"@x.test, b@x.test']))
        ->toBe('b@x.test');
});

it('lists a repeated address once', function (): void {
    expect(resolveRecipient(['to_type' => 'field', 'to_field' => 'who'], ['who' => 'a@x.test, b@x.test, a@x.test']))
        ->toBe('a@x.test, b@x.test');
});

it('resolves nothing when the routing rules are not a list, without a PHP warning', function (): void {
    $warnings = [];
    set_error_handler(function (int $errno, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        $resolved = resolveRecipient(['to_type' => 'routing', 'to_routing' => 'a@x.test'], []);
    } finally {
        restore_error_handler();
    }

    expect($resolved)->toBe('')
        ->and($warnings)->toBe([]);
});

it('skips a routing rule that is not an array and keeps the rules after it', function (): void {
    $config = ['to_type' => 'routing', 'to_routing' => [
        'not a rule',
        ['field' => 'dept', 'operator' => 'equals', 'value' => 'sales', 'email' => 'sales@x.test'],
    ]];

    expect(resolveRecipient($config, ['dept' => 'sales']))->toBe('sales@x.test');
});
