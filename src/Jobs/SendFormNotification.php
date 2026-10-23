<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Jobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Statamic\Contracts\Forms\Submission;
use Statamic\Forms\SendEmail;
use Statamic\Sites\Site;
use UAMSWeb\FormSettings\Contracts\SubmissionContext;
use UAMSWeb\FormSettings\Features;
use UAMSWeb\FormSettings\Mail\FormNotificationEmail;
use UAMSWeb\FormSettings\Support\ConditionalLogicEvaluator;
use UAMSWeb\FormSettings\Support\RecipientResolver;

/**
 * Form send-email job that dispatches the project's `from_name`-aware mailable and
 * carries the submitter's request context across the queue boundary.
 *
 * Statamic's base {@see SendEmail} job hardcodes its own {@see Email}
 * mailable, which ignores the email config's `from_name` field. This subclass swaps
 * in {@see FormNotificationEmail} so the resolved display name reaches the `From:`
 * header while leaving the rest of the dispatch behavior (mailer selection, queue
 * semantics, constructor signature) untouched.
 *
 * It also closes the async-rendering gap. The four request-context merge tags read
 * live request state at render time, which is only the submitter's while the email
 * renders inside the submit request (the `sync` connection). To stay correct on a
 * real queue, this job asks the bound {@see SubmissionContext} for the context captured
 * for this submission in its constructor (which runs in the live submit request, before
 * serialization) and stores it as a plain array that serializes onto the queued job. At
 * render time it hands that array back to the same binding around `Mail::send()`, so the
 * tags prefer the stored submitter values over the worker's empty/default state. The
 * context never touches the submission record, so it cannot leak into `{{ all_fields }}`.
 * The add-on's default binding captures nothing; the host binds the implementation that
 * does.
 *
 * Finally, it gates and addresses each notification independently: an inactive notification
 * (the `enabled` "Active" toggle set false, UAMS-Web/uams-statamic#204) is skipped first, then {@see ConditionalLogicEvaluator}
 * evaluates the config's `conditional_logic` against the submitted values (UAMS-Web/uams-statamic#202) and the job returns
 * without sending when they fail. For the survivors, {@see RecipientResolver} resolves TO/CC/BCC/Reply-To
 * from each kind's configured source (literal address, submitted field, or conditional routing, UAMS-Web/uams-statamic#201),
 * skipping a notification that resolves no TO recipient. So a form's notifications send independently.
 *
 * Wired in via the `statamic.forms.send_email_job` config key. Settles issues UAMS-Web/uams-statamic#213, UAMS-Web/uams-statamic#830, UAMS-Web/uams-statamic#202, UAMS-Web/uams-statamic#204, UAMS-Web/uams-statamic#201.
 * With the `notifications` feature switched off ({@see Features}) it defers to the base job
 * entirely, so the config key can keep naming this class.
 */
final class SendFormNotification extends SendEmail
{
    /**
     * The submitter's captured request context, as a queue-serializable array.
     *
     * Populated in the constructor (in the live submit request) and serialized onto
     * the job, so a worker rendering the email later still has the submitter's ip,
     * referrer, query parameters, and origin site. `null` when no context was
     * captured (e.g. a job dispatched outside the form-submit flow).
     *
     * @var array<string, mixed>|null
     */
    public ?array $requestContext;

    /**
     * Build the job and claim the request context captured for this submission.
     *
     * @param  Submission  $submission  The form submission to notify about.
     * @param  Site  $site  The site that handled the submission.
     * @param  mixed  $config  The resolved email-notification config.
     */
    public function __construct(Submission $submission, Site $site, $config)
    {
        // Preserve the parent's submission/site/config wiring and queue semantics
        parent::__construct($submission, $site, $config);

        // Claim the context captured for this submission, as a plain array so it
        // serializes cleanly onto the queued job; with notifications switched off the
        // job sends as Statamic's does and needs none
        $this->requestContext = Features::enabled(Features::NOTIFICATIONS)
            ? app(SubmissionContext::class)->capture($submission)
            : null;
    }

    /**
     * Render and send the notification using the `from_name`-aware mailable.
     */
    #[\Override]
    public function handle(): void
    {
        // With notifications switched off, send the way Statamic's own job does
        if (! Features::enabled(Features::NOTIFICATIONS)) {
            parent::handle();

            return;
        }

        /** @var array<string, mixed> $config */
        $config = $this->config;
        /** @var Submission $submission */
        $submission = $this->submission;
        /** @var Site $site */
        $site = $this->site;

        // UAMS-Web/uams-statamic#204: honor the per-notification "Active" toggle. An inactive notification is
        // skipped before any conditional-logic expressions run (so it does no needless
        // work), with a short debug note naming the form and notification.
        if (! $this->notificationIsActive($config)) {
            Log::debug(sprintf(
                'Form notification "%s" on form [%s] active=false; skipped.',
                $this->notificationName($config),
                $submission->form()->handle(),
            ));

            return;
        }

        // The submitted field values drive both the conditional gate and recipient resolution.
        /** @var array<string, mixed> $values */
        $values = $submission->data()->all();

        // UAMS-Web/uams-statamic#202: gate this notification on its conditional logic. When the notification's
        // conditions don't pass against the submitted values, skip its send entirely:
        // each notification is gated independently (the chain's other jobs still run).
        if (! (new ConditionalLogicEvaluator)->passes($config['conditional_logic'] ?? null, $values)) {
            return;
        }

        // UAMS-Web/uams-statamic#201: resolve TO/CC/BCC/Reply-To from each kind's configured source (a literal
        // address, a submitted field, or conditional routing) into concrete addresses.
        // TO is required: with nothing resolved, skip rather than send a recipient-less email.
        // CC/BCC/Reply-To are optional and dropped when empty so no blank header is built.
        $resolver = new RecipientResolver;
        $resolvedTo = $resolver->resolve($config, $values, 'to');
        $config['to'] = $resolvedTo;

        if (trim($resolvedTo) === '') {
            Log::debug(sprintf(
                'Form notification "%s" on form [%s] resolved no TO recipient; skipped.',
                $this->notificationName($config),
                $submission->form()->handle(),
            ));

            return;
        }

        foreach (['cc', 'bcc', 'reply_to'] as $recipientKind) {
            $resolved = $resolver->resolve($config, $values, $recipientKind);

            if (trim($resolved) === '') {
                unset($config[$recipientKind]);
            } else {
                $config[$recipientKind] = $resolved;
            }
        }

        $mailer = $config['mailer'] ?? null;
        $mailer = is_string($mailer) ? $mailer : null;

        // Build the from_name-aware mailable for the configured mailer
        $send = fn (): mixed => Mail::mailer($mailer)
            ->send(new FormNotificationEmail($submission, $config, $site));

        // Without a captured context (e.g. sync render in the live request), send
        // directly so the request-context tags read live request state as before
        if ($this->requestContext === null) {
            $send();

            return;
        }

        // With a captured context (queue-worker render), activate the submitter's
        // values for the duration of the render so the tags prefer them over live state.
        // build(), and both its Antlers contexts (parseConfig subject/addresses and the
        // body view), runs inside send(), so this single wrap covers both.
        app(SubmissionContext::class)->during($this->requestContext, $send);
    }

    /**
     * Whether this notification's "Active" toggle (`enabled`) permits sending.
     *
     * Absent (the default) is active; only an explicitly falsy value
     * (boolean false, or a string/number like 'false', '0', 'no', 'off') deactivates
     * it, covering both the CP toggle's boolean and an imported string representation.
     *
     * @param  array<string, mixed>  $config  The notification config.
     */
    private function notificationIsActive(array $config): bool
    {
        $enabled = $config['enabled'] ?? true;

        if (\is_string($enabled)) {
            return ! \in_array(strtolower(trim($enabled)), ['false', '0', 'no', 'off', ''], true);
        }

        return (bool) $enabled;
    }

    /**
     * A human-readable name for this notification for the skip log line.
     *
     * @param  array<string, mixed>  $config  The notification config.
     */
    private function notificationName(array $config): string
    {
        $name = $config['name'] ?? null;

        return \is_scalar($name) && (string) $name !== '' ? (string) $name : 'unnamed';
    }
}
