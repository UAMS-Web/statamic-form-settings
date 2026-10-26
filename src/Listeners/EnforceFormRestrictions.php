<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Listeners;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Statamic\Events\FormSubmitted;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Features;
use UAMSWeb\FormSettings\Support\FormRestrictions;

/**
 * Rejects a form submission server-side when the form's restrictions block it (UAMS-Web/uams-statamic#205).
 *
 * `Statamic\Events\FormSubmitted` fires inside `FormController::submit()` before the submission
 * is stored or emails dispatched, and the controller wraps that dispatch in a `try/catch` for
 * `ValidationException` (its own comment: "If they want to add validation errors, they can throw
 * an exception"). So throwing here rejects the POST and surfaces the restriction's closed-state
 * message through the form's normal error display, enforcing the entry limit / schedule window /
 * require-login even against a direct POST that bypassed the rendered form.
 *
 * The decision is delegated to {@see FormRestrictions}, the same source of truth the render-time
 * `{{ form_restriction }}` tag uses, so the gate and the UI never disagree. Registered in the
 * service provider's `register()`, so it runs before the host application's own `FormSubmitted`
 * listeners and a restricted form reports its restriction first. With the `restrictions` feature
 * switched off ({@see Features}) it refuses nothing.
 */
final class EnforceFormRestrictions
{
    /**
     * Reject the submission when a restriction blocks the form.
     *
     * @param  FormSubmitted  $event  The form-submitted event, carrying the submission.
     *
     * @throws ValidationException When a restriction blocks the submission.
     */
    public function handle(FormSubmitted $event): void
    {
        // With restrictions switched off, no submission is refused
        if (! Features::enabled(Features::RESTRICTIONS)) {
            return;
        }

        $form = $event->submission->form();

        if (! $form instanceof Form) {
            return;
        }

        $restriction = (new FormRestrictions)->restriction($form, Auth::check());

        if ($restriction === null) {
            return;
        }

        $handle = $form->handle();

        throw ValidationException::withMessages([
            (is_scalar($handle) ? (string) $handle : 'form') => [$restriction['message']],
        ]);
    }
}
