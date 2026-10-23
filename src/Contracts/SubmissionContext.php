<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Contracts;

use Statamic\Contracts\Forms\Submission;
use UAMSWeb\FormSettings\Support\NullSubmissionContext;

/**
 * Carries the submitter's request context across the queue boundary for a
 * notification email.
 *
 * The notification job asks for the context in its constructor, which runs in
 * the live submit request, and stores what it gets back on the queued job. When
 * a queue worker later sends the email, the job hands that array back so the
 * host can make it active for the duration of the send: tags that read the
 * request (the submitter's IP address, referrer, query string or site) then see
 * the submitter's values instead of the worker's empty ones.
 *
 * The add-on binds {@see NullSubmissionContext},
 * which captures nothing, so it works on its own. A host that captures request
 * context binds its own implementation.
 */
interface SubmissionContext
{
    /**
     * Claim the context captured for a submission, as an array that survives
     * queue serialization.
     *
     * @param  Submission  $submission  The submission the notification is about.
     * @return array<string, mixed>|null The context, or null when none was captured.
     */
    public function capture(Submission $submission): ?array;

    /**
     * Run the send with a captured context active.
     *
     * @param  array<string, mixed>  $context  The array {@see capture()} returned.
     * @param  callable(): mixed  $send  Sends the email.
     * @return mixed What the send returned.
     */
    public function during(array $context, callable $send): mixed;
}
