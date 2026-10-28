<?php

declare(strict_types=1);

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use UAMSWeb\FormSettings\Tests\TestCase;

/**
 * Proves the email row's `message` is sent as the notification body (UAMS-Web/uams-statamic-archive#3149, decided on UAMS-Web/uams-statamic-archive#3094).
 *
 * A form is submitted over HTTP and the captured Symfony message is read back. The cases are
 * the ticket's: text with a `{{ submission:<handle> }}` tag, an empty message that keeps
 * today's field list, a submitted value carrying HTML and Antlers syntax that arrives
 * escaped, and a send whose subject and recipients are unchanged by the body. The cases for
 * a `{{ all_fields }}` body stay in UAMS-Web/uams-statamic, which owns that tag.
 *
 * @command  vendor/bin/pest tests/Integration/Forms/FormEmailMessageBodyTest.php
 */
/**
 * Handle of the throwaway form created for each test.
 */
const FORM_EMAIL_MESSAGE_BODY_HANDLE = 'message_body_probe';

/**
 * Isolated forms directory so the throwaway form never touches resources/forms.
 *
 * @var string Filesystem path to the per-test forms directory.
 */
$formEmailMessageBodyDir = '';

beforeEach(function () use (&$formEmailMessageBodyDir): void {
    // Lift the form-submission throttle, which a file of more than ten submissions would trip
    $this->withoutMiddleware(ThrottleRequests::class);

    // Isolate the forms directory so the throwaway form is self-contained
    $formEmailMessageBodyDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($formEmailMessageBodyDir);
    Config::set('statamic.forms.forms', $formEmailMessageBodyDir);

    // A text field, a textarea, an option field and a replicator cover the display branches
    app(BlueprintRepository::class)->setFallback('forms.'.FORM_EMAIL_MESSAGE_BODY_HANDLE, fn () => Blueprint::makeFromFields([
        'name' => ['type' => 'text', 'display' => 'Name'],
        'comments' => ['type' => 'textarea', 'display' => 'Comments'],
        'color' => ['type' => 'select', 'display' => 'Favorite Color', 'options' => ['red' => 'Bright Red', 'blue' => 'Deep Blue']],
        'guests' => [
            'type' => 'replicator',
            'display' => 'Guests',
            'sets' => ['main' => ['sets' => ['guest' => [
                'display' => 'Guest',
                'fields' => [['handle' => 'guest_name', 'field' => ['type' => 'text', 'display' => 'Guest Name']]],
            ]]]],
        ],
    ]));
});

afterEach(function () use (&$formEmailMessageBodyDir): void {
    // Remove the isolated forms directory
    if ($formEmailMessageBodyDir !== '' && File::isDirectory($formEmailMessageBodyDir)) {
        File::deleteDirectory($formEmailMessageBodyDir);
    }
});

/**
 * Build a Bard document holding one paragraph of text per argument.
 *
 * @param  string  ...$paragraphs  The text of each paragraph.
 * @return list<array<string, mixed>>
 */
function formEmailMessageBodyBard(string ...$paragraphs): array
{
    return array_map(
        static fn (string $text): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        array_values($paragraphs)
    );
}

/**
 * Store the form with one email row, submit it, and return the one captured message.
 *
 * @param  TestCase  $test  The running test.
 * @param  array<string, mixed>  $row  The email row's config, merged over a minimal row.
 * @param  array<string, mixed>  $payload  The submitted values.
 * @return Email The captured notification message.
 */
function formEmailMessageBodySend(TestCase $test, array $row, array $payload): Email
{
    // Store the form with the one email row under test
    $form = makeForm(FORM_EMAIL_MESSAGE_BODY_HANDLE);
    $form->title('Message Body Probe');
    $form->store(false);
    $form->email([array_merge(['to' => 'recipient@example.test', 'subject' => 'Message probe'], $row)]);
    $form->save();

    // Submit the form over HTTP
    $test->post('/!/forms/'.FORM_EMAIL_MESSAGE_BODY_HANDLE, $payload)->assertRedirect();

    // Read back the single captured message
    $transport = mustBe(ArrayTransport::class, Mail::getSymfonyTransport(), 'transport');
    expect($transport->messages())->toHaveCount(1);
    $sent = mustBe(SentMessage::class, $transport->messages()->first(), 'sent');

    return mustBe(Email::class, $sent->getOriginalMessage(), 'message');
}

it('sends the message text with each submission tag replaced by the submitted value', function (): void {
    $email = formEmailMessageBodySend(
        mustBe(TestCase::class, $this, 'test case'),
        ['message' => formEmailMessageBodyBard('Hello from {{ submission:name }}.', 'Color: {{ submission:color }}')],
        ['name' => 'Ada Lovelace', 'color' => 'blue']
    );

    $html = mustBeString($email->getHtmlBody(), 'html');

    // The text is sent, with the tag replaced by the value and an option shown as its label
    expect($html)->toContain('<p>Hello from Ada Lovelace.</p>');
    expect($html)->toContain('<p>Color: Deep Blue</p>');

    // The field list is not sent alongside it
    expect($html)->not->toContain('<b>Name:</b>');
    expect($html)->not->toContain('{{');

    // The plain-text part carries the same text without markup
    expect($email->getTextBody())->toBe("Hello from Ada Lovelace.\nColor: Deep Blue");
});

it('resolves a bare field handle in the message to its escaped display value', function (): void {
    $email = formEmailMessageBodySend(
        mustBe(TestCase::class, $this, 'test case'),
        ['message' => formEmailMessageBodyBard('Color {{ color }} from {{ name }}')],
        ['name' => 'Ada <i>L</i>', 'color' => 'red']
    );

    expect(mustBeString($email->getHtmlBody(), 'html'))->toContain('<p>Color Bright Red from Ada &lt;i&gt;L&lt;/i&gt;</p>');
});

it('sends the field list unchanged when the message is empty', function (array|string|null $message): void {
    $email = formEmailMessageBodySend(mustBe(TestCase::class, $this, 'test case'), ['message' => $message], ['name' => 'Ada Lovelace', 'color' => 'red']);

    $html = mustBeString($email->getHtmlBody(), 'html');

    // The automagic field list is the body, as before the message was sent
    expect($html)->toContain('<b>Name:</b>');
    expect($html)->toContain('Ada Lovelace');
    expect($html)->not->toContain('<table');
})->with([
    'absent' => [null],
    'empty string' => [''],
    'empty document' => [[]],
    'blank paragraph' => [[['type' => 'paragraph']]],
]);

it('keeps an explicitly configured html template over the message', function (): void {
    $email = formEmailMessageBodySend(
        mustBe(TestCase::class, $this, 'test case'),
        ['html' => 'statamic::forms.automagic-email', 'message' => formEmailMessageBodyBard('Message text')],
        ['name' => 'Ada Lovelace']
    );

    $html = mustBeString($email->getHtmlBody(), 'html');

    expect($html)->toContain('<b>Name:</b>');
    expect($html)->not->toContain('Message text');
});

it('escapes a submitted value carrying HTML or Antlers syntax and never evaluates it', function (): void {
    $email = formEmailMessageBodySend(
        mustBe(TestCase::class, $this, 'test case'),
        ['message' => formEmailMessageBodyBard('Name: {{ submission:name }}')],
        ['name' => '<script>alert(1)</script> {{ form:title }} {{ config:app:key }}']
    );

    $html = mustBeString($email->getHtmlBody(), 'html');

    // The markup arrives as text
    expect($html)->not->toContain('<script>');
    expect(substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;'))->toBe(1);

    // The Antlers syntax arrives as text and is never resolved
    expect(substr_count($html, '&#123;&#123; form:title &#125;&#125;'))->toBe(1);
    expect($html)->not->toContain('Message Body Probe');
    expect($html)->not->toContain(config()->string('app.key'));

    // The text part decodes the entities back to the literal text, still unevaluated
    expect((string) $email->getTextBody())->toContain('Name: <script>alert(1)</script> {{ form:title }}');
});

it('leaves the subject and recipients of the send unchanged', function (): void {
    $email = formEmailMessageBodySend(
        mustBe(TestCase::class, $this, 'test case'),
        [
            'to' => 'recipient@example.test',
            'cc' => 'copy@example.test',
            'from' => 'noreply@example.test',
            'subject' => 'From {{ name }}',
            'message' => formEmailMessageBodyBard('Hello {{ submission:name }}'),
        ],
        ['name' => 'Ada']
    );

    expect($email->getSubject())->toBe('From Ada');
    expect(array_map(static fn (Address $a): string => $a->getAddress(), $email->getTo()))->toBe(['recipient@example.test']);
    expect(array_map(static fn (Address $a): string => $a->getAddress(), $email->getCc()))->toBe(['copy@example.test']);
    expect(array_map(static fn (Address $a): string => $a->getAddress(), $email->getFrom()))->toBe(['noreply@example.test']);
});

it('replaces the submission id tag with the submission id', function (): void {
    $email = formEmailMessageBodySend(
        mustBe(TestCase::class, $this, 'test case'),
        ['message' => formEmailMessageBodyBard('Submission {{ submission:id }}')],
        ['name' => 'Ada']
    );

    expect(mustBeString($email->getHtmlBody(), 'html'))->toMatch('/<p>Submission \d+(\.\d+)?<\/p>/');
});
