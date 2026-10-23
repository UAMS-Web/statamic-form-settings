<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Support;

use Statamic\Contracts\Forms\Submission;
use UAMSWeb\FormSettings\Contracts\SubmissionContext;

/**
 * The add-on's default submission context: it captures nothing, so a
 * notification sends with whatever request state is live at the time.
 */
final class NullSubmissionContext implements SubmissionContext
{
    /**
     * Capture nothing.
     *
     * @param  Submission  $submission  The submission the notification is about.
     * @return array<string, mixed>|null Always null, since there is no context to carry.
     */
    public function capture(Submission $submission): ?array
    {
        return null;
    }

    /**
     * Run the send as it is.
     *
     * @param  array<string, mixed>  $context  Ignored.
     * @param  callable(): mixed  $send  Sends the email.
     * @return mixed What the send returned.
     */
    public function during(array $context, callable $send): mixed
    {
        return $send();
    }
}
