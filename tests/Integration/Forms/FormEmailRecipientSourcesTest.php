<?php

declare(strict_types=1);

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Form;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * End-to-end proof that a notification's TO/CC/BCC recipients resolve from the configured
 * source (literal email, a submitted field, or conditional routing) when the form is
 * submitted (UAMS-Web/uams-statamic-archive#201).
 *
 * @covers \UAMSWeb\FormSettings\Support\RecipientResolver
 * @covers \UAMSWeb\FormSettings\Jobs\SendFormNotification
 *
 * @command  vendor/bin/pest tests/Integration/Forms/FormEmailRecipientSourcesTest.php
 */
/**
 * Handle of the throwaway form created for this test.
 */
const RECIPIENT_SOURCES_FORM_HANDLE = 'recipient_sources_probe';

/**
 * Isolated forms directory so the throwaway form never touches resources/forms.
 *
 * @var string Filesystem path to the per-test forms directory.
 */
$recipientSourcesDir = '';

beforeEach(function () use (&$recipientSourcesDir): void {
    // Submit from a unique test-net IP so this file's submissions never share the
    // per-IP form-submit rate-limit bucket (whose counter lives in the shared file
    // cache across paratest workers) with other form tests.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.31']);

    $recipientSourcesDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($recipientSourcesDir);
    Config::set('statamic.forms.forms', $recipientSourcesDir);

    app(BlueprintRepository::class)->setFallback('forms.'.RECIPIENT_SOURCES_FORM_HANDLE, fn () => Blueprint::makeFromFields([
        'name' => ['type' => 'text', 'display' => 'Name'],
        'contact_email' => ['type' => 'text', 'display' => 'Contact Email'],
        'manager_email' => ['type' => 'text', 'display' => 'Manager Email'],
        'department' => ['type' => 'text', 'display' => 'Department'],
    ]));
});

afterEach(function () use (&$recipientSourcesDir): void {
    if ($recipientSourcesDir !== '' && File::isDirectory($recipientSourcesDir)) {
        File::deleteDirectory($recipientSourcesDir);
    }
});

/**
 * The first captured message, decoded.
 */
function recipientSourcesFirstMessage(): Email
{
    $transport = mustBe(ArrayTransport::class, Mail::getSymfonyTransport(), 'transport');
    $sent = mustBe(SentMessage::class, $transport->messages()->first(), 'sent');

    return mustBe(Email::class, $sent->getOriginalMessage(), 'message');
}

/**
 * Lowercase address list from a Symfony header array.
 *
 * @param  array<Address>  $addresses
 * @return array<string>
 */
function recipientSourcesAddresses(array $addresses): array
{
    return array_map(static fn (Address $a): string => strtolower($a->getAddress()), $addresses);
}

it('resolves a field-type TO from the submitted field value', function (): void {
    $form = makeForm(RECIPIENT_SOURCES_FORM_HANDLE);
    $form->store(false);
    $form->email([[
        'to_type' => 'field',
        'to_field' => 'contact_email',
        'subject' => 'Field TO',
    ]]);
    $form->save();

    $this->post('/!/forms/'.RECIPIENT_SOURCES_FORM_HANDLE, [
        'name' => 'Pat',
        'contact_email' => 'submitter@example.test',
        'department' => 'x',
    ])->assertRedirect();

    expect(recipientSourcesAddresses(recipientSourcesFirstMessage()->getTo()))->toBe(['submitter@example.test']);
});

it('resolves a routing-type TO to the matching rule address, and a field-type CC', function (): void {
    $form = makeForm(RECIPIENT_SOURCES_FORM_HANDLE);
    $form->store(false);
    $form->email([[
        'to_type' => 'routing',
        'to_routing' => [
            ['field' => 'department', 'operator' => 'equals', 'value' => 'cardiology', 'email' => 'cardio@example.test'],
            ['field' => 'department', 'operator' => 'equals', 'value' => 'oncology', 'email' => 'onco@example.test'],
        ],
        'cc_type' => 'field',
        'cc_field' => 'manager_email',
        'subject' => 'Routing TO',
    ]]);
    $form->save();

    $this->post('/!/forms/'.RECIPIENT_SOURCES_FORM_HANDLE, [
        'name' => 'Pat',
        'department' => 'cardiology',
        'manager_email' => 'boss@example.test',
    ])->assertRedirect();

    $message = recipientSourcesFirstMessage();
    expect(recipientSourcesAddresses($message->getTo()))->toBe(['cardio@example.test']);
    expect(recipientSourcesAddresses($message->getCc()))->toBe(['boss@example.test']);
});

it('skips a notification whose TO resolves to nothing (no recipient-less send)', function (): void {
    $form = makeForm(RECIPIENT_SOURCES_FORM_HANDLE);
    $form->store(false);
    $form->email([[
        'to_type' => 'field',
        'to_field' => 'contact_email',
        'subject' => 'No recipient',
    ]]);
    $form->save();

    // contact_email submitted blank → no valid TO → notification skipped, no exception.
    $this->post('/!/forms/'.RECIPIENT_SOURCES_FORM_HANDLE, [
        'name' => 'Pat',
        'contact_email' => '',
        'department' => 'x',
    ])->assertRedirect();

    $transport = mustBe(ArrayTransport::class, Mail::getSymfonyTransport(), 'transport');
    expect($transport->messages())->toHaveCount(0);
});

it('does not send when a field-type TO is a malicious submitter value (no SSTI / injected recipient)', function (): void {
    // A submitter posts a quoted-Antlers address that passes FILTER_VALIDATE_EMAIL. If it were
    // accepted, the mailable's parseConfig pass would Antlers-render the To header server-side
    // (executing {{ config:app:name }}) and could inject extra recipients. The resolver rejects it,
    // so the notification resolves no TO and nothing is sent.
    $form = makeForm(RECIPIENT_SOURCES_FORM_HANDLE);
    $form->store(false);
    $form->email([[
        'to_type' => 'field',
        'to_field' => 'contact_email',
        'subject' => 'Field TO',
    ]]);
    $form->save();

    $this->post('/!/forms/'.RECIPIENT_SOURCES_FORM_HANDLE, [
        'name' => 'Pat',
        'contact_email' => '"{{ config:app:name }}"@attacker.test',
        'department' => 'x',
    ])->assertRedirect();

    $transport = mustBe(ArrayTransport::class, Mail::getSymfonyTransport(), 'transport');
    expect($transport->messages())->toHaveCount(0);
});
