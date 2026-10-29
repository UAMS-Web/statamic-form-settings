<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings;

/**
 * Reads the per-feature switches in `config/statamic-form-settings.php`.
 *
 * A switch that is missing from the config counts as on, matching the file's
 * defaults, so a host that publishes an older copy of the config, or none at
 * all, keeps every feature.
 */
final class Features
{
    /**
     * The form settings screen and the fieldtypes it uses.
     */
    public const string CONTROL_PANEL = 'control_panel';

    /**
     * The notification job and its email.
     */
    public const string NOTIFICATIONS = 'notifications';

    /**
     * The message or redirect after a submission.
     */
    public const string CONFIRMATIONS = 'confirmations';

    /**
     * Entry limits, the form schedule and required login.
     */
    public const string RESTRICTIONS = 'restrictions';

    /**
     * Whether a feature is switched on.
     *
     * @param  self::*  $feature  One of this class's constants.
     * @return bool True unless the config switches the feature off.
     */
    public static function enabled(string $feature): bool
    {
        return (bool) config("statamic-form-settings.features.{$feature}", true);
    }

    /**
     * Whether quiz and survey scoring is switched on.
     *
     * Read from the `quiz_scoring` key rather than `features`, which it predates. Like the
     * feature switches, a missing key counts as on, so a host on a copy of the config from
     * before the switch existed keeps the grading section it always had.
     *
     * @return bool True unless the config switches scoring off.
     */
    public static function quizScoring(): bool
    {
        return (bool) config('statamic-form-settings.quiz_scoring', true);
    }
}
