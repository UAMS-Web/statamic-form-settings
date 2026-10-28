<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Statamic\Forms\Submission;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\FormSettings\Http\Middleware\ApplyFormConfirmation;

/**
 * Drives {@see ApplyFormConfirmation} directly with a hand-built request, session and downstream
 * response, so each branch is asserted without a full submit: which requests it acts on, which
 * flashed submission it trusts, how it builds the redirect URL and its query string, and how it
 * reports a redirect to a JSON (XHR) submit. The submit flow itself is pinned end to end by
 * FormConfirmationTest (UAMS-Web/uams-statamic-archive#3089).
 *
 * Mutation (UAMS-Web/uams-statamic-archive#3089, macOS, measured on main 287d8ef41, with FormConfirmationTest and
 * QuizSurveyConfirmationRenderTest): 83 mutants, all killed. The equivalents are annotated
 * `@pest-mutate-ignore` in the class, each with its reason: the flash-list cast and strict flag,
 * the early return with no settings, the type list's strict flag, the form-handle cast, the entry
 * id cast, and the `''` URL fallbacks the internal-URL guard refuses in any non-URL form. Two
 * `=== ''` shortcuts that later code already made redundant were deleted.
 *
 * @covers \UAMSWeb\FormSettings\Http\Middleware\ApplyFormConfirmation
 *
 * @command  vendor/bin/pest tests/Integration/Http/Middleware/ApplyFormConfirmationMiddlewareTest.php
 */
$confirmationMiddlewareDir = '';

beforeEach(function () use (&$confirmationMiddlewareDir): void {
    $confirmationMiddlewareDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($confirmationMiddlewareDir);
    Config::set('statamic.forms.forms', $confirmationMiddlewareDir);
});

afterEach(function () use (&$confirmationMiddlewareDir): void {
    if ($confirmationMiddlewareDir !== '' && File::isDirectory($confirmationMiddlewareDir)) {
        File::deleteDirectory($confirmationMiddlewareDir);
    }
});

/**
 * A submission on a saved throwaway form carrying the given default confirmation settings.
 *
 * @param  array<string, mixed>  $settings
 */
function confirmationMiddlewareSubmission(array $settings): Submission
{
    $form = makeForm('confirmation_mw_'.str_replace('.', '', uniqid('', true)));
    $form->set('confirmation_settings_default', $settings);
    $form->save();

    $submission = $form->makeSubmission();
    $submission->data(['name' => 'Pat']);

    return mustBe(Submission::class, $submission, 'submission');
}

/**
 * Run the middleware for one request against a downstream response.
 */
function runConfirmationMiddleware(Request $request, ?Response $downstream = null): Response
{
    $request->setLaravelSession(app('session.store'));

    return (new ApplyFormConfirmation)->handle($request, fn (): Response => $downstream ?? new Response('downstream'));
}

/**
 * A POST to the form submit route for the submission's form.
 */
function confirmationSubmitRequest(Submission $submission, string $method = 'POST'): Request
{
    return Request::create('/!/forms/'.mustBeString($submission->form()->handle(), 'form handle'), $method);
}

/**
 * The success message the middleware flashed for the submission's form, if any.
 */
function confirmationFlashedMessage(Submission $submission): mixed
{
    return session('form.'.mustBeString($submission->form()->handle(), 'form handle').'.success');
}

it('acts only on a POST to the form submit route', function (string $method, string $path): void {
    $submission = confirmationMiddlewareSubmission(['confirmation_type' => 'message', 'confirmation_message' => 'Thanks.']);
    session()->flash('submission', $submission);

    $response = runConfirmationMiddleware(Request::create($path === '' ? '/!/forms/'.mustBeString($submission->form()->handle(), 'form handle') : $path, $method));

    expect($response->getContent())->toBe('downstream')
        ->and(confirmationFlashedMessage($submission))->toBeNull();
})->with([
    'a GET to the submit route' => ['GET', ''],
    'a POST elsewhere' => ['POST', '/contact'],
]);

it('ignores a submission that this request did not flash', function (): void {
    $submission = confirmationMiddlewareSubmission(['confirmation_type' => 'message', 'confirmation_message' => 'Thanks.']);
    session()->put('submission', $submission);

    runConfirmationMiddleware(confirmationSubmitRequest($submission));

    expect(confirmationFlashedMessage($submission))->toBeNull();
});

it('ignores a flashed submission value that is not a submission', function (): void {
    session()->flash('submission', 'not a submission');

    $response = runConfirmationMiddleware(Request::create('/!/forms/contact', 'POST'));

    expect($response->getContent())->toBe('downstream');
});

it('shows the message, not a redirect, when the redirecting type resolves no URL', function (array $settings): void {
    $submission = confirmationMiddlewareSubmission(mustBeStringKeyed($settings + ['confirmation_message' => 'Thanks.', 'confirmation_query_string' => 'ref=1'], 'settings'));
    session()->flash('submission', $submission);

    $response = runConfirmationMiddleware(confirmationSubmitRequest($submission));

    expect($response->getContent())->toBe('downstream')
        ->and(confirmationFlashedMessage($submission))->toBe('Thanks.');
})->with([
    'a redirect with no URL' => [['confirmation_type' => 'redirect']],
    'an entry that does not exist' => [['confirmation_type' => 'entry', 'confirmation_page' => 'missing-entry-id']],
    'an entry id that is empty' => [['confirmation_type' => 'entry', 'confirmation_page' => '']],
    'an entry id that is not a scalar' => [['confirmation_type' => 'entry', 'confirmation_page' => ['x']]],
    'a message' => [['confirmation_type' => 'message']],
]);

it('trims the rendered message and ignores one that is not text', function (mixed $message, ?string $expected): void {
    $submission = confirmationMiddlewareSubmission(['confirmation_type' => 'message', 'confirmation_message' => $message]);
    session()->flash('submission', $submission);

    runConfirmationMiddleware(confirmationSubmitRequest($submission));

    expect(confirmationFlashedMessage($submission))->toBe($expected);
})->with([
    'padded' => ['  Thanks.  ', 'Thanks.'],
    'a list' => [['Thanks.'], null],
]);

/**
 * A Bard document of one paragraph per given text.
 *
 * @param  list<string>  $paragraphs
 * @return list<array<string, mixed>>
 */
function confirmationBardMessage(array $paragraphs): array
{
    return array_map(
        static fn (string $text): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        $paragraphs
    );
}

it('flashes a Bard confirmation message as HTML', function (): void {
    $submission = confirmationMiddlewareSubmission([
        'confirmation_type' => 'message',
        'confirmation_message' => confirmationBardMessage(['Thanks.', 'We will be in touch.']),
    ]);
    session()->flash('submission', $submission);

    runConfirmationMiddleware(confirmationSubmitRequest($submission));

    expect(confirmationFlashedMessage($submission))->toBe('<p>Thanks.</p><p>We will be in touch.</p>');
});

it('keeps a plain string confirmation message, markup included', function (): void {
    $submission = confirmationMiddlewareSubmission([
        'confirmation_type' => 'message',
        'confirmation_message' => '<strong>Thanks</strong>.',
    ]);
    session()->flash('submission', $submission);

    runConfirmationMiddleware(confirmationSubmitRequest($submission));

    expect(confirmationFlashedMessage($submission))->toBe('<strong>Thanks</strong>.');
});

it('flashes nothing for an empty confirmation message so Statamic keeps its own success text', function (mixed $message): void {
    $submission = confirmationMiddlewareSubmission(['confirmation_type' => 'message', 'confirmation_message' => $message]);
    session()->flash('submission', $submission);

    runConfirmationMiddleware(confirmationSubmitRequest($submission));

    expect(confirmationFlashedMessage($submission))->toBeNull();
})->with([
    'an empty string' => [''],
    'an empty Bard value' => [[]],
    'an empty paragraph' => [[['type' => 'paragraph']]],
]);

it('prints the HTML of a Bard message as written, with no sanitizing', function (): void {
    $submission = confirmationMiddlewareSubmission([
        'confirmation_type' => 'message',
        'confirmation_message' => confirmationBardMessage(['Use <em>care</em> & thanks']),
    ]);
    session()->flash('submission', $submission);

    runConfirmationMiddleware(confirmationSubmitRequest($submission));

    // Bard encodes typed markup as text, so the editor's angle brackets reach the page as entities
    expect(confirmationFlashedMessage($submission))->toBe('<p>Use &lt;em&gt;care&lt;/em&gt; &amp; thanks</p>');
});

it('strips leading separators from the query string and joins it to an existing one', function (string $url, string $query, string $expected): void {
    $submission = confirmationMiddlewareSubmission(['confirmation_type' => 'redirect', 'confirmation_redirect_url' => $url, 'confirmation_query_string' => $query]);
    session()->flash('submission', $submission);

    $response = runConfirmationMiddleware(confirmationSubmitRequest($submission));

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response instanceof RedirectResponse ? $response->getTargetUrl() : null)->toBe(url($expected));
})->with([
    'a leading question mark' => ['/thank-you', '?ref=1', '/thank-you?ref=1'],
    'a leading ampersand onto an existing query' => ['/thank-you?a=1', '&ref=1', '/thank-you?a=1&ref=1'],
]);

it('reports the redirect inside a JSON response instead of replacing it', function (): void {
    $submission = confirmationMiddlewareSubmission(['confirmation_type' => 'redirect', 'confirmation_redirect_url' => '/thank-you']);
    session()->flash('submission', $submission);

    $response = runConfirmationMiddleware(confirmationSubmitRequest($submission), new JsonResponse(['success' => true]));

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response instanceof JsonResponse ? $response->getData(true) : null)->toBe(['success' => true, 'redirect' => '/thank-you']);
});

it('trims the whitespace around a configured redirect URL', function (): void {
    $submission = confirmationMiddlewareSubmission(['confirmation_type' => 'redirect', 'confirmation_redirect_url' => "  /thank-you \n"]);
    session()->flash('submission', $submission);

    $response = runConfirmationMiddleware(confirmationSubmitRequest($submission));

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response instanceof RedirectResponse ? $response->getTargetUrl() : null)->toBe(url('/thank-you'));
});

it('shows the message when the configured redirect URL is not text', function (): void {
    $submission = confirmationMiddlewareSubmission([
        'confirmation_type' => 'redirect',
        'confirmation_redirect_url' => ['not', 'text'],
        'confirmation_message' => 'Thanks',
    ]);
    session()->flash('submission', $submission);

    $response = runConfirmationMiddleware(confirmationSubmitRequest($submission));

    expect($response)->not->toBeInstanceOf(RedirectResponse::class)
        ->and(confirmationFlashedMessage($submission))->toBe('Thanks');
});
