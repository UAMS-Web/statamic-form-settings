<?php

declare(strict_types=1);

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Assert;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Form;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Proves the email config's `from_name` field reaches the rendered `From:` header
 * under the project's synchronous rendering mode (issue UAMS-Web/uams-statamic#213).
 *
 * A form is submitted over HTTP with a `from_name` that references a submitted
 * field; the rendered notification's From header is read straight off the captured
 * Symfony message. Two branches are covered: `from_name` set (display name resolved
 * and attached) and `from_name` empty (bare-email fallback, today's behavior).
 *
 * Mutation: 100% on UAMSWeb\FormSettings\Mail\FormNotificationEmail (16 mutants) and
 * UAMSWeb\FormSettings\Jobs\SendFormNotification (3 mutants), both --covered-only.
 *
 * @command  vendor/bin/pest tests/Integration/Forms/FormEmailFromNameTest.php
 */

/**
 * Handle of the throwaway form created for each test.
 */
const FORM_EMAIL_FROM_NAME_HANDLE = 'from_name_probe';

/**
 * Isolated forms directory so the throwaway form never touches resources/forms.
 *
 * @var string Filesystem path to the per-test forms directory.
 */
$formEmailFromNameDir = '';

beforeEach(function () use (&$formEmailFromNameDir): void {
    // Isolate the forms directory so the throwaway form is self-contained
    $formEmailFromNameDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($formEmailFromNameDir);
    Config::set('statamic.forms.forms', $formEmailFromNameDir);

    // Give the form a blueprint so {{ name }} augments to the submitted value.
    // Resolve the singleton repository directly: the Blueprint facade's @method
    // annotation references an unimported `Closure`, which static analysis
    // misresolves to the facade namespace; the repository's typed signature does
    // not. The facade forwards to this same singleton, so behavior is identical.
    app(BlueprintRepository::class)->setFallback('forms.'.FORM_EMAIL_FROM_NAME_HANDLE, fn () => Blueprint::makeFromFields([
        'name' => ['type' => 'text', 'display' => 'Name'],
    ]));
});

afterEach(function () use (&$formEmailFromNameDir): void {
    // Remove the isolated forms directory
    if ($formEmailFromNameDir !== '' && File::isDirectory($formEmailFromNameDir)) {
        File::deleteDirectory($formEmailFromNameDir);
    }
});

/**
 * Pull the single From address off the one captured notification message.
 *
 * @return Address The sole From-header address.
 */
function formEmailFromNameSentFromHeader(): Address
{
    // Exactly one notification email should have been sent. Tests use the array
    // mail transport, whose messages() returns the captured collection.
    $transport = Mail::getSymfonyTransport();
    Assert::assertInstanceOf(ArrayTransport::class, $transport);
    $messages = $transport->messages();
    Assert::assertCount(1, $messages, 'Exactly one notification email should have been sent.');

    // Return the first (and only) From-header address on the message. The
    // notification renders to an Email, which carries the From header.
    $message = $messages->first();
    Assert::assertInstanceOf(SentMessage::class, $message);
    $email = $message->getOriginalMessage();
    Assert::assertInstanceOf(Email::class, $email);
    $from = $email->getFrom();
    Assert::assertCount(1, $from, 'The notification should carry exactly one From address.');

    return $from[0];
}

it('attaches the resolved from_name to the from header', function (): void {
    // Build a form whose from_name references the submitted name field
    $form = makeForm(FORM_EMAIL_FROM_NAME_HANDLE);
    $form->store(false);
    $form->email([
        'to' => 'recipient@example.test',
        'from' => 'noreply@example.test',
        'from_name' => 'Dr. {{ name }}',
        'subject' => 'From-name probe',
    ]);
    $form->save();

    // Submit the form over HTTP with a known name value
    $response = $this->post(
        '/!/forms/'.FORM_EMAIL_FROM_NAME_HANDLE,
        ['name' => 'Ada Lovelace']
    );
    $response->assertRedirect();

    // Read the From header straight off the captured Symfony message
    $from = formEmailFromNameSentFromHeader();

    // The configured sender address is preserved
    expect($from->getAddress())->toBe('noreply@example.test');

    // The from_name resolved against the submission and became the display name
    expect($from->getName())->toBe('Dr. Ada Lovelace');
});

it('falls back to a bare-email from header when from_name is empty', function (): void {
    // Build a form with an explicit from address but no from_name
    $form = makeForm(FORM_EMAIL_FROM_NAME_HANDLE);
    $form->store(false);
    $form->email([
        'to' => 'recipient@example.test',
        'from' => 'noreply@example.test',
        'from_name' => '',
        'subject' => 'From-name probe',
    ]);
    $form->save();

    // Submit the form over HTTP
    $response = $this->post(
        '/!/forms/'.FORM_EMAIL_FROM_NAME_HANDLE,
        ['name' => 'Ada Lovelace']
    );
    $response->assertRedirect();

    // Read the From header straight off the captured Symfony message
    $from = formEmailFromNameSentFromHeader();

    // The sender address is intact with no display name attached
    expect($from->getAddress())->toBe('noreply@example.test');
    expect($from->getName())->toBe('');
});
