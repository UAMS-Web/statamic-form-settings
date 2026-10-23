<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Form;

/**
 * Proves a form's configured confirmation drives the post-submit response (UAMS-Web/uams-statamic#203): a message
 * confirmation overrides the success text (Antlers-rendered), a redirect/entry confirmation
 * redirects (with an optional query string), and conditional confirmations select the first
 * matching one (else the default). A form with no confirmation config keeps Statamic's default.
 *
 * @covers \UAMSWeb\FormSettings\Http\Middleware\ApplyFormConfirmation
 * @covers \UAMSWeb\FormSettings\Support\FormConfirmationResolver
 *
 * @command  vendor/bin/pest tests/Integration/Forms/FormConfirmationTest.php
 */
$confirmationFormsDir = '';
$confirmationHandle = '';

beforeEach(function () use (&$confirmationFormsDir, &$confirmationHandle): void {
    // A unique IP per test keeps each test's submissions in their own form-submit rate-limit
    // bucket (Statamic's `statamic.forms` throttle), so the file's many posts never overflow it.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.'.random_int(0, 255).'.'.random_int(1, 254)]);

    $confirmationHandle = 'confirmation_probe_'.str_replace('.', '', uniqid('', true));

    $confirmationFormsDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($confirmationFormsDir);
    Config::set('statamic.forms.forms', $confirmationFormsDir);

    app(BlueprintRepository::class)->setFallback('forms.'.$confirmationHandle, fn () => Blueprint::makeFromFields([
        'name' => ['type' => 'text', 'display' => 'Name'],
        'tier' => ['type' => 'text', 'display' => 'Tier'],
    ]));
});

afterEach(function () use (&$confirmationFormsDir): void {
    if ($confirmationFormsDir !== '' && File::isDirectory($confirmationFormsDir)) {
        File::deleteDirectory($confirmationFormsDir);
    }
});

/**
 * Build + save the probe form with the given confirmation config.
 *
 * @param  array<string, mixed>  $config
 */
function makeConfirmationForm(string $handle, array $config): Form
{
    $form = makeForm($handle);
    $form->store(false);

    foreach ($config as $key => $value) {
        $form->set($key, $value);
    }

    $form->save();

    return $form;
}

it('shows the Antlers-rendered message confirmation in place of the default success text', function () use (&$confirmationHandle): void {
    makeConfirmationForm($confirmationHandle, [
        'confirmation_settings_default' => [
            'confirmation_type' => 'message',
            'confirmation_message' => 'Thank you, {{ name }}!',
        ],
    ]);

    $this->post('/!/forms/'.$confirmationHandle, ['name' => 'Pat'])
        ->assertRedirect()
        ->assertSessionHas('form.'.$confirmationHandle.'.success', 'Thank you, Pat!');
});

it('shows a Bard message confirmation as HTML with the submission merge tags resolved', function () use (&$confirmationHandle): void {
    makeConfirmationForm($confirmationHandle, [
        'confirmation_settings_default' => [
            'confirmation_type' => 'message',
            'confirmation_message' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Thank you, {{ name }}!']]],
            ],
        ],
    ]);

    $this->post('/!/forms/'.$confirmationHandle, ['name' => 'Pat'])
        ->assertRedirect()
        ->assertSessionHas('form.'.$confirmationHandle.'.success', '<p>Thank you, Pat!</p>');
});

it('does not run Antlers tags a submitter typed into a field of a Bard message confirmation', function () use (&$confirmationHandle): void {
    makeConfirmationForm($confirmationHandle, [
        'confirmation_settings_default' => [
            'confirmation_type' => 'message',
            'confirmation_message' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Thank you, {{ name }}!']]],
            ],
        ],
    ]);

    $this->post('/!/forms/'.$confirmationHandle, ['name' => '{{ 1 + 1 }}'])
        ->assertRedirect()
        ->assertSessionHas('form.'.$confirmationHandle.'.success', fn (mixed $message): bool => is_string($message) && ! str_contains($message, '2'));
});

it('redirects to the configured URL for a redirect confirmation, with a rendered query string', function () use (&$confirmationHandle): void {
    makeConfirmationForm($confirmationHandle, [
        'confirmation_settings_default' => [
            'confirmation_type' => 'redirect',
            'confirmation_redirect_url' => '/thank-you',
            'confirmation_query_string' => 'ref={{ name }}',
        ],
    ]);

    $this->post('/!/forms/'.$confirmationHandle, ['name' => 'Pat'])
        ->assertRedirect('/thank-you?ref=Pat');
});

it('selects the first conditional confirmation whose logic matches, else the default', function (string $tier, string $expected) use (&$confirmationHandle): void {
    makeConfirmationForm($confirmationHandle, [
        'confirmation_settings_default' => [
            'confirmation_type' => 'message',
            'confirmation_message' => 'Standard thanks.',
        ],
        'confirmation_additional' => [
            [
                'type' => 'confirmation',
                'confirmation_settings' => [
                    'confirmation_type' => 'message',
                    'confirmation_message' => 'VIP thanks!',
                ],
                'conditional_logic' => ['if' => ['tier' => 'gold']],
            ],
        ],
    ]);

    $this->post('/!/forms/'.$confirmationHandle, ['name' => 'Pat', 'tier' => $tier])
        ->assertSessionHas('form.'.$confirmationHandle.'.success', $expected);
})->with([
    'matching condition → conditional confirmation' => ['gold', 'VIP thanks!'],
    'non-matching condition → default confirmation' => ['silver', 'Standard thanks.'],
]);

it('redirects to the chosen entry URL for an entry (page) confirmation', function () use (&$confirmationHandle): void {
    /** @var Statamic\Entries\Collection $collection */
    $collection = Collection::make('confirmation_pages');
    /** @var Statamic\Entries\Collection $collection */
    $collection = $collection->routes('/thanks/{slug}');
    $collection->save();
    /** @var Statamic\Entries\Entry $entry */
    $entry = Entry::make();
    /** @var Statamic\Entries\Entry $entry */
    $entry = $entry->collection('confirmation_pages');
    /** @var Statamic\Entries\Entry $entry */
    $entry = $entry->slug('done');
    /** @var Statamic\Entries\Entry $entry */
    $entry = $entry->data(['title' => 'Done']);
    $entry->save();

    makeConfirmationForm($confirmationHandle, [
        'confirmation_settings_default' => [
            'confirmation_type' => 'entry',
            'confirmation_page' => $entry->id(),
        ],
    ]);

    /** @var string $entryUrl */
    $entryUrl = $entry->url();
    $this->post('/!/forms/'.$confirmationHandle, ['name' => 'Pat'])
        ->assertRedirect($entryUrl);

    Collection::find('confirmation_pages')?->delete();
});

it('leaves the default Statamic success behavior when no confirmation is configured', function () use (&$confirmationHandle): void {
    makeConfirmationForm($confirmationHandle, []);

    $this->post('/!/forms/'.$confirmationHandle, ['name' => 'Pat'])
        ->assertRedirect()
        ->assertSessionHas('form.'.$confirmationHandle.'.success', 'Submission successful.');
});

it('does not leak a confirmation onto a following failed submission (stale-flash guard)', function () use (&$confirmationHandle): void {
    // Form A has a redirect confirmation; form B is a different form that will fail validation.
    makeConfirmationForm($confirmationHandle, [
        'confirmation_settings_default' => ['confirmation_type' => 'redirect', 'confirmation_redirect_url' => '/thank-you'],
    ]);
    $handleB = 'confirmation_probe_b_'.str_replace('.', '', uniqid('', true));
    app(BlueprintRepository::class)->setFallback('forms.'.$handleB, fn () => Blueprint::makeFromFields([
        'email' => ['type' => 'text', 'display' => 'Email', 'validate' => ['required']],
    ]));
    $formB = makeForm($handleB);
    $formB->store(false);
    $formB->save();

    // Request 1: A succeeds → its redirect, flashing the submission for one request.
    $this->post('/!/forms/'.$confirmationHandle, ['name' => 'Pat'])->assertRedirect('/thank-you');

    // Request 2 (same session): B fails validation. The carried-over flash of A's submission must
    // NOT make B's failure redirect to A's confirmation: B redirects back with its own errors.
    $response = $this->post('/!/forms/'.$handleB, []);
    $response->assertSessionHasErrors([], null, 'form.'.$handleB);

    expect($response->headers->get('Location'))->not->toBe('/thank-you');
});

it('refuses an external confirmation redirect (no open redirect), falling back to the default', function () use (&$confirmationHandle): void {
    makeConfirmationForm($confirmationHandle, [
        'confirmation_settings_default' => [
            'confirmation_type' => 'redirect',
            'confirmation_redirect_url' => 'https://evil.test/phish',
        ],
    ]);

    $response = $this->post('/!/forms/'.$confirmationHandle, ['name' => 'Pat']);

    // The external target is refused: the response does not redirect off-site.
    expect($response->headers->get('Location'))->not->toContain('evil.test');
});

it('navigates to the replace entry page for a replace confirmation', function () use (&$confirmationHandle): void {
    /** @var Statamic\Entries\Collection $collection */
    $collection = Collection::make('confirmation_pages');
    /** @var Statamic\Entries\Collection $collection */
    $collection = $collection->routes('/thanks/{slug}');
    $collection->save();
    /** @var Statamic\Entries\Entry $entry */
    $entry = Entry::make();
    /** @var Statamic\Entries\Entry $entry */
    $entry = $entry->collection('confirmation_pages');
    /** @var Statamic\Entries\Entry $entry */
    $entry = $entry->slug('replaced');
    /** @var Statamic\Entries\Entry $entry */
    $entry = $entry->data(['title' => 'Replaced']);
    $entry->save();

    makeConfirmationForm($confirmationHandle, [
        'confirmation_settings_default' => [
            'confirmation_type' => 'replace',
            'confirmation_replace_entry' => $entry->id(),
        ],
    ]);

    /** @var string $entryUrl */
    $entryUrl = $entry->url();
    $this->post('/!/forms/'.$confirmationHandle, ['name' => 'Pat'])->assertRedirect($entryUrl);

    Collection::find('confirmation_pages')?->delete();
});

it('lets an additional confirmation with empty conditional logic always win over the default', function () use (&$confirmationHandle): void {
    makeConfirmationForm($confirmationHandle, [
        'confirmation_settings_default' => ['confirmation_type' => 'message', 'confirmation_message' => 'Default.'],
        'confirmation_additional' => [
            [
                'type' => 'confirmation',
                'confirmation_settings' => ['confirmation_type' => 'message', 'confirmation_message' => 'Unconditional override.'],
                'conditional_logic' => [],
            ],
        ],
    ]);

    $this->post('/!/forms/'.$confirmationHandle, ['name' => 'Pat'])
        ->assertSessionHas('form.'.$confirmationHandle.'.success', 'Unconditional override.');
});

it('skips a matched additional confirmation that has no settings and applies the default', function () use (&$confirmationHandle): void {
    makeConfirmationForm($confirmationHandle, [
        'confirmation_settings_default' => ['confirmation_type' => 'message', 'confirmation_message' => 'Default applies.'],
        'confirmation_additional' => [
            ['type' => 'confirmation', 'confirmation_settings' => [], 'conditional_logic' => []],
        ],
    ]);

    $this->post('/!/forms/'.$confirmationHandle, ['name' => 'Pat'])
        ->assertSessionHas('form.'.$confirmationHandle.'.success', 'Default applies.');
});

it('falls back to the default success when an entry confirmation page is missing', function () use (&$confirmationHandle): void {
    makeConfirmationForm($confirmationHandle, [
        'confirmation_settings_default' => ['confirmation_type' => 'entry', 'confirmation_page' => 'nonexistent-entry-id'],
    ]);

    $this->post('/!/forms/'.$confirmationHandle, ['name' => 'Pat'])
        ->assertRedirect()
        ->assertSessionHas('form.'.$confirmationHandle.'.success', 'Submission successful.');
});
