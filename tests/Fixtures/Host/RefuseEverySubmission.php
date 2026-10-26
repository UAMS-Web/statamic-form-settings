<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Tests\Fixtures\Host;

use Illuminate\Validation\ValidationException;
use Statamic\Events\FormSubmitted;

/**
 * A host application's own `FormSubmitted` listener, which refuses every submission.
 *
 * It stands in for a host check such as a site's choice submission limits, so a
 * test can tell which listener refused a submission first.
 */
final class RefuseEverySubmission
{
    /**
     * The message this listener refuses a submission with.
     */
    public const string MESSAGE = 'The host refused this submission.';

    /**
     * Refuse the submission.
     *
     * @param  FormSubmitted  $event  The form-submitted event, carrying the submission.
     *
     * @throws ValidationException Always.
     */
    public function handle(FormSubmitted $event): never
    {
        throw ValidationException::withMessages([
            $event->submission->form()->handle() => [self::MESSAGE],
        ]);
    }
}
