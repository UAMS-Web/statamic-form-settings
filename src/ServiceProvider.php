<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\View;
use Illuminate\View\FileViewFinder;
use Statamic\Forms\SendEmail;
use Statamic\Http\Controllers\CP\Forms\FormsController as BaseFormsController;
use Statamic\Providers\AddonServiceProvider;
use UAMSWeb\FormSettings\Contracts\SubmissionContext;
use UAMSWeb\FormSettings\Http\Controllers\FormsController;
use UAMSWeb\FormSettings\Http\Middleware\ApplyFormConfirmation;
use UAMSWeb\FormSettings\Jobs\SendFormNotification;
use UAMSWeb\FormSettings\Support\NullSubmissionContext;

/**
 * Service provider for the form settings add-on.
 *
 * The host application discovers it through `extra.laravel.providers` in
 * `composer.json`. The fieldtypes, the `form_restriction` tag and the
 * restrictions listener register through Statamic's discovery of
 * `src/Fieldtypes`, `src/Tags` and `src/Listeners`, always, so a stored
 * blueprint or template that names them keeps working with any feature
 * switched off; the tag and the listener check the `restrictions` switch
 * themselves. Everything else registers in {@see bootAddon()}, once the
 * add-on's config is merged, and only when its feature is on
 * ({@see Features}).
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
     * Bind the default submission context, which a host's own binding replaces.
     */
    #[\Override]
    public function register(): void
    {
        // Capture nothing unless the host binds a context of its own
        $this->app->bindIf(SubmissionContext::class, NullSubmissionContext::class);
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
