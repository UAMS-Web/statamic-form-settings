<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Contracts;

use Statamic\Forms\Form;

/**
 * A section another package adds to the Control Panel's form settings screen.
 *
 * Tag an implementation with {@see self::TAG} in a service provider's
 * `register()`, and the add-on injects its section after its own, in the order
 * the tags were registered:
 *
 *     $this->app->tag([QuizGradingSection::class], FormSettingsSection::TAG);
 *
 * The section's values save with the form like every other setting, under the
 * handles its fields declare.
 */
interface FormSettingsSection
{
    /**
     * The container tag implementations are registered under.
     */
    public const string TAG = 'statamic-form-settings.sections';

    /**
     * The section to inject, keyed by a name, in the shape the add-on's own
     * sections use: a `display` label and its fields, each field keyed by its
     * handle and given as `['handle' => ..., 'field' => [...]]`.
     *
     * @param  Form  $form  The form whose settings are being edited.
     * @return array<string, array{display: string, fields: array<array-key, mixed>}> The section, keyed by name.
     */
    public function section(Form $form): array;
}
