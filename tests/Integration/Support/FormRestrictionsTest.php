<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Statamic\Forms\Form;
use Statamic\Forms\Submission;
use UAMSWeb\FormSettings\Support\FormRestrictions;

/**
 * Behavior of {@see FormRestrictions}: require-login, schedule window, and entry-limit
 * evaluation against the current state (UAMS-Web/uams-statamic#205). Limit-reached (which needs stored submissions)
 * is exercised end-to-end in FormRestrictionsEnforcementTest.
 *
 * Mutation (UAMS-Web/uams-statamic#3089, macOS, measured on main 287d8ef41, with FormRestrictionsEnforcementTest,
 * FormRestrictionTagTest and EnforceFormRestrictionsMutationTest): 72 mutants, all killed. Two
 * equivalents are annotated `@pest-mutate-ignore` with their reasons in the class: the
 * `CarbonInterface` check on a submission date, and the zeros in the limit coercion. Two branches
 * that changed nothing were deleted: the `total` early return and the `is_bool` toggle branch.
 *
 * @param  array<string, mixed>  $config
 *
 * @command  vendor/bin/pest tests/Integration/Support/FormRestrictionsTest.php
 */
function makeRestrictedForm(array $config): Form
{
    $form = makeForm('restrictions_unit_probe_'.uniqid('', true));

    foreach ($config as $key => $value) {
        $form->set($key, $value);
    }

    return $form;
}

it('returns null (open) for a form with no restrictions configured', function (): void {
    expect((new FormRestrictions)->restriction(makeRestrictedForm([]), false))->toBeNull();
});

it('blocks an anonymous visitor when login is required, and passes an authenticated one', function (): void {
    $form = makeRestrictedForm([
        'require_login_query' => true,
        'require_login_message' => 'Please log in to continue.',
    ]);

    expect((new FormRestrictions)->restriction($form, false))
        ->toBe(['state' => 'login_required', 'message' => 'Please log in to continue.']);
    expect((new FormRestrictions)->restriction($form, true))->toBeNull();
});

it('blocks before the schedule start (pending) and after the end (expired), open in between', function (string $nowAt, ?string $expectedState): void {
    $form = makeRestrictedForm([
        'schedule_form_query' => true,
        'schedule_form' => ['start_datetime' => '2030-06-01 09:00', 'end_datetime' => '2030-06-30 17:00'],
        'schedule_pending_message' => 'Not open yet.',
        'schedule_expired_message' => 'This form has closed.',
    ]);

    $result = (new FormRestrictions)->restriction($form, true, CarbonImmutable::parse($nowAt));

    expect($result['state'] ?? null)->toBe($expectedState);
})->with([
    'before start → pending' => ['2030-05-15 12:00', 'pending'],
    'within window → open' => ['2030-06-15 12:00', null],
    'after end → expired' => ['2030-07-15 12:00', 'expired'],
    // Boundaries are inclusive (strict lessThan/greaterThan): open at the exact start and end instant.
    'at exact start → open' => ['2030-06-01 09:00:00', null],
    'at exact end → open' => ['2030-06-30 17:00:00', null],
]);

it('surfaces the matching schedule message and falls back when none is configured', function (): void {
    $form = makeRestrictedForm([
        'schedule_form_query' => true,
        'schedule_form' => ['start_datetime' => '2030-06-01 09:00'],
        'schedule_pending_message' => 'Opens June 1.',
    ]);
    /** @var array{state: string, message: string} $result */
    $result = (new FormRestrictions)->restriction($form, true, CarbonImmutable::parse('2030-05-01'));
    expect($result['message'])->toBe('Opens June 1.');

    // No message configured → a sensible default rather than an empty string.
    $bare = makeRestrictedForm(['require_login_query' => true]);
    /** @var array{state: string, message: string} $bareResult */
    $bareResult = (new FormRestrictions)->restriction($bare, false);
    expect($bareResult['message'])->not->toBe('');
});

it('treats an imported string toggle as enabled', function (): void {
    $form = makeRestrictedForm([
        'require_login_query' => 'true',
        'require_login_message' => 'Login required.',
    ]);

    /** @var array{state: string, message: string} $result */
    $result = (new FormRestrictions)->restriction($form, false);
    expect($result['state'])->toBe('login_required');
});

it('checks require-login before schedule before limit (first violation wins)', function (): void {
    // All three would block; require-login is reported first.
    $form = makeRestrictedForm([
        'require_login_query' => true,
        'require_login_message' => 'Login first.',
        'schedule_form_query' => true,
        'schedule_form' => ['start_datetime' => '2030-01-01 00:00'],
        'schedule_pending_message' => 'Pending.',
    ]);

    /** @var array{state: string, message: string} $result */
    $result = (new FormRestrictions)->restriction($form, false, CarbonImmutable::parse('2020-01-01'));
    expect($result['state'])->toBe('login_required');
});

it('treats a blank schedule datetime as no limit (open)', function (): void {
    $form = makeRestrictedForm([
        'schedule_form_query' => true,
        'schedule_form' => ['start_datetime' => '', 'end_datetime' => null],
        'schedule_pending_message' => 'Pending.',
    ]);

    expect((new FormRestrictions)->restriction($form, true, CarbonImmutable::parse('2025-01-01')))->toBeNull();
});

it('closes the form when the start datetime cannot be read', function (): void {
    $form = makeRestrictedForm([
        'schedule_form_query' => true,
        'schedule_form' => ['start_datetime' => 'not a date', 'end_datetime' => '2099-01-01 00:00'],
    ]);

    expect((new FormRestrictions)->restriction($form, true, CarbonImmutable::parse('2025-01-01')))
        ->toBe(['state' => 'schedule_unreadable', 'message' => 'This form is not currently accepting submissions.']);
});

it('closes the form when the end datetime cannot be read', function (): void {
    $form = makeRestrictedForm([
        'schedule_form_query' => true,
        'schedule_form' => ['start_datetime' => '2000-01-01 00:00', 'end_datetime' => 'next tuesday-ish?'],
    ]);

    expect((new FormRestrictions)->restriction($form, true, CarbonImmutable::parse('2025-01-01')))
        ->toBe(['state' => 'schedule_unreadable', 'message' => 'This form is not currently accepting submissions.']);
});

it('ignores an unreadable datetime while the schedule is switched off', function (): void {
    $form = makeRestrictedForm([
        'schedule_form_query' => false,
        'schedule_form' => ['start_datetime' => 'not a date'],
    ]);

    expect((new FormRestrictions)->restriction($form, true, CarbonImmutable::parse('2025-01-01')))->toBeNull();
});

it('reports a readable schedule exactly as before', function (): void {
    $form = makeRestrictedForm([
        'schedule_form_query' => true,
        'schedule_form' => ['start_datetime' => '2030-06-01 09:00', 'end_datetime' => '2030-06-30 17:00'],
        'schedule_pending_message' => 'Pending.',
        'schedule_expired_message' => 'Expired.',
    ]);
    $rules = new FormRestrictions;

    expect($rules->restriction($form, true, CarbonImmutable::parse('2030-01-01')))->toBe(['state' => 'pending', 'message' => 'Pending.'])
        ->and($rules->restriction($form, true, CarbonImmutable::parse('2030-06-15')))->toBeNull()
        ->and($rules->restriction($form, true, CarbonImmutable::parse('2031-01-01')))->toBe(['state' => 'expired', 'message' => 'Expired.']);
});

it('is open when the entry limit is configured but not yet reached (no stored submissions)', function (): void {
    $form = makeRestrictedForm([
        'limit_entries' => true,
        'limit_entries_count' => 5,
        'limit_entries_period' => 'total',
        'limit_entries_message' => 'Full.',
    ]);

    expect((new FormRestrictions)->restriction($form, true))->toBeNull();
});

/**
 * A form whose stored submissions are the given items, so the entry-limit count can be driven
 * directly instead of through the submissions store (UAMS-Web/uams-statamic#3089).
 *
 * @param  array<string, mixed>  $config
 * @param  list<mixed>  $submissions
 */
function makeFormWithSubmissions(array $config, array $submissions): Form
{
    $form = new class extends Form
    {
        /** @var list<mixed> */
        public array $fakeSubmissions = [];

        /**
         * @return Collection<int, mixed>
         */
        public function submissions()
        {
            return collect($this->fakeSubmissions);
        }
    };

    $form->handle('restrictions_fake_'.str_replace('.', '', uniqid('', true)));
    $form->fakeSubmissions = $submissions;

    foreach ($config as $key => $value) {
        $form->set($key, $value);
    }

    return $form;
}

/**
 * A submission dated at the given unix timestamp (Statamic dates a submission from its id).
 */
function submissionAt(int $timestamp): Submission
{
    $submission = new Submission;
    $submission->id((string) $timestamp);

    return $submission;
}

it('blocks once the stored submissions reach the limit exactly', function (): void {
    $form = makeFormWithSubmissions(
        ['limit_entries' => true, 'limit_entries_count' => 2, 'limit_entries_message' => 'Full.'],
        [submissionAt(1000000000), submissionAt(1000000001)]
    );

    expect((new FormRestrictions)->restriction($form, true))->toBe(['state' => 'limit_reached', 'message' => 'Full.']);
});

it('treats a limit of zero, a negative limit or a non-numeric limit as no limit', function (mixed $count): void {
    $form = makeFormWithSubmissions(
        ['limit_entries' => true, 'limit_entries_count' => $count, 'limit_entries_message' => 'Full.'],
        [submissionAt(1000000000)]
    );

    expect((new FormRestrictions)->restriction($form, true))->toBeNull();
})->with([
    'zero' => [0],
    'the string zero' => ['0'],
    'negative' => [-5],
    'not a number' => ['abc'],
]);

it('reads a numeric string limit as its integer', function (): void {
    $form = makeFormWithSubmissions(
        ['limit_entries' => true, 'limit_entries_count' => '1', 'limit_entries_message' => 'Full.'],
        [submissionAt(1000000000)]
    );

    expect((new FormRestrictions)->restriction($form, true)['state'] ?? null)->toBe('limit_reached');
});

it('counts a submission made in the current day and ignores anything that is not a submission', function (): void {
    $now = CarbonImmutable::parse('2030-06-15 12:00:00');
    $config = ['limit_entries' => true, 'limit_entries_count' => 1, 'limit_entries_period' => 'day', 'limit_entries_message' => 'Daily.'];

    $today = makeFormWithSubmissions($config, [submissionAt($now->subHour()->getTimestamp())]);
    $notSubmissions = makeFormWithSubmissions($config, [new stdClass, ['id' => '1']]);

    expect((new FormRestrictions)->restriction($today, true, $now)['state'] ?? null)->toBe('limit_reached')
        ->and((new FormRestrictions)->restriction($notSubmissions, true, $now))->toBeNull();
});

it('reads an imported string toggle as words, case and spacing aside', function (string $value, bool $enabled): void {
    $form = makeRestrictedForm(['require_login_query' => $value, 'require_login_message' => 'Login.']);

    expect((new FormRestrictions)->restriction($form, false) !== null)->toBe($enabled);
})->with([
    'true' => ['true', true],
    'one' => ['1', true],
    'yes' => ['yes', true],
    'on' => ['on', true],
    'padded and capitalized' => ['  Yes ', true],
    'the word false' => ['false', false],
    'a number that only equals one loosely' => ['01', false],
]);

it('trims the configured message and falls back when it is blank or not text', function (mixed $message, string $expected): void {
    $form = makeRestrictedForm(['require_login_query' => true, 'require_login_message' => $message]);

    expect((new FormRestrictions)->restriction($form, false)['message'] ?? null)->toBe($expected);
})->with([
    'padded' => ['  Log in.  ', 'Log in.'],
    'a number' => [5, '5'],
    'blank' => ['   ', 'This form is not currently accepting submissions.'],
    'a list' => [['Log in.'], 'This form is not currently accepting submissions.'],
]);

it('ignores a schedule end that is blank, whitespace or not text', function (mixed $end): void {
    $form = makeRestrictedForm([
        'schedule_form_query' => true,
        'schedule_form' => ['end_datetime' => $end],
        'schedule_expired_message' => 'Closed.',
    ]);

    expect((new FormRestrictions)->restriction($form, true, CarbonImmutable::parse('2099-01-01')))->toBeNull();
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
    'a number' => [5],
]);
