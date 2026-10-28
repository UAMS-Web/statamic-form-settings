<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Statamic\Contracts\Forms\Submission;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Listeners\EnforceFormRestrictions;

/**
 * Evaluates a form's custom restrictions (entry limit, schedule window, and require-login)
 * against the current state (UAMS-Web/uams-statamic-archive#205).
 *
 * The restriction config (added to the form blueprint to receive imported Gravity Forms /
 * Formidable settings) lives on the form: `require_login_query`, `schedule_form_query` +
 * `schedule_form` ({start_datetime, end_datetime}), and `limit_entries` + `limit_entries_count`
 * / `limit_entries_period` / `limit_entries_message`, each with its own closed-state message.
 *
 * {@see self::restriction()} returns the first restriction that blocks the form (state + message),
 * or `null` when the form is open. It is the single source of truth shared by the submit-time
 * gate ({@see EnforceFormRestrictions}, which rejects a violating POST server-side)
 * and the render-time state (the `{{ form_restriction }}` tag), so the two never disagree.
 *
 * Accepted gap: the entry-limit gate is a best-effort read-then-act on the flat-file submission
 * count, not an atomic counter: two simultaneous submissions can each observe a sub-limit count
 * and both store, slightly overshooting the cap. For these public marketing-form limits (no seats
 * or money at stake) the race is accepted rather than serialized behind a lock.
 */
final class FormRestrictions
{
    /**
     * The restriction blocking the form, or null when it is open.
     *
     * Checked in order: require-login, schedule window, then entry limit; the first violated
     * one wins, so its message is the one surfaced.
     *
     * @param  Form  $form  The form whose restrictions to evaluate.
     * @param  bool  $authenticated  Whether the current visitor is logged in.
     * @param  CarbonInterface|null  $now  The reference time (defaults to now); injectable for tests.
     * @return array{state: string, message: string}|null
     */
    public function restriction(Form $form, bool $authenticated, ?CarbonInterface $now = null): ?array
    {
        $now ??= CarbonImmutable::now();

        if ($this->enabled($form->get('require_login_query')) && ! $authenticated) {
            return $this->blocked('login_required', $form->get('require_login_message'));
        }

        if ($this->enabled($form->get('schedule_form_query'))) {
            $schedule = $form->get('schedule_form');
            $schedule = \is_array($schedule) ? $schedule : [];

            try {
                $start = $this->parse($schedule['start_datetime'] ?? null);
                $end = $this->parse($schedule['end_datetime'] ?? null);
            } catch (InvalidFormatException) {
                // A date that cannot be read closes the form, so a broken end date never leaves it open
                return $this->blocked('schedule_unreadable', null);
            }

            if ($start instanceof CarbonInterface && $now->lessThan($start)) {
                return $this->blocked('pending', $form->get('schedule_pending_message'));
            }

            if ($end instanceof CarbonInterface && $now->greaterThan($end)) {
                return $this->blocked('expired', $form->get('schedule_expired_message'));
            }
        }

        if ($this->enabled($form->get('limit_entries'))) {
            $limit = $this->intValue($form->get('limit_entries_count'));
            $period = \is_string($p = $form->get('limit_entries_period')) ? $p : 'total';

            if ($limit > 0 && $this->submissionCount($form, $period, $now) >= $limit) {
                return $this->blocked('limit_reached', $form->get('limit_entries_message'));
            }
        }

        return null;
    }

    /**
     * Build a blocked-state result with a fallback message.
     *
     * @return array{state: string, message: string}
     */
    private function blocked(string $state, mixed $message): array
    {
        $resolved = $this->stringValue($message);

        return [
            'state' => $state,
            'message' => $resolved !== '' ? $resolved : __('This form is not currently accepting submissions.'),
        ];
    }

    /**
     * Count a form's submissions within the limit period.
     *
     * `total` counts every submission; the day/week/month/year periods count from the start of the
     * current calendar period (matching Gravity Forms / Formidable, which reset on the calendar
     * boundary (a "per month" limit clears when a new month begins, not on a rolling 30-day window).
     */
    private function submissionCount(Form $form, string $period, CarbonInterface $now): int
    {
        $submissions = $form->submissions();

        $windowStart = match ($period) {
            'day' => $now->startOfDay(),
            'week' => $now->startOfWeek(),
            'month' => $now->startOfMonth(),
            'year' => $now->startOfYear(),
            default => null,
        };

        // `total` has no window, and an unknown period falls back to the total count rather than
        // silently allowing past it.
        if ($windowStart === null) {
            return $submissions->count();
        }

        return $submissions
            ->filter(function ($submission) use ($windowStart): bool {
                if (! $submission instanceof Submission) {
                    return false;
                }

                $date = $submission->date();

                // Compare on the cleanly-typed $windowStart ($date >= $windowStart): the
                // submission's date() carries an unqualified-Carbon PHPDoc that doesn't resolve.
                // Statamic's `Submission::date()` always returns a Carbon instance, so the check only satisfies PHPStan.
                // @pest-mutate-ignore: InstanceOfToTrue
                return $date instanceof CarbonInterface && $windowStart->lessThanOrEqualTo($date);
            })
            ->count();
    }

    /**
     * Whether a toggle config value is truthy (boolean true, or an imported 'true'/'1'/'yes'/'on').
     */
    private function enabled(mixed $value): bool
    {
        if (\is_string($value)) {
            return \in_array(strtolower(trim($value)), ['true', '1', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Coerce a config value to a non-negative integer (0 when not numeric).
     */
    private function intValue(mixed $value): int
    {
        // The caller enforces only `$limit > 0`, so any value at or below zero means no limit and lowering either zero changes nothing.
        // @pest-mutate-ignore: DecrementInteger
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Coerce a scalar config value to a trimmed string ('' when not scalar).
     */
    private function stringValue(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Parse a stored datetime value into a Carbon instance, or null when absent or blank.
     *
     * @throws InvalidFormatException When a non-blank value cannot be read as a date.
     */
    private function parse(mixed $value): ?CarbonInterface
    {
        if (! \is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
