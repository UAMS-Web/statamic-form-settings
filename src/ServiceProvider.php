<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\View;
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

            View::prependNamespace('statamic', __DIR__.'/../resources/views');
        }

        // Answer a submission with its confirmation message or redirect
        if (Features::enabled(Features::CONFIRMATIONS)) {
            $this->app->make(Router::class)->pushMiddlewareToGroup('web', ApplyFormConfirmation::class);
        }
    }
}
