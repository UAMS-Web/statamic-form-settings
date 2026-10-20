<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Statamic\Contracts\Forms\Submission;
use Statamic\Events\FormSubmitted;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Listeners\EnforceFormRestrictions;

covers(EnforceFormRestrictions::class);

uses(MockeryPHPUnitIntegration::class);

/**
 * Narrow mutate coverage for UAMSWeb\FormSettings\Listeners\EnforceFormRestrictions.
 *
 * Collaborators are mocked so mutant children never touch the form-submissions store.
 *
 * @command  vendor/bin/pest tests/Integration/Listeners/EnforceFormRestrictionsMutationTest.php
 */
it('returns early when the submission has no Form instance', function (): void {
    $submission = Mockery::mock(Submission::class);
    $submission->shouldReceive('form')->once()->andReturnNull();

    expect(throwableFrom(fn () => (new EnforceFormRestrictions)->handle(new FormSubmitted($submission))))->toBeNull();
});

it('returns early when no restriction blocks the form', function (): void {
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('get')->andReturnNull();

    $submission = Mockery::mock(Submission::class);
    $submission->shouldReceive('form')->once()->andReturn($form);

    Auth::shouldReceive('check')->once()->andReturnFalse();

    expect(throwableFrom(fn () => (new EnforceFormRestrictions)->handle(new FormSubmitted($submission))))->toBeNull();
});

it('throws a ValidationException keyed by the form handle when blocked', function (): void {
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('get')
        ->with('require_login_query')
        ->andReturnTrue();
    $form->shouldReceive('get')
        ->with('require_login_message')
        ->andReturn('Please log in to submit.');
    $form->shouldReceive('get')->andReturnNull();
    $form->shouldReceive('handle')->once()->andReturn('contact');

    $submission = Mockery::mock(Submission::class);
    $submission->shouldReceive('form')->once()->andReturn($form);

    Auth::shouldReceive('check')->once()->andReturnFalse();

    $exception = mustBe(
        ValidationException::class,
        throwableFrom(fn () => (new EnforceFormRestrictions)->handle(new FormSubmitted($submission))),
        'the blocked-submission exception',
    );

    expect($exception->errors())->toBe([
        'contact' => ['Please log in to submit.'],
    ]);
});

it('falls back to the form key when the handle is not scalar', function (): void {
    // RemoveStringCast / is_scalar ternary: non-scalar handle must become 'form'.
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('get')
        ->with('require_login_query')
        ->andReturnTrue();
    $form->shouldReceive('get')
        ->with('require_login_message')
        ->andReturn('Closed.');
    $form->shouldReceive('get')->andReturnNull();
    $form->shouldReceive('handle')->once()->andReturn(['not', 'scalar']);

    $submission = Mockery::mock(Submission::class);
    $submission->shouldReceive('form')->once()->andReturn($form);

    Auth::shouldReceive('check')->once()->andReturnFalse();

    $exception = mustBe(
        ValidationException::class,
        throwableFrom(fn () => (new EnforceFormRestrictions)->handle(new FormSubmitted($submission))),
        'the blocked-submission exception',
    );

    expect($exception->errors())->toBe([
        'form' => ['Closed.'],
    ]);
});

it('casts a scalar non-string handle before keying the exception', function (): void {
    // RemoveStringCast: a float array key truncates to int; (string) keeps "7.5".
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('get')
        ->with('require_login_query')
        ->andReturnTrue();
    $form->shouldReceive('get')
        ->with('require_login_message')
        ->andReturn('Closed.');
    $form->shouldReceive('get')->andReturnNull();
    $form->shouldReceive('handle')->once()->andReturn(7.5);

    $submission = Mockery::mock(Submission::class);
    $submission->shouldReceive('form')->once()->andReturn($form);

    Auth::shouldReceive('check')->once()->andReturnFalse();

    $exception = mustBe(
        ValidationException::class,
        throwableFrom(fn () => (new EnforceFormRestrictions)->handle(new FormSubmitted($submission))),
        'the blocked-submission exception',
    );

    expect($exception->errors())->toBe([
        '7.5' => ['Closed.'],
    ]);
});
