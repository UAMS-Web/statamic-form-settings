<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Stache;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Form;
use Statamic\Stache\Stores\Store;

/**
 * Proves form restrictions are enforced server-side at submit time (UAMS-Web/uams-statamic#205): a POST that
 * violates the entry limit, schedule window, or require-login is rejected (not stored), with
 * the configured closed-state message, even against a direct POST.
 *
 * Each test uses a unique form handle so the entry-limit gate (which reads the live submission
 * count) never sees submissions cached by a sibling test in the shared form-submissions store.
 *
 * @covers \UAMSWeb\FormSettings\Listeners\EnforceFormRestrictions
 * @covers \UAMSWeb\FormSettings\Support\FormRestrictions
 *
 * @command  vendor/bin/pest tests/Integration/Forms/FormRestrictionsEnforcementTest.php
 */
$restrictionsFormsDir = '';
$restrictionsSubsDir = '';
$restrictionsHandle = '';

beforeEach(function () use (&$restrictionsFormsDir, &$restrictionsSubsDir, &$restrictionsHandle): void {
    // Unique IP per test → each test's posts get their own form-submit rate-limit bucket.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.'.random_int(0, 255).'.'.random_int(1, 254)]);

    $restrictionsHandle = 'restrictions_probe_'.str_replace('.', '', uniqid('', true));

    $restrictionsFormsDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($restrictionsFormsDir);
    Config::set('statamic.forms.forms', $restrictionsFormsDir);

    $restrictionsSubsDir = storage_path('framework/testing/subs-'.uniqid('', true));
    File::ensureDirectoryExists($restrictionsSubsDir);
    Config::set('statamic.forms.submissions', $restrictionsSubsDir);
    /** @var Store $submissionsStore */
    $submissionsStore = Stache::store('form-submissions');
    $submissionsStore->directory($restrictionsSubsDir);

    app(BlueprintRepository::class)->setFallback('forms.'.$restrictionsHandle, fn () => Blueprint::makeFromFields([
        'name' => ['type' => 'text', 'display' => 'Name'],
    ]));
});

afterEach(function () use (&$restrictionsFormsDir, &$restrictionsSubsDir): void {
    foreach ([$restrictionsFormsDir, $restrictionsSubsDir] as $dir) {
        if ($dir !== '' && File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }
    }
});

/**
 * Count the submission YAML files actually written for the handle, bypassing the Stache cache.
 */
function restrictionStoredCount(string $handle): int
{
    $base = Config::get('statamic.forms.submissions');
    $dir = (is_string($base) ? $base : '').'/'.$handle;

    return File::isDirectory($dir) ? count(File::files($dir)) : 0;
}

it('allows a submission to a form with no restrictions', function () use (&$restrictionsHandle): void {
    $form = makeForm($restrictionsHandle);
    $form->store(true);
    $form->save();

    $this->post('/!/forms/'.$restrictionsHandle, ['name' => 'Pat'])->assertRedirect();

    expect(restrictionStoredCount($restrictionsHandle))->toBe(1);
});

it('rejects a submission once the entry limit is reached, server-side', function () use (&$restrictionsHandle): void {
    $form = makeForm($restrictionsHandle);
    $form->store(true);
    $form->set('limit_entries', true);
    $form->set('limit_entries_count', 1);
    $form->set('limit_entries_period', 'total');
    $form->set('limit_entries_message', 'This form is full.');
    $form->save();

    // First submission is within the limit and stored.
    $this->post('/!/forms/'.$restrictionsHandle, ['name' => 'First'])->assertRedirect();
    expect(restrictionStoredCount($restrictionsHandle))->toBe(1);

    // Second submission hits the limit: rejected with the message, not stored.
    $this->post('/!/forms/'.$restrictionsHandle, ['name' => 'Second'])
        ->assertSessionHasErrors([$restrictionsHandle => 'This form is full.'], null, 'form.'.$restrictionsHandle);
    expect(restrictionStoredCount($restrictionsHandle))->toBe(1);
});

it('rejects an anonymous submission when login is required', function () use (&$restrictionsHandle): void {
    $form = makeForm($restrictionsHandle);
    $form->store(true);
    $form->set('require_login_query', true);
    $form->set('require_login_message', 'Please log in to submit.');
    $form->save();

    $this->post('/!/forms/'.$restrictionsHandle, ['name' => 'Guest'])
        ->assertSessionHasErrors([$restrictionsHandle => 'Please log in to submit.'], null, 'form.'.$restrictionsHandle);

    expect(restrictionStoredCount($restrictionsHandle))->toBe(0);
});

it('counts only submissions in the current calendar period for a per-day entry limit', function () use (&$restrictionsHandle): void {
    $form = makeForm($restrictionsHandle);
    $form->store(true);
    $form->set('limit_entries', true);
    $form->set('limit_entries_count', 1);
    $form->set('limit_entries_period', 'day');
    $form->set('limit_entries_message', 'One per day.');
    $form->save();

    // A submission from years ago (the submission id is its unix timestamp, so Submission::date()
    // resolves to 2001), outside today's calendar window, so it must NOT count toward a per-day limit.
    $old = $form->makeSubmission();
    $old->id('1000000000');
    $old->data(['name' => 'Ancient']);
    $old->save();

    // A per-day limit of 1 with zero submissions today → this one is allowed (the old one is ignored).
    $this->post('/!/forms/'.$restrictionsHandle, ['name' => 'Today'])->assertRedirect();
    expect(restrictionStoredCount($restrictionsHandle))->toBe(2);
});

it('falls back to the total count for an unknown limit period', function () use (&$restrictionsHandle): void {
    $form = makeForm($restrictionsHandle);
    $form->store(true);
    $form->set('limit_entries', true);
    $form->set('limit_entries_count', 1);
    $form->set('limit_entries_period', 'fortnight'); // unknown token → fail closed to the total count
    $form->set('limit_entries_message', 'Closed.');
    $form->save();

    // One pre-existing submission; with an unknown period the gate counts the total (1) and blocks.
    $existing = $form->makeSubmission();
    $existing->data(['name' => 'First']);
    $existing->save();

    $this->post('/!/forms/'.$restrictionsHandle, ['name' => 'Second'])
        ->assertSessionHasErrors([$restrictionsHandle => 'Closed.'], null, 'form.'.$restrictionsHandle);
});

it('rejects a submission to a form whose schedule has expired', function () use (&$restrictionsHandle): void {
    $form = makeForm($restrictionsHandle);
    $form->store(true);
    $form->set('schedule_form_query', true);
    $form->set('schedule_form', ['start_datetime' => '2000-01-01 00:00', 'end_datetime' => '2000-12-31 23:59']);
    $form->set('schedule_expired_message', 'This form has closed.');
    $form->save();

    $this->post('/!/forms/'.$restrictionsHandle, ['name' => 'TooLate'])
        ->assertSessionHasErrors([$restrictionsHandle => 'This form has closed.'], null, 'form.'.$restrictionsHandle);

    expect(restrictionStoredCount($restrictionsHandle))->toBe(0);
});

it('rejects a submission to a form whose schedule date cannot be read', function () use (&$restrictionsHandle): void {
    $form = makeForm($restrictionsHandle);
    $form->store(true);
    $form->set('schedule_form_query', true);
    $form->set('schedule_form', ['end_datetime' => 'not a date']);
    $form->save();

    $this->post('/!/forms/'.$restrictionsHandle, ['name' => 'Unreadable'])
        ->assertSessionHasErrors([$restrictionsHandle => 'This form is not currently accepting submissions.'], null, 'form.'.$restrictionsHandle);

    expect(restrictionStoredCount($restrictionsHandle))->toBe(0);
});
