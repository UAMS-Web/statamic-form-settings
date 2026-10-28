<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Support;

use Statamic\Forms\Form;

/**
 * Selects which confirmation a form submission should produce (UAMS-Web/uams-statamic-archive#203).
 *
 * A form carries a default confirmation (`confirmation_settings_default`) plus an ordered list of
 * additional confirmations (`confirmation_additional`), each a `{confirmation_settings, conditional_logic}`
 * set. The first additional confirmation whose conditional logic passes against the submitted values
 * wins (reusing {@see ConditionalLogicEvaluator}, the UAMS-Web/uams-statamic-archive#202 engine); otherwise the default applies.
 *
 * {@see self::resolve()} returns the chosen confirmation-settings array (`{confirmation_type,
 * confirmation_message, confirmation_redirect_url, confirmation_page, confirmation_query_string, …}`)
 * or `null` when the form has no confirmation config, in which case the caller leaves Statamic's
 * default post-submit behavior untouched.
 */
final class FormConfirmationResolver
{
    public function __construct(
        private readonly ConditionalLogicEvaluator $evaluator = new ConditionalLogicEvaluator,
    ) {}

    /**
     * Resolve the confirmation settings to apply for a submission, or null for default behavior.
     *
     * @param  Form  $form  The submitted form.
     * @param  array<string, mixed>  $values  The submitted field values, keyed by handle.
     * @return array<string, mixed>|null
     */
    public function resolve(Form $form, array $values): ?array
    {
        $additional = $form->get('confirmation_additional');

        if (\is_array($additional)) {
            foreach ($additional as $set) {
                if (! \is_array($set)) {
                    continue;
                }

                if (! $this->evaluator->passes($set['conditional_logic'] ?? null, $values)) {
                    continue;
                }

                $settings = $set['confirmation_settings'] ?? null;
                if (\is_array($settings) && $settings !== []) {
                    /** @var array<string, mixed> $settings */
                    return $settings;
                }
            }
        }

        $default = $form->get('confirmation_settings_default');

        if (\is_array($default) && $default !== []) {
            /** @var array<string, mixed> $default */
            return $default;
        }

        return null;
    }
}
