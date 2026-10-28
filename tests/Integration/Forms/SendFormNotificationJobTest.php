<?php

declare(strict_types=1);

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Statamic\Contracts\Forms\Submission as StatamicSubmission;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Site;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Submission;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use UAMSWeb\FormSettings\Contracts\SubmissionContext;
use UAMSWeb\FormSettings\Jobs\SendFormNotification;
use UAMSWeb\FormSettings\Support\NullSubmissionContext;

/**
 * Drives {@see SendFormNotification} directly, without a submit request, so each decision it
 * makes between building the job and handing the mailable to a mailer is asserted on its own:
 * capturing and applying the bound submission context, skipping a blank TO, dropping a blank CC/BCC/Reply-To,
 * choosing the mailer, reading the Active toggle, and naming the notification in the skip log.
 * The submit-flow behavior is pinned end to end by the FormEmail* feature tests (UAMS-Web/uams-statamic-archive#3089).
 *
 * Mutation (UAMS-Web/uams-statamic-archive#3089, macOS, measured on main 287d8ef41, with the five FormEmail* feature tests that
 * cover the class): 55 mutants, all killed, no annotations. An `is_bool` branch in the Active
 * toggle read that changed nothing beside the `(bool)` fallback was deleted.
 *
 * @covers \UAMSWeb\FormSettings\Jobs\SendFormNotification
 *
 * @command  vendor/bin/pest tests/Integration/Forms/SendFormNotificationJobTest.php
 */
$sendJobFormsDir = '';

beforeEach(function () use (&$sendJobFormsDir): void {
    $sendJobFormsDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($sendJobFormsDir);
    Config::set('statamic.forms.forms', $sendJobFormsDir);
});

afterEach(function () use (&$sendJobFormsDir): void {
    if ($sendJobFormsDir !== '' && File::isDirectory($sendJobFormsDir)) {
        File::deleteDirectory($sendJobFormsDir);
    }
});

/**
 * A submission on a throwaway form with a `name` and an `email` field.
 *
 * @param  array<string, mixed>  $data
 */
function sendJobSubmission(array $data = ['name' => 'Ada', 'email' => 'ada@example.test']): Submission
{
    $handle = 'send_job_probe_'.str_replace('.', '', uniqid('', true));

    app(BlueprintRepository::class)->setFallback('forms.'.$handle, fn () => Blueprint::makeFromFields([
        'name' => ['type' => 'text', 'display' => 'Name'],
        'email' => ['type' => 'text', 'display' => 'Email'],
    ]));

    $form = makeForm($handle);
    $form->save();

    $submission = $form->makeSubmission();
    $submission->data($data);

    return mustBe(Submission::class, $submission, 'submission');
}

/**
 * Run the job for one notification config.
 *
 * @param  array<string, mixed>  $config
 */
function runSendJob(array $config, ?Submission $submission = null): void
{
    new SendFormNotification($submission ?? sendJobSubmission(), mustBe(Statamic\Sites\Site::class, Site::default(), 'site'), $config + ['subject' => 'Hello'])->handle();
}

/**
 * Every message the given mailer's array transport captured.
 *
 * @return list<Email>
 */
function sendJobMessages(?string $mailer = null): array
{
    $transport = mustBe(ArrayTransport::class, Mail::mailer($mailer)->getSymfonyTransport(), 'transport');

    /** @var list<Email> $messages */
    $messages = $transport->messages()
        ->map(fn ($sent): Email => mustBe(Email::class, mustBe(SentMessage::class, $sent, 'sent')->getOriginalMessage(), 'message'))
        ->values()
        ->all();

    return $messages;
}

/**
 * The addresses in a header list.
 *
 * @param  array<Address>  $addresses
 * @return list<string>
 */
function sendJobAddresses(array $addresses): array
{
    return array_values(array_map(fn (Address $address): string => $address->getAddress(), $addresses));
}

it('claims the context the bound submission context captures for the submission', function (): void {
    $submission = sendJobSubmission();
    $submission->id('42');

    app()->instance(SubmissionContext::class, new class implements SubmissionContext
    {
        public function capture(StatamicSubmission $submission): array
        {
            return ['ip' => '203.0.113.'.mustBeString($submission->id(), 'submission id')];
        }

        public function during(array $context, callable $send): mixed
        {
            return $send();
        }
    });

    $job = new SendFormNotification($submission, mustBe(Statamic\Sites\Site::class, Site::default(), 'site'), []);

    expect($job->requestContext)->toBe(['ip' => '203.0.113.42']);
});

it('captures nothing with the default submission context, and sends with no context active', function (): void {
    $job = new SendFormNotification(sendJobSubmission(), mustBe(Statamic\Sites\Site::class, Site::default(), 'site'), ['to' => 'staff@example.test', 'subject' => 'Hello']);
    $job->handle();

    expect(app(SubmissionContext::class))->toBeInstanceOf(NullSubmissionContext::class)
        ->and($job->requestContext)->toBeNull()
        ->and(sendJobMessages())->toHaveCount(1);
});

it('sends inside the bound context, handing it the captured array', function (): void {
    /** @var ArrayObject<int, array<string, mixed>> $seen */
    $seen = new ArrayObject;
    app()->instance(SubmissionContext::class, new class($seen) implements SubmissionContext
    {
        /** @param  ArrayObject<int, array<string, mixed>>  $seen */
        public function __construct(private ArrayObject $seen) {}

        public function capture(StatamicSubmission $submission): array
        {
            return ['ip' => '203.0.113.9'];
        }

        public function during(array $context, callable $send): mixed
        {
            $this->seen->append($context);

            return $send();
        }
    });

    runSendJob(['to' => 'staff@example.test']);

    expect($seen->getArrayCopy())->toBe([['ip' => '203.0.113.9']])
        ->and(sendJobMessages())->toHaveCount(1);
});

it('skips a notification whose TO is only whitespace, and logs why', function (): void {
    $log = Log::spy();

    runSendJob(['to' => '   ', 'name' => 'Staff copy']);

    expect(sendJobMessages())->toBe([]);
    $log->shouldHaveReceived('debug')->once()->withArgs(fn (string $message): bool => str_contains($message, '"Staff copy"')
        && str_contains($message, 'resolved no TO recipient'));
});

it('resolves CC, BCC and Reply-To from their configured sources', function (): void {
    runSendJob([
        'to' => 'staff@example.test',
        'cc_type' => 'field', 'cc_field' => 'email',
        'bcc_type' => 'field', 'bcc_field' => 'email',
        'reply_to_type' => 'field', 'reply_to_field' => 'email',
    ]);

    $message = sendJobMessages()[0];

    expect(sendJobAddresses($message->getCc()))->toBe(['ada@example.test'])
        ->and(sendJobAddresses($message->getBcc()))->toBe(['ada@example.test'])
        ->and(sendJobAddresses($message->getReplyTo()))->toBe(['ada@example.test']);
});

it('drops a CC, BCC or Reply-To that is only whitespace instead of building a blank header', function (): void {
    runSendJob(['to' => 'staff@example.test', 'cc' => '   ', 'bcc' => '  ', 'reply_to' => ' ']);

    $message = sendJobMessages()[0];

    expect($message->getCc())->toBe([])
        ->and($message->getBcc())->toBe([])
        ->and($message->getReplyTo())->toBe([]);
});

it('sends through the configured mailer, and the default one when the setting is not text', function (): void {
    Config::set('mail.mailers.form_second', ['transport' => 'array']);

    runSendJob(['to' => 'staff@example.test', 'mailer' => 'form_second']);
    runSendJob(['to' => 'other@example.test', 'mailer' => ['form_second']]);

    expect(array_map(fn (Email $message): string => $message->getTo()[0]->getAddress(), sendJobMessages('form_second')))
        ->toBe(['staff@example.test'])
        ->and(array_map(fn (Email $message): string => $message->getTo()[0]->getAddress(), sendJobMessages()))
        ->toBe(['other@example.test']);
});

it('reads a string Active toggle as words, case and spacing aside', function (string $enabled, bool $sends): void {
    runSendJob(['to' => 'staff@example.test', 'enabled' => $enabled]);

    expect(count(sendJobMessages()))->toBe($sends ? 1 : 0);
})->with([
    'false' => ['false', false],
    'zero' => ['0', false],
    'no' => ['no', false],
    'off' => ['off', false],
    'empty' => ['', false],
    'padded and capitalized' => ['  OFF ', false],
    'yes' => ['yes', true],
    'a number that only equals zero loosely' => ['00', true],
]);

it('names the notification in the skip log, or calls it unnamed', function (mixed $name, string $expected): void {
    $log = Log::spy();

    runSendJob(['to' => 'staff@example.test', 'enabled' => false, 'name' => $name]);

    $log->shouldHaveReceived('debug')->once()->withArgs(fn (string $message): bool => str_contains($message, 'Form notification "'.$expected.'"'));
})->with([
    'a name' => ['Staff copy', 'Staff copy'],
    'a number' => [5, '5'],
    'an empty string' => ['', 'unnamed'],
    'false' => [false, 'unnamed'],
    'null' => [null, 'unnamed'],
    'a list' => [['Staff copy'], 'unnamed'],
]);

it('reads a numeric Active toggle by its truthiness', function (int $enabled, int $sent): void {
    runSendJob(['to' => 'staff@example.test', 'enabled' => $enabled]);

    expect(sendJobMessages())->toHaveCount($sent);
})->with([
    'zero' => [0, 0],
    'one' => [1, 1],
]);
