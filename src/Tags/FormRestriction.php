<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Tags;

use Illuminate\Support\Facades\Auth;
use Statamic\Facades\Form;
use Statamic\Forms\Form as FormInstance;
use Statamic\Tags\Parameters;
use Statamic\Tags\Tags;
use UAMSWeb\FormSettings\Features;
use UAMSWeb\FormSettings\Listeners\EnforceFormRestrictions;
use UAMSWeb\FormSettings\Support\FormRestrictions;

/**
 * Exposes a form's current restriction state to templates so the rendered page can show the
 * closed/login/pending/expired state instead of the form (UAMS-Web/uams-statamic-archive#205).
 *
 * The submit path is gated server-side by {@see EnforceFormRestrictions}; this
 * tag is the render-time counterpart, sharing the same {@see FormRestrictions} source of truth so
 * the UI and the gate never disagree. A form with no active restriction reports `open => true`.
 *
 * Usage:
 *
 *     {{ form_restriction handle="contact" }}
 *         {{ if open }}
 *             {{ form:contact }} … {{ /form:contact }}
 *         {{ else }}
 *             <p class="form-closed">{{ message }}</p>
 *         {{ /if }}
 *     {{ /form_restriction }}
 *
 * Exposed variables: `open` (bool), `state` (open|login_required|pending|expired|schedule_unreadable|limit_reached),
 * and `message` (the configured closed-state message, '' when open).
 *
 * With the `restrictions` feature switched off ({@see Features}) nothing refuses a
 * submission, so the tag reports every form open.
 */
class FormRestriction extends Tags
{
    /**
     * Resolve the restriction state for the requested form.
     *
     * @return array{open: bool, state: string, message: string}
     */
    public function index(): array
    {
        /** @var Parameters $params */
        $params = $this->params;
        $handle = $params->get('handle') ?? $params->get('is') ?? $params->get('in');
        $handle = is_scalar($handle) ? (string) $handle : '';

        // With restrictions switched off, no form is closed
        if (! Features::enabled(Features::RESTRICTIONS)) {
            return $this->open();
        }

        $form = $handle !== '' ? Form::find($handle) : null;

        if (! $form instanceof FormInstance) {
            return $this->open();
        }

        $restriction = (new FormRestrictions)->restriction($form, Auth::check());

        if ($restriction === null) {
            return $this->open();
        }

        return [
            'open' => false,
            'state' => $restriction['state'],
            'message' => $restriction['message'],
        ];
    }

    /**
     * The open (unrestricted) state.
     *
     * @return array{open: bool, state: string, message: string}
     */
    private function open(): array
    {
        return [
            'open' => true,
            'state' => 'open',
            'message' => '',
        ];
    }
}
