<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Statamic\Events\FormSubmitted;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Stache;
use Statamic\Fields\BlueprintRepository;
use Statamic\Stache\Stores\Store;
use UAMSWeb\FormSettings\Listeners\EnforceFormRestrictions;
use UAMSWeb\FormSettings\Tests\Fixtures\Host\HostEventServiceProvider;
use UAMSWeb\FormSettings\Tests\Fixtures\Host\RefuseEverySubmission;

/**
 * The restrictions listener runs before a host application's own `FormSubmitted`
 * listeners, and is registered once (#25).
 *
 * Each test boots a host event service provider after the add-on's, as Laravel
 * registers a host's providers after its packages', and that host listener
 * refuses every submission. So a form a restriction closes shows the
 * restriction's message only when the add-on's listener ran first.
 *
 * @command  vendor/bin/pest tests/Integration/Listeners/ListenerOrderTest.php
 */
$orderFormsDir = '';
$orderSubsDir = '';
$orderHandle = '';

beforeEach(function () use (&$orderFormsDir, &$orderSubsDir, &$orderHandle): void {
    // Boot again with the host's event service provider after the add-on's
    $this->rebootWithHostProviders([HostEventServiceProvider::class]);

    // Give each test its own form-submit rate-limit bucket
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.'.random_int(0, 255).'.'.random_int(1, 254)]);

    $orderHandle = 'listener_order_'.str_replace('.', '', uniqid('', true));

    // Store this test's forms and submissions in directories of its own
    $orderFormsDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($orderFormsDir);
    Config::set('statamic.forms.forms', $orderFormsDir);

    $orderSubsDir = storage_path('framework/testing/subs-'.uniqid('', true));
    File::ensureDirectoryExists($orderSubsDir);
    Config::set('statamic.forms.submissions', $orderSubsDir);
    /** @var Store $submissionsStore */
    $submissionsStore = Stache::store('form-submissions');
    $submissionsStore->directory($orderSubsDir);

    app(BlueprintRepository::class)->setFallback('forms.'.$orderHandle, fn () => Blueprint::makeFromFields([
        'name' => ['type' => 'text', 'display' => 'Name'],
    ]));
});

/**
 * The listeners the dispatcher holds for an event, in the order it calls them.
 *
 * @param  string|null  $event  The event class, or null for every event's listeners together.
 * @return list<mixed> The listeners as they were registered: class names, or closures.
 */
function registeredListeners(?string $event): array
{
    $listeners = [];
    foreach (Event::getRawListeners() as $name => $forEvent) {
        if (($event === null || $name === $event) && is_array($forEvent)) {
            $listeners = [...$listeners, ...array_values($forEvent)];
        }
    }

    return $listeners;
}

afterEach(function () use (&$orderFormsDir, &$orderSubsDir): void {
    foreach ([$orderFormsDir, $orderSubsDir] as $dir) {
        if ($dir !== '' && File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }
    }
});

it('registers the restrictions listener ahead of the host listener', function (): void {
    // Read the listeners in the order the dispatcher calls them
    $listeners = registeredListeners(FormSubmitted::class);

    expect($listeners)->toContain(RefuseEverySubmission::class)
        ->and($listeners[0] ?? null)->toBe(EnforceFormRestrictions::class);
});

it('registers the restrictions listener once', function (): void {
    $listeners = registeredListeners(FormSubmitted::class);

    expect(array_count_values(array_filter($listeners, is_string(...)))[EnforceFormRestrictions::class] ?? 0)->toBe(1);
});

it('registers every listener in src/Listeners, since the provider skips their discovery', function (): void {
    // Every class the dispatcher was given, for any event
    $registered = registeredListeners(null);

    // The classes Statamic's discovery would have registered
    $classes = array_map(
        static fn (SplFileInfo $file): string => 'UAMSWeb\\FormSettings\\Listeners\\'.$file->getBasename('.php'),
        File::files(__DIR__.'/../../../src/Listeners'),
    );

    expect($classes)->not->toBeEmpty();
    foreach ($classes as $class) {
        expect($registered)->toContain($class);
    }
});

it('shows the restriction message when a host listener would also refuse the submission', function () use (&$orderHandle): void {
    // A form whose schedule ended long ago
    $form = makeForm($orderHandle);
    $form->set('schedule_form_query', true);
    $form->set('schedule_form', ['start_datetime' => '2000-01-01 00:00', 'end_datetime' => '2000-12-31 23:59']);
    $form->set('schedule_expired_message', 'This form has closed.');
    $form->save();

    $this->post('/!/forms/'.$orderHandle, ['name' => 'Pat'])
        ->assertSessionHasErrors([$orderHandle => 'This form has closed.'], null, 'form.'.$orderHandle);
});

it('leaves the host listener to refuse a submission no restriction blocks', function () use (&$orderHandle): void {
    // A form with no restrictions
    $form = makeForm($orderHandle);
    $form->save();

    $this->post('/!/forms/'.$orderHandle, ['name' => 'Pat'])
        ->assertSessionHasErrors([$orderHandle => RefuseEverySubmission::MESSAGE], null, 'form.'.$orderHandle);
});
