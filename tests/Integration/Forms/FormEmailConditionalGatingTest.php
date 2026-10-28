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
use Symfony\Component\Mime\Email;

/**
 * End-to-end proof that per-notification conditional logic gates the send (issue UAMS-Web/uams-statamic-archive#202).
 *
 * Submits one form carrying three email notifications (one whose `conditional_logic`
 * passes against the submitted values, one whose fails, and one with no conditional logic)
 * over HTTP on the sync connection (the job runs inline), and asserts that exactly the
 * passing and unconditional notifications send, while the failing one is suppressed. This
 * proves notifications are gated independently against the same submission.
 *
 * @covers \UAMSWeb\FormSettings\Support\ConditionalLogicEvaluator
 * @covers \UAMSWeb\FormSettings\Jobs\SendFormNotification
 *
 * @command  vendor/bin/pest tests/Integration/Forms/FormEmailConditionalGatingTest.php
 */
/**
 * Handle of the throwaway form created for this test.
 */
const CONDITIONAL_GATING_FORM_HANDLE = 'conditional_gating_probe';

/**
 * Isolated forms directory so the throwaway form never touches resources/forms.
 *
 * @var string Filesystem path to the per-test forms directory.
 */
$conditionalGatingDir = '';

beforeEach(function () use (&$conditionalGatingDir): void {
    // Submit from a unique test-net IP so this file's submissions never share the
    // per-IP form-submit rate-limit bucket (whose counter lives in the shared file
    // cache across paratest workers) with other form tests.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11']);

    // Isolate the forms directory so the throwaway form is self-contained
    $conditionalGatingDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($conditionalGatingDir);
    Config::set('statamic.forms.forms', $conditionalGatingDir);

    // Give the form a blueprint so the submitted `department` value is stored + augmented
    app(BlueprintRepository::class)->setFallback('forms.'.CONDITIONAL_GATING_FORM_HANDLE, fn () => Blueprint::makeFromFields([
        'name' => ['type' => 'text', 'display' => 'Name'],
        'department' => ['type' => 'text', 'display' => 'Department'],
    ]));
});

afterEach(function () use (&$conditionalGatingDir): void {
    // Remove the isolated forms directory
    if ($conditionalGatingDir !== '' && File::isDirectory($conditionalGatingDir)) {
        File::deleteDirectory($conditionalGatingDir);
    }
});

/**
 * Collect the lowercase recipient addresses across every captured notification email.
 *
 * @return list<string>
 */
function conditionalGatingSentRecipients(): array
{
    $transport = mustBe(ArrayTransport::class, Mail::getSymfonyTransport(), 'transport');

    return array_values(
        $transport->messages()
            ->map(function ($sent): string {
                $sent = mustBe(SentMessage::class, $sent, 'sent');
                $message = mustBe(Email::class, $sent->getOriginalMessage(), 'message');

                return strtolower($message->getTo()[0]->getAddress());
            })
            ->all()
    );
}

it('sends only the notifications whose conditional logic passes, independently', function (): void {
    // Three notifications gated independently against the same submission.
    $form = makeForm(CONDITIONAL_GATING_FORM_HANDLE);
    $form->store(false);
    $form->email([
        [
            'to' => 'cardiology@example.test',
            'subject' => 'Cardiology',
            'conditional_logic' => ['if' => ['department' => 'cardiology'], 'always_save' => false],
        ],
        [
            'to' => 'oncology@example.test',
            'subject' => 'Oncology',
            'conditional_logic' => ['if' => ['department' => 'oncology'], 'always_save' => false],
        ],
        [
            'to' => 'always@example.test',
            'subject' => 'Always',
        ],
    ]);
    $form->save();

    // Submit with department=cardiology
    $this->post('/!/forms/'.CONDITIONAL_GATING_FORM_HANDLE, [
        'name' => 'Pat Submitter',
        'department' => 'cardiology',
    ])->assertRedirect();

    $recipients = conditionalGatingSentRecipients();

    // The passing (cardiology) and unconditional (always) notifications send; oncology is gated out.
    expect($recipients)->toContain('cardiology@example.test');
    expect($recipients)->toContain('always@example.test');
    expect($recipients)->not->toContain('oncology@example.test');
    expect($recipients)->toHaveCount(2);
});

it('still Antlers-renders the string config fields while the conditional_logic array is present', function (): void {
    // The conditional_logic array must not break config parsing: the subject merge tag
    // still resolves (the parseConfig guard renders strings, passes the array through).
    $form = makeForm(CONDITIONAL_GATING_FORM_HANDLE);
    $form->store(false);
    $form->email([
        'to' => 'dept@example.test',
        'subject' => 'Dept: {{ department }}',
        'conditional_logic' => ['if' => ['department' => 'cardiology'], 'always_save' => false],
    ]);
    $form->save();

    $this->post('/!/forms/'.CONDITIONAL_GATING_FORM_HANDLE, [
        'name' => 'Pat Submitter',
        'department' => 'cardiology',
    ])->assertRedirect();

    $transport = mustBe(ArrayTransport::class, Mail::getSymfonyTransport(), 'transport');
    $messages = $transport->messages();
    expect($messages)->toHaveCount(1);

    $sent = mustBe(SentMessage::class, $messages->first(), 'sent');
    $message = mustBe(Email::class, $sent->getOriginalMessage(), 'message');

    // The merge tag rendered (string field parsed) and no literal markers leaked.
    expect($message->getSubject())->toBe('Dept: cardiology');
});

it('suppresses a single notification whose condition fails, sending nothing', function (): void {
    $form = makeForm(CONDITIONAL_GATING_FORM_HANDLE);
    $form->store(false);
    $form->email([
        'to' => 'oncology@example.test',
        'subject' => 'Oncology only',
        'conditional_logic' => ['if' => ['department' => 'oncology']],
    ]);
    $form->save();

    // department=cardiology fails the oncology condition
    $this->post('/!/forms/'.CONDITIONAL_GATING_FORM_HANDLE, [
        'name' => 'Pat Submitter',
        'department' => 'cardiology',
    ])->assertRedirect();

    $transport = mustBe(ArrayTransport::class, Mail::getSymfonyTransport(), 'transport');
    expect($transport->messages())->toHaveCount(0);
});
