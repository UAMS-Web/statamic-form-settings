<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Illuminate\View\FileViewFinder;
use Statamic\Contracts\Forms\Submission;
use Statamic\Events\FormSubmitted;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use Statamic\Forms\SendEmail;
use Statamic\Http\Controllers\CP\Forms\FormsController as BaseFormsController;
use UAMSWeb\FormSettings\Features;
use UAMSWeb\FormSettings\Http\Controllers\FormsController;
use UAMSWeb\FormSettings\Http\Middleware\ApplyFormConfirmation;
use UAMSWeb\FormSettings\Jobs\SendFormNotification;
use UAMSWeb\FormSettings\Listeners\EnforceFormRestrictions;
use UAMSWeb\FormSettings\Tags\FormRestriction;
use UAMSWeb\FormSettings\Tests\TestCase;

/**
 * Each feature switch turns its own feature off and leaves the other three
 * working, and the notification job is installed only over Statamic's own.
 *
 * The add-on reads the switches while booting, so each test that turns one off
 * reboots the application with the switch set first.
 *
 * @command  vendor/bin/pest tests/Integration/FeaturesTest.php
 */

/**
 * Reboot the application with the named features switched off.
 *
 * @param  TestCase  $test  The running test.
 * @param  string  ...$features  Constants of {@see Features}.
 */
function bootWithFeaturesOff(TestCase $test, string ...$features): void
{
    $config = [];
    foreach ($features as $feature) {
        $config['statamic-form-settings.features.'.$feature] = false;
    }

    $test->rebootWithConfig($config);
}

/**
 * Which of the four features are in effect in the booted application.
 *
 * Each is read from what the add-on did while booting: the controller binding,
 * the notification job and the email view, the confirmation middleware, and
 * whether a submission to a restricted form is refused.
 *
 * @return array{control_panel: bool, notifications: bool, notification_view: bool, confirmations: bool, restrictions: bool} Whether each feature is in effect.
 */
function featuresInEffect(): array
{
    // A form that requires login, which an anonymous submission breaks
    $form = mustBe(Form::class, FormFacade::make('features_probe')->title('Probe'), 'form');
    $form->set('require_login_query', true);
    $form->set('require_login_message', 'Log in first.');

    $submission = mustBe(Submission::class, $form->makeSubmission(), 'submission');

    try {
        app(EnforceFormRestrictions::class)->handle(new FormSubmitted($submission));
        $refused = false;
    } catch (ValidationException) {
        $refused = true;
    }

    $hints = mustBeArray(mustBe(FileViewFinder::class, View::getFinder(), 'view finder')->getHints()['statamic'] ?? [], 'view hints');
    $hintPaths = array_map(static fn (mixed $path): string|false => realpath(mustBeString($path, 'view hint')), $hints);
    $ownViews = realpath(dirname(__DIR__, 2).'/resources/views');
    $webMiddleware = mustBeArray(app(Router::class)->getMiddlewareGroups()['web'] ?? [], 'web middleware');

    return [
        'control_panel' => app(BaseFormsController::class) instanceof FormsController,
        'notifications' => config('statamic.forms.send_email_job') === SendFormNotification::class,
        'notification_view' => in_array($ownViews, $hintPaths, true),
        'confirmations' => in_array(ApplyFormConfirmation::class, $webMiddleware, true),
        'restrictions' => $refused,
    ];
}

it('turns every feature on by default', function (): void {
    expect(featuresInEffect())->toBe([
        'control_panel' => true,
        'notifications' => true,
        'notification_view' => true,
        'confirmations' => true,
        'restrictions' => true,
    ]);
});

it('turns the form settings screen off alone', function (): void {
    bootWithFeaturesOff(mustBe(TestCase::class, $this, 'test case'), Features::CONTROL_PANEL);

    expect(featuresInEffect())->toBe([
        'control_panel' => false,
        'notifications' => true,
        'notification_view' => true,
        'confirmations' => true,
        'restrictions' => true,
    ]);
});

it("turns notifications off alone, leaving Statamic's own job", function (): void {
    bootWithFeaturesOff(mustBe(TestCase::class, $this, 'test case'), Features::NOTIFICATIONS);

    expect(featuresInEffect())->toBe([
        'control_panel' => true,
        'notifications' => false,
        'notification_view' => false,
        'confirmations' => true,
        'restrictions' => true,
    ])->and(config('statamic.forms.send_email_job'))->toBe(SendEmail::class);
});

it('turns confirmations off alone', function (): void {
    bootWithFeaturesOff(mustBe(TestCase::class, $this, 'test case'), Features::CONFIRMATIONS);

    expect(featuresInEffect())->toBe([
        'control_panel' => true,
        'notifications' => true,
        'notification_view' => true,
        'confirmations' => false,
        'restrictions' => true,
    ]);
});

it('turns restrictions off alone', function (): void {
    bootWithFeaturesOff(mustBe(TestCase::class, $this, 'test case'), Features::RESTRICTIONS);

    expect(featuresInEffect())->toBe([
        'control_panel' => true,
        'notifications' => true,
        'notification_view' => true,
        'confirmations' => true,
        'restrictions' => false,
    ]);
});

it('reports a restricted form open in the tag when restrictions are off', function (): void {
    // A saved form that requires login, which an anonymous visitor is refused
    $handle = 'features_tag_'.str_replace('.', '', uniqid('', true));
    $form = makeForm($handle);
    $form->set('require_login_query', true);
    $form->set('require_login_message', 'Log in first.');
    $form->save();

    // Read the state the tag hands the template
    $state = function () use ($handle): array {
        $tag = new FormRestriction;
        $tag->setProperties(['parser' => null, 'content' => '', 'context' => [], 'params' => ['handle' => $handle]]);

        return $tag->index();
    };

    try {
        // The tag reports the form closed with restrictions on
        expect($state()['state'])->toBe('login_required');

        bootWithFeaturesOff(mustBe(TestCase::class, $this, 'test case'), Features::RESTRICTIONS);

        // And open with them off
        expect($state())->toBe(['open' => true, 'state' => 'open', 'message' => '']);
    } finally {
        mustBe(Form::class, FormFacade::find($handle), 'form')->delete();
    }
});

it('keeps a notification job the host set itself', function (): void {
    // A job class of the host's own, set before the add-on boots
    $hostJob = 'App\\Jobs\\HostFormEmail';
    mustBe(TestCase::class, $this, 'test case')->rebootWithConfig(['statamic.forms.send_email_job' => $hostJob]);

    expect(config('statamic.forms.send_email_job'))->toBe($hostJob);
});

it('reads a switch set to 0 or 1, as an env value or a published config may be, as off or on', function (): void {
    config([
        'statamic-form-settings.features.'.Features::RESTRICTIONS => 0,
        'statamic-form-settings.features.'.Features::CONFIRMATIONS => '1',
    ]);

    expect(Features::enabled(Features::RESTRICTIONS))->toBeFalse()
        ->and(Features::enabled(Features::CONFIRMATIONS))->toBeTrue();
});

it("puts the add-on's email view after a host's own copy and before Statamic's", function (): void {
    // A host view path holding an override directory for Statamic's views
    $hostViews = sys_get_temp_dir().'/form-settings-host-views-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($hostViews.'/vendor/statamic', 0777, true);

    try {
        mustBe(TestCase::class, $this, 'test case')->rebootWithConfig(['view.paths' => [$hostViews]]);

        $hints = mustBeArray(mustBe(FileViewFinder::class, View::getFinder(), 'view finder')->getHints()['statamic'] ?? [], 'view hints');
        $paths = array_map(static fn (mixed $path): string|false => realpath(mustBeString($path, 'view hint')), $hints);

        // Statamic registers the namespace twice, so the host's copy can be listed twice
        $own = array_search(realpath(dirname(__DIR__, 2).'/resources/views'), $paths, true);
        $host = array_keys($paths, realpath($hostViews.'/vendor/statamic'), true);
        $statamic = array_keys($paths, realpath(dirname(__DIR__, 2).'/vendor/statamic/cms/resources/views'), true);

        expect($own)->toBeInt()
            ->and($host)->not->toBeEmpty()
            ->and($statamic)->not->toBeEmpty()
            ->and(max($host))->toBeLessThan($own)
            ->and(min($statamic))->toBeGreaterThan($own);
    } finally {
        rmdir($hostViews.'/vendor/statamic');
        rmdir($hostViews.'/vendor');
        rmdir($hostViews);
    }
});
