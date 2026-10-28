<?php

declare(strict_types=1);

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Form;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

/**
 * Proves the per-notification "Active" toggle (`enabled`) is honored at send time (UAMS-Web/uams-statamic-archive#204):
 * an inactive notification is skipped (with a debug log line) before its conditional logic
 * is evaluated, while active/default notifications still fire.
 *
 * @covers \UAMSWeb\FormSettings\Jobs\SendFormNotification
 *
 * @command  vendor/bin/pest tests/Integration/Forms/FormEmailActiveToggleTest.php
 */
/**
 * Handle of the throwaway form created for this test.
 */
const ACTIVE_TOGGLE_FORM_HANDLE = 'active_toggle_probe';

/**
 * Isolated forms directory so the throwaway form never touches resources/forms.
 *
 * @var string Filesystem path to the per-test forms directory.
 */
$activeToggleDir = '';

beforeEach(function () use (&$activeToggleDir): void {
    // Submit from a unique test-net IP so this file's submissions never share the
    // per-IP form-submit rate-limit bucket (whose counter lives in the shared file
    // cache across paratest workers) with other form tests.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21']);

    $activeToggleDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($activeToggleDir);
    Config::set('statamic.forms.forms', $activeToggleDir);

    app(BlueprintRepository::class)->setFallback('forms.'.ACTIVE_TOGGLE_FORM_HANDLE, fn () => Blueprint::makeFromFields([
        'name' => ['type' => 'text', 'display' => 'Name'],
        'department' => ['type' => 'text', 'display' => 'Department'],
    ]));
});

afterEach(function () use (&$activeToggleDir): void {
    if ($activeToggleDir !== '' && File::isDirectory($activeToggleDir)) {
        File::deleteDirectory($activeToggleDir);
    }
});

/**
 * Lowercase recipient addresses across every captured notification email.
 *
 * @return list<string>
 */
function activeToggleSentRecipients(): array
{
    $transport = mustBe(ArrayTransport::class, Mail::getSymfonyTransport(), 'transport');

    /** @var list<string> $recipients */
    $recipients = $transport->messages()
        ->map(function ($sent): string {
            $sent = mustBe(SentMessage::class, $sent, 'sent');
            $message = mustBe(Email::class, $sent->getOriginalMessage(), 'message');

            return strtolower($message->getTo()[0]->getAddress());
        })
        ->all();

    return $recipients;
}

it('sends active notifications and skips inactive ones', function (): void {
    $form = makeForm(ACTIVE_TOGGLE_FORM_HANDLE);
    $form->store(false);
    $form->email([
        ['name' => 'Active one', 'to' => 'active@example.test', 'subject' => 'A', 'enabled' => true],
        ['name' => 'Inactive one', 'to' => 'inactive@example.test', 'subject' => 'B', 'enabled' => false],
        ['name' => 'Default one', 'to' => 'default@example.test', 'subject' => 'C'], // enabled absent → active
    ]);
    $form->save();

    $this->post('/!/forms/'.ACTIVE_TOGGLE_FORM_HANDLE, ['name' => 'Pat', 'department' => 'x'])->assertRedirect();

    $recipients = activeToggleSentRecipients();
    expect($recipients)->toContain('active@example.test');
    expect($recipients)->toContain('default@example.test');
    expect($recipients)->not->toContain('inactive@example.test');
    expect($recipients)->toHaveCount(2);
});

it('logs a debug note naming the form and notification when skipping an inactive one', function (): void {
    $log = Log::spy();

    $form = makeForm(ACTIVE_TOGGLE_FORM_HANDLE);
    $form->store(false);
    $form->email(['name' => 'Disabled notice', 'to' => 'x@example.test', 'subject' => 'X', 'enabled' => false]);
    $form->save();

    $this->post('/!/forms/'.ACTIVE_TOGGLE_FORM_HANDLE, ['name' => 'Pat', 'department' => 'x'])->assertRedirect();

    expect(activeToggleSentRecipients())->toHaveCount(0);
    $log->shouldHaveReceived('debug')->once()->withArgs(fn ($message): bool => \is_string($message)
        && str_contains($message, 'Disabled notice')
        && str_contains($message, ACTIVE_TOGGLE_FORM_HANDLE)
        && str_contains($message, 'active=false'));
});

it('treats an imported string "false" toggle as inactive', function (): void {
    $form = makeForm(ACTIVE_TOGGLE_FORM_HANDLE);
    $form->store(false);
    $form->email(['name' => 'Imported off', 'to' => 'x@example.test', 'subject' => 'X', 'enabled' => 'false']);
    $form->save();

    $this->post('/!/forms/'.ACTIVE_TOGGLE_FORM_HANDLE, ['name' => 'Pat', 'department' => 'x'])->assertRedirect();

    expect(activeToggleSentRecipients())->toHaveCount(0);
});

it('skips an inactive notification even when its conditional logic would pass (active checked first)', function (): void {
    // The notification is inactive but its condition matches the submission; the active
    // toggle is checked before conditional logic, so it is still skipped.
    $form = makeForm(ACTIVE_TOGGLE_FORM_HANDLE);
    $form->store(false);
    $form->email([
        'name' => 'Inactive but matching',
        'to' => 'x@example.test',
        'subject' => 'X',
        'enabled' => false,
        'conditional_logic' => ['if' => ['department' => 'cardiology']],
    ]);
    $form->save();

    $this->post('/!/forms/'.ACTIVE_TOGGLE_FORM_HANDLE, ['name' => 'Pat', 'department' => 'cardiology'])->assertRedirect();

    expect(activeToggleSentRecipients())->toHaveCount(0);
});
