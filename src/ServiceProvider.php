<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\View;
use Illuminate\View\FileViewFinder;
use Statamic\Events\FormSubmitted;
use Statamic\Forms\SendEmail;
use Statamic\Http\Controllers\CP\Forms\FormsController as BaseFormsController;
use Statamic\Providers\AddonServiceProvider;
use UAMSWeb\FormSettings\Contracts\SubmissionContext;
use UAMSWeb\FormSettings\Http\Controllers\FormsController;
use UAMSWeb\FormSettings\Http\Middleware\ApplyFormConfirmation;
use UAMSWeb\FormSettings\Jobs\SendFormNotification;
use UAMSWeb\FormSettings\Listeners\EnforceFormRestrictions;
use UAMSWeb\FormSettings\Support\NullSubmissionContext;

/**
 * Service provider for the form settings add-on.
 *
 * The host application discovers it through `extra.laravel.providers` in
 * `composer.json`. The fieldtypes and the `form_restriction` tag register
 * through Statamic's discovery of `src/Fieldtypes` and `src/Tags`, and the
 * restrictions listener in {@see register()}, always, so a stored blueprint
 * or template that names them keeps working with any feature switched off;
 * the tag and the listener check the `restrictions` switch themselves.
 * Everything else registers in {@see bootAddon()}, once the add-on's config
 * is merged, and only when its feature is on ({@see Features}).
 */
class ServiceProvider extends AddonServiceProvider
{
    /**
     * The Vite build of the Control Panel assets. `input` and `publicDirectory`
     * must match `vite.config.js`; the built files are committed under
     * `resources/dist`.
     *
     * @var array{input: list<string>, publicDirectory: string}
     */
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    /**
     * Bind the default submission context, which a host's own binding replaces,
     * and listen for form submissions ahead of the host.
     *
     * Laravel calls the listeners for an event in the order they were registered,
     * and registers a package's providers before a host's own, so a listener
     * registered here runs before any `FormSubmitted` listener the host
     * registers. Statamic's discovery of `src/Listeners` registers its listeners
     * once every provider has booted, after the host's, so a restricted form
     * would otherwise report a host listener's refusal instead of its own (#25).
     */
    #[\Override]
    public function register(): void
    {
        // Capture nothing unless the host binds a context of its own
        $this->app->bindIf(SubmissionContext::class, NullSubmissionContext::class);

        // Refuse a restricted submission before the host's own listeners see it
        $this->app->make(Dispatcher::class)->listen(FormSubmitted::class, EnforceFormRestrictions::class);
    }

    /**
     * Register no listeners from Statamic's discovery of `src/Listeners`.
     *
     * The add-on's one listener is registered in {@see register()}, so that it
     * runs ahead of the host's; discovering it as well would register it twice.
     * A listener added to `src/Listeners` is registered in {@see register()} too.
     */
    #[\Override]
    public function bootEvents(): static
    {
        return $this;
    }

    /**
     * Register each feature that is switched on, now that the config is merged.
     */
    #[\Override]
    public function bootAddon(): void
    {
        // Replace Statamic's form settings controller with the one that adds the sections
        if (Features::enabled(Features::CONTROL_PANEL)) {
            $this->app->bind(BaseFormsController::class, FormsController::class);
        }

        // Send notifications through the add-on's job, and render them with its body view
        if (Features::enabled(Features::NOTIFICATIONS)) {
            // Leave a host that names a job of its own alone
            if (config('statamic.forms.send_email_job') === SendEmail::class) {
                config(['statamic.forms.send_email_job' => SendFormNotification::class]);
            }

            $this->addStatamicViewsAfterHostOverrides(__DIR__.'/../resources/views');
        }

        // Answer a submission with its confirmation message or redirect
        if (Features::enabled(Features::CONFIRMATIONS)) {
            $this->app->make(Router::class)->pushMiddlewareToGroup('web', ApplyFormConfirmation::class);
        }
    }

    /**
     * Add a path to the `statamic` view namespace, after a host's overrides of it.
     *
     * Laravel lists a host's own `resources/views/vendor/statamic` ahead of Statamic's
     * views, which is how a host overrides a package view. The add-on's views go
     * between the two, so they replace Statamic's and a host's copy still wins.
     *
     * @param  string  $path  The add-on's view directory.
     */
    private function addStatamicViewsAfterHostOverrides(string $path): void
    {
        // Read the namespace's current paths
        $finder = View::getFinder();
        // Laravel's view factory always holds a FileViewFinder, so the guard is for PHPStan and the
        // other branch cannot be reached from a test.
        // @pest-mutate-ignore: InstanceOfToTrue
        $hints = $finder instanceof FileViewFinder ? $finder->getHints()['statamic'] ?? [] : [];

        // The override directories Laravel looks for under each of the host's view paths
        $viewPaths = config('view.paths');
        $overrides = array_map(
            // A view path that is not a string names no directory, so any text in its place matches no hint.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            static fn (mixed $viewPath): string => (\is_string($viewPath) ? $viewPath : '').'/vendor/statamic',
            \is_array($viewPaths) ? $viewPaths : [],
        );

        // Keep the host's overrides first, then the add-on's views, then the rest
        $first = [];
        $rest = [];
        foreach ($hints as $hint) {
            // Hints and overrides are both directory paths, which compare the same loosely or strictly.
            // @pest-mutate-ignore: TrueToFalse
            if (\in_array($hint, $overrides, true)) {
                $first[] = $hint;
            } else {
                $rest[] = $hint;
            }
        }

        View::replaceNamespace('statamic', [...$first, $path, ...$rest]);
    }
}
