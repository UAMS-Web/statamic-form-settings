<?php

declare(strict_types=1);

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

/**
 * Proves a public-form Replicator survives into the notification email (UAMS-Web/uams-statamic#2051).
 *
 * `ReplicatorFormRoundTripTest` already covers render, validation and the
 * submission-data round-trip. It stops at the stored submission. This file covers
 * the step after it (what a recipient actually reads), because a replicator that
 * renders and stores correctly but emails an unreadable blob is not a migration
 * target for the WordPress waiver form it is meant to replace (566 submissions).
 *
 * The set mirrors that form: repeating participant rows, each with names, an email,
 * a parent checkbox and a typed signature.
 *
 * @command  vendor/bin/pest tests/Integration/Forms/ReplicatorEmailRoundTripTest.php
 */
/**
 * Handle of the throwaway form created for this test.
 */
const PEST_REPLICATOR_EMAIL_FORM_HANDLE = 'replicator_email_probe';

/**
 * Isolated forms directory so the throwaway form never touches resources/forms.
 *
 * @var string $pestReplicatorEmailFormsDir
 */
$pestReplicatorEmailFormsDir = '';

/**
 * Register a form whose only field is a required replicator of participant rows.
 */
function pestMakeReplicatorEmailForm(): void
{
    app(BlueprintRepository::class)->setFallback(
        'forms.'.PEST_REPLICATOR_EMAIL_FORM_HANDLE,
        fn () => Blueprint::makeFromFields([
            'participants' => [
                'type' => 'replicator',
                'display' => 'Participant Waivers',
                'validate' => ['required'],
                'min_sets' => 1,
                'sets' => [
                    'main' => [
                        'sets' => [
                            'participant' => [
                                'display' => 'Participant',
                                'fields' => [
                                    ['handle' => 'first_name', 'field' => ['type' => 'text', 'display' => "Participant's First Name"]],
                                    ['handle' => 'last_name', 'field' => ['type' => 'text', 'display' => "Participant's Last Name"]],
                                    ['handle' => 'is_minor', 'field' => ['type' => 'toggle', 'display' => 'I am the parent of the participant above']],
                                    ['handle' => 'email', 'field' => ['type' => 'text', 'display' => 'Email']],
                                    ['handle' => 'signature', 'field' => ['type' => 'text', 'display' => 'Signature']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])
    );

    $form = makeForm(PEST_REPLICATOR_EMAIL_FORM_HANDLE);
    $form->title('Participant Waiver Probe');
    $form->store(false);
    $form->email([
        'to' => 'recipient@example.test',
        'subject' => 'Participant waiver probe',
    ]);
    $form->save();
}

/**
 * Read the single captured notification email's rendered HTML body.
 *
 * @return string The rendered HTML body.
 */
function pestReplicatorEmailBody(): string
{
    $transport = mustBe(ArrayTransport::class, Mail::getSymfonyTransport(), 'transport');
    $messages = $transport->messages();
    expect($messages)->toHaveCount(1, 'Exactly one notification email should have been sent.');

    $first = mustBe(SentMessage::class, $messages->first(), 'first');
    $original = mustBe(Email::class, $first->getOriginalMessage(), 'original');

    return mustBeString($original->getHtmlBody(), 'body');
}

beforeEach(function () use (&$pestReplicatorEmailFormsDir): void {
    $pestReplicatorEmailFormsDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($pestReplicatorEmailFormsDir);
    Config::set('statamic.forms.forms', $pestReplicatorEmailFormsDir);
});

afterEach(function () use (&$pestReplicatorEmailFormsDir): void {
    if ($pestReplicatorEmailFormsDir !== '' && File::isDirectory($pestReplicatorEmailFormsDir)) {
        File::deleteDirectory($pestReplicatorEmailFormsDir);
    }
});

it('carries every set and subfield of a replicator into the notification email', function (): void {
    pestMakeReplicatorEmailForm();

    // Two participant rows, mirroring a real multi-participant waiver submission.
    $payload = [
        'participants' => [
            ['type' => 'participant', 'enabled' => true, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'is_minor' => false, 'email' => 'ada@probe.test', 'signature' => 'Ada Lovelace'],
            ['type' => 'participant', 'enabled' => true, 'first_name' => 'Grace', 'last_name' => 'Hopper', 'is_minor' => true, 'email' => 'grace@probe.test', 'signature' => 'Grace Hopper'],
        ],
    ];

    $this->post('/!/forms/'.PEST_REPLICATOR_EMAIL_FORM_HANDLE, $payload)->assertRedirect();

    $transport = mustBe(ArrayTransport::class, Mail::getSymfonyTransport(), 'transport');
    $messages = $transport->messages();
    expect($messages)->toHaveCount(1, 'Exactly one notification email should have been sent.');

    $first = mustBe(SentMessage::class, $messages->first(), 'first');
    $original = mustBe(Email::class, $first->getOriginalMessage(), 'original');
    $body = mustBeString($original->getHtmlBody(), 'body');

    // Every submitted value from BOTH sets must survive into the email.
    foreach (['Ada', 'Lovelace', 'ada@probe.test', 'Grace', 'Hopper', 'grace@probe.test'] as $value) {
        expect($body)->toContain($value);
    }

    // The worst-case failure, PHP array-to-string coercion, does NOT occur.
    expect($body)->not->toContain('Array');
    expect($body)->not->toContain('{{');
});

it('renders each set as a labelled block with subfield labels', function (): void {
    pestMakeReplicatorEmailForm();

    $this->post('/!/forms/'.PEST_REPLICATOR_EMAIL_FORM_HANDLE, [
        'participants' => [
            ['type' => 'participant', 'enabled' => true, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'is_minor' => false, 'email' => 'ada@probe.test', 'signature' => 'Ada Lovelace'],
            ['type' => 'participant', 'enabled' => true, 'first_name' => 'Grace', 'last_name' => 'Hopper', 'is_minor' => true, 'email' => 'grace@probe.test', 'signature' => 'Grace Hopper'],
        ],
    ])->assertRedirect();

    $body = pestReplicatorEmailBody();

    // One labelled block per set, in submission order.
    expect(substr_count($body, '<b>Participant</b>'))->toBe(2);

    // Subfield display labels, which the JSON blob carried none of.
    expect($body)->toContain('Participant&#039;s First Name: Ada');
    expect($body)->toContain('Participant&#039;s Last Name: Lovelace');
    expect($body)->toContain('Email: ada@probe.test');
    expect($body)->toContain('Signature: Grace Hopper');

    // The field's own display label still leads the block.
    expect($body)->toContain('Participant Waivers');

    // The escaped-JSON rendering this replaced is gone.
    expect($body)->not->toContain('&quot;first_name&quot;');
    expect($body)->not->toContain('&quot;type&quot;:&quot;participant&quot;');
});

it('renders subfield values through their own fieldtype semantics', function (): void {
    pestMakeReplicatorEmailForm();

    $this->post('/!/forms/'.PEST_REPLICATOR_EMAIL_FORM_HANDLE, [
        'participants' => [
            ['type' => 'participant', 'enabled' => true, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'is_minor' => false, 'email' => 'ada@probe.test', 'signature' => 'A'],
            ['type' => 'participant', 'enabled' => true, 'first_name' => 'Grace', 'last_name' => 'Hopper', 'is_minor' => true, 'email' => 'grace@probe.test', 'signature' => 'G'],
        ],
    ])->assertRedirect();

    $body = pestReplicatorEmailBody();

    // A toggle reads Yes/No, never a bare 1 or an empty string.
    expect($body)->toContain('I am the parent of the participant above: No');
    expect($body)->toContain('I am the parent of the participant above: Yes');
});

it('omits the internal id, type and enabled keys', function (): void {
    pestMakeReplicatorEmailForm();

    $this->post('/!/forms/'.PEST_REPLICATOR_EMAIL_FORM_HANDLE, [
        'participants' => [
            ['type' => 'participant', 'enabled' => true, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'is_minor' => false, 'email' => 'ada@probe.test', 'signature' => 'A'],
        ],
    ])->assertRedirect();

    $body = pestReplicatorEmailBody();

    foreach (['Type:', 'Enabled:', 'Id:', '_id'] as $leak) {
        expect($body)->not->toContain($leak);
    }
});

it('leaves every non-replicator fieldtype rendering unchanged', function (): void {
    // Bounds the change on the other side: the override must touch replicators only.
    app(BlueprintRepository::class)->setFallback(
        'forms.'.PEST_REPLICATOR_EMAIL_FORM_HANDLE,
        fn () => Blueprint::makeFromFields([
            'plain' => ['type' => 'text', 'display' => 'Plain Text'],
            'choice' => ['type' => 'select', 'display' => 'Choice', 'options' => ['r' => 'Red', 'b' => 'Blue']],
            'many' => ['type' => 'checkboxes', 'display' => 'Many', 'options' => ['x' => 'Ex', 'y' => 'Why']],
            'flag' => ['type' => 'toggle', 'display' => 'Flag'],
        ])
    );

    $form = makeForm(PEST_REPLICATOR_EMAIL_FORM_HANDLE);
    $form->title('Non-replicator Probe');
    $form->store(false);
    $form->email(['to' => 'recipient@example.test', 'subject' => 'Non-replicator probe']);
    $form->save();

    $this->post('/!/forms/'.PEST_REPLICATOR_EMAIL_FORM_HANDLE, [
        'plain' => 'hello',
        'choice' => 'r',
        'many' => ['x', 'y'],
        'flag' => '1',
    ])->assertRedirect();

    $body = pestReplicatorEmailBody();

    // Stock Statamic branches: select resolves its label, checkboxes join, text renders plain.
    expect($body)->toContain('Plain Text');
    expect($body)->toContain('hello');
    expect($body)->toContain('Red');
    expect($body)->toContain('Ex');
    expect($body)->toContain('Why');

    // No replicator scaffolding leaks into a form that has no replicator.
    expect($body)->not->toContain('<b>Participant</b>');
});

it('enforces a required subfield inside a replicator set', function (): void {
    app(BlueprintRepository::class)->setFallback(
        'forms.'.PEST_REPLICATOR_EMAIL_FORM_HANDLE,
        fn () => Blueprint::makeFromFields([
            'participants' => [
                'type' => 'replicator',
                'display' => 'Participant Waivers',
                'sets' => [
                    'main' => [
                        'sets' => [
                            'participant' => [
                                'display' => 'Participant',
                                'fields' => [
                                    ['handle' => 'first_name', 'field' => ['type' => 'text', 'display' => 'First Name', 'validate' => ['required']]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])
    );

    $form = makeForm(PEST_REPLICATOR_EMAIL_FORM_HANDLE);
    $form->title('Participant Waiver Probe');
    $form->store(false);
    $form->save();

    // A set whose required subfield is empty.
    $response = $this->post('/!/forms/'.PEST_REPLICATOR_EMAIL_FORM_HANDLE, [
        'participants' => [
            ['type' => 'participant', 'enabled' => true, 'first_name' => ''],
        ],
    ]);

    // Pins whether per-subfield rules reach the public-form validator at all.
    $response->assertSessionHasErrors();
});
