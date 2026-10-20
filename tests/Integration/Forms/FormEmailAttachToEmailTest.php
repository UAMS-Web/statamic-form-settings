<?php

declare(strict_types=1);

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Assets\AssetContainer as StatamicAssetContainer;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Site;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Form;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use UAMSWeb\FormSettings\Mail\FormNotificationEmail;

/**
 * Proves the custom Assets fieldtype's per-field `attach_to_email` toggle is the single
 * source of truth for which uploads attach to a form notification (UAMS-Web/uams-statamic#196): within a
 * notification whose `attachments` gate is on, only Assets fields flagged `attach_to_email`
 * attach, and a flagged field never double-attaches.
 *
 * @covers \UAMSWeb\FormSettings\Mail\FormNotificationEmail
 *
 * @command  vendor/bin/pest tests/Integration/Forms/FormEmailAttachToEmailTest.php
 */

// Safe under `pest --parallel`, and allowlisted for Rule A in
// scripts/check-parallel-safe-tests.php. The faked disk (`attach_to_email_disk`)
// is used by this file alone, and paratest runs a file in one worker, so no
// other process writes its directory. The asset container `beforeEach` saves
// into the shared fixtures directory is deleted in `afterEach`, and no other
// test here reads asset containers.

/**
 * Handle of the throwaway form created for this test.
 */
const ATTACH_TO_EMAIL_FORM_HANDLE = 'attach_to_email_probe';

/**
 * Handle of the throwaway asset container created for this test.
 */
const ATTACH_TO_EMAIL_CONTAINER_HANDLE = 'attach_to_email_assets';

/**
 * Isolated forms directory so the throwaway form never touches resources/forms.
 *
 * @var string Filesystem path to the per-test forms directory.
 */
$attachToEmailDir = '';

beforeEach(function () use (&$attachToEmailDir): void {
    // A faked asset disk + container holding two uploaded files.
    Storage::fake('attach_to_email_disk');
    $probeContainer = mustBe(StatamicAssetContainer::class, AssetContainer::make(ATTACH_TO_EMAIL_CONTAINER_HANDLE), 'probe container');
    $probeContainer->disk('attach_to_email_disk');
    $probeContainer->title('Attach');
    $probeContainer->save();
    Storage::disk('attach_to_email_disk')->put('flagged.txt', 'FLAGGED CONTENT');
    Storage::disk('attach_to_email_disk')->put('unflagged.txt', 'UNFLAGGED CONTENT');
    $container = mustBe(StatamicAssetContainer::class, AssetContainer::find(ATTACH_TO_EMAIL_CONTAINER_HANDLE), 'container');
    $container->makeAsset('flagged.txt')->save();
    $container->makeAsset('unflagged.txt')->save();

    // Isolate the forms directory.
    $attachToEmailDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($attachToEmailDir);
    Config::set('statamic.forms.forms', $attachToEmailDir);

    // Two Assets fields: one flagged to attach, one not.
    app(BlueprintRepository::class)->setFallback('forms.'.ATTACH_TO_EMAIL_FORM_HANDLE, fn () => Blueprint::makeFromFields([
        'flagged' => ['type' => 'assets', 'container' => ATTACH_TO_EMAIL_CONTAINER_HANDLE, 'max_files' => 1, 'attach_to_email' => true],
        'unflagged' => ['type' => 'assets', 'container' => ATTACH_TO_EMAIL_CONTAINER_HANDLE, 'max_files' => 1, 'attach_to_email' => false],
    ]));
});

afterEach(function () use (&$attachToEmailDir): void {
    // Delete throwaway assets and container so later tests never hit attach_to_email_disk.
    $container = AssetContainer::find(ATTACH_TO_EMAIL_CONTAINER_HANDLE);
    if ($container instanceof StatamicAssetContainer) {
        foreach (['flagged.txt', 'unflagged.txt'] as $basename) {
            $asset = $container->asset($basename);
            if ($asset instanceof StatamicAsset) {
                $asset->delete();
            }
        }

        $container->delete();
    }

    // Deleting the assets and the container above removes their files and the
    // container's entry in the `asset-containers` store, so no later test sees them.

    if ($attachToEmailDir !== '' && File::isDirectory($attachToEmailDir)) {
        File::deleteDirectory($attachToEmailDir);
    }
});

/**
 * Build + send a notification for a submission carrying both uploads, returning the
 * attached filenames.
 *
 * @param  array<string, mixed>  $config  The email-notification config.
 * @return list<string>
 */
function attachToEmailSendAndGetFilenames(array $config): array
{
    $form = makeForm(ATTACH_TO_EMAIL_FORM_HANDLE);
    $form->save();

    $submission = $form->makeSubmission();
    $submission->data(['flagged' => 'flagged.txt', 'unflagged' => 'unflagged.txt']);

    /** @var Statamic\Sites\Site $site */
    $site = Site::default();
    Mail::mailer('array')->send(new FormNotificationEmail($submission, $config, $site));

    $transport = mustBe(ArrayTransport::class, Mail::getSymfonyTransport(), 'transport');
    $sent = mustBe(SentMessage::class, $transport->messages()->first(), 'sent');
    $message = mustBe(Email::class, $sent->getOriginalMessage(), 'message');

    return array_values(array_filter(array_map(
        static fn (DataPart $part): ?string => $part->getFilename(),
        $message->getAttachments(),
    )));
}

it('attaches only the flagged Assets field upload when the notification attachments gate is on', function (): void {
    $filenames = attachToEmailSendAndGetFilenames([
        'to' => 'recipient@example.test',
        'subject' => 'With attachments',
        'attachments' => true,
    ]);

    expect($filenames)->toContain('flagged.txt');
    expect($filenames)->not->toContain('unflagged.txt');
    // The flagged field attaches exactly once (no double-attach).
    expect($filenames)->toBe(['flagged.txt']);
});

it('attaches nothing when the notification attachments gate is off', function (): void {
    $filenames = attachToEmailSendAndGetFilenames([
        'to' => 'recipient@example.test',
        'subject' => 'No attachments',
        // no 'attachments' key → notification carries no attachments
    ]);

    expect($filenames)->toBe([]);
});

it('attaches the same uploads when the row sends its message as the body', function (): void {
    // A row with a written message replaces the field list (UAMS-Web/uams-statamic#3149) and must not change the attachments
    $filenames = attachToEmailSendAndGetFilenames([
        'to' => 'recipient@example.test',
        'subject' => 'With a message',
        'attachments' => true,
        'message' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Upload: {{ submission:unflagged }}']]]],
    ]);

    expect($filenames)->toBe(['flagged.txt']);

    // The message rendered, naming the unattached upload by its URL
    $transport = mustBe(ArrayTransport::class, Mail::getSymfonyTransport(), 'transport');
    $sent = mustBe(SentMessage::class, $transport->messages()->first(), 'sent');
    $message = mustBe(Email::class, $sent->getOriginalMessage(), 'message');

    expect(mustBeString($message->getHtmlBody(), 'html'))->toMatch('/<p>Upload: \S*unflagged\.txt<\/p>/');
});
