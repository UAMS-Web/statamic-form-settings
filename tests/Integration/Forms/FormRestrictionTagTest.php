<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Antlers;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Tags\FormRestriction;

/**
 * Proves the {{ form_restriction }} tag reports a form's render-time restriction state (UAMS-Web/uams-statamic-archive#205),
 * sharing the FormRestrictions source of truth with the submit-time gate.
 *
 * @covers \UAMSWeb\FormSettings\Tags\FormRestriction
 *
 * @command  vendor/bin/pest tests/Integration/Forms/FormRestrictionTagTest.php
 */
$formRestrictionTagDir = '';

beforeEach(function () use (&$formRestrictionTagDir): void {
    $formRestrictionTagDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($formRestrictionTagDir);
    Config::set('statamic.forms.forms', $formRestrictionTagDir);
});

afterEach(function () use (&$formRestrictionTagDir): void {
    if ($formRestrictionTagDir !== '' && File::isDirectory($formRestrictionTagDir)) {
        File::deleteDirectory($formRestrictionTagDir);
    }
});

/**
 * Render the tag for a handle, returning the OPEN/CLOSED marker output.
 *
 * Rendered trusted, as a template is in production: an untrusted parse runs in
 * Antlers' user-content sandbox, which refuses any tag missing from the host's
 * allow-list and renders it as an empty string.
 */
function renderRestrictionTag(string $handle): string
{
    $template = '{{ form_restriction handle="'.$handle.'" }}'
        .'{{ if open }}OPEN{{ else }}CLOSED:{{ state }}:{{ message }}{{ /if }}'
        .'{{ /form_restriction }}';

    return trim((string) Antlers::parse($template, [], true));
}

it('reports the form open when no restriction is active', function (): void {
    $handle = 'restriction_tag_open_'.str_replace('.', '', uniqid('', true));
    $form = makeForm($handle);
    $form->save();

    expect(renderRestrictionTag($handle))->toBe('OPEN');
});

it('reports the closed state and message when a restriction blocks the form', function (): void {
    $handle = 'restriction_tag_closed_'.str_replace('.', '', uniqid('', true));
    $form = makeForm($handle);
    $form->set('require_login_query', true);
    $form->set('require_login_message', 'Members only. Please log in.');
    $form->save();

    // Anonymous render → login_required, with the configured message.
    expect(renderRestrictionTag($handle))->toBe('CLOSED:login_required:Members only. Please log in.');
});

it('reports open for an unknown form handle', function (): void {
    expect(renderRestrictionTag('no_such_form_'.str_replace('.', '', uniqid('', true))))->toBe('OPEN');
});

/**
 * Call the tag class directly so the exact array it hands the template is asserted, not just
 * what one template happens to print from it (UAMS-Web/uams-statamic-archive#3089).
 *
 * @param  array<string, mixed>  $params
 * @return array{open: bool, state: string, message: string}
 */
function formRestrictionTagResult(array $params): array
{
    $tag = new FormRestriction;
    $tag->setProperties(['parser' => null, 'content' => '', 'context' => [], 'params' => $params]);

    return $tag->index();
}

it('returns exactly open, state and message for an open form', function (): void {
    expect(formRestrictionTagResult(['handle' => 'no_such_form_'.str_replace('.', '', uniqid('', true))]))
        ->toBe(['open' => true, 'state' => 'open', 'message' => '']);
});

it('returns open false with the state and message for a restricted form', function (): void {
    $handle = 'restriction_tag_shape_'.str_replace('.', '', uniqid('', true));
    $form = makeForm($handle);
    $form->set('require_login_query', true);
    $form->set('require_login_message', 'Log in first.');
    $form->save();

    expect(formRestrictionTagResult(['handle' => $handle]))
        ->toBe(['open' => false, 'state' => 'login_required', 'message' => 'Log in first.']);
});

it('reads the form handle from the is and in parameters too', function (): void {
    $handle = 'restriction_tag_alias_'.str_replace('.', '', uniqid('', true));
    $form = makeForm($handle);
    $form->set('require_login_query', true);
    $form->set('require_login_message', 'Closed.');
    $form->save();

    expect(formRestrictionTagResult(['is' => $handle])['state'])->toBe('login_required')
        ->and(formRestrictionTagResult(['in' => $handle])['state'])->toBe('login_required');
});

it('does not look a form up when the handle is missing or not a scalar', function (): void {
    $forms = Statamic\Facades\Form::spy();

    expect(formRestrictionTagResult([])['open'])->toBeTrue()
        ->and(formRestrictionTagResult(['handle' => ['contact']])['open'])->toBeTrue();

    $forms->shouldNotHaveReceived('find');
});

it('looks a numeric handle up as a string', function (): void {
    $forms = Statamic\Facades\Form::spy();

    formRestrictionTagResult(['handle' => 42]);

    $forms->shouldHaveReceived('find')->once()->with(Mockery::on(fn (mixed $handle): bool => $handle === '42'));
});

it('reports a form with an unreadable schedule date as closed', function (): void {
    $handle = 'restriction_tag_unreadable_'.str_replace('.', '', uniqid('', true));
    $form = makeForm($handle);
    $form->set('schedule_form_query', true);
    $form->set('schedule_form', ['start_datetime' => 'not a date']);
    $form->save();

    expect(formRestrictionTagResult(['handle' => $handle]))
        ->toBe(['open' => false, 'state' => 'schedule_unreadable', 'message' => 'This form is not currently accepting submissions.']);
});
