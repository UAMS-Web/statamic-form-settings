<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\CpForms;

use Illuminate\Support\Facades\Log;
use Statamic\Forms\Form;

/**
 * Orchestrates the add-on's customization of Statamic CP form-configuration
 * blueprints.
 *
 * Delegates email-grid augmentation to EmailGridBlueprintCustomizer and
 * tab/section injection to FormConfigurationTabsInjector. Statamic's route
 * binding and the add-on's ServiceProvider override resolve FormsController,
 * which auto-wires this class.
 */
final readonly class FormConfigurationBlueprintCustomizer
{
    /**
     * @param  EmailGridBlueprintCustomizer  $emailGridCustomizer  Augments the email grid fields.
     * @param  FormConfigurationTabsInjector  $tabsInjector  Injects description, submit, confirmation, and restrictions tabs.
     */
    public function __construct(
        private EmailGridBlueprintCustomizer $emailGridCustomizer,
        private FormConfigurationTabsInjector $tabsInjector,
    ) {}

    /**
     * Apply the add-on's tabs, fields, and instructions to Statamic blueprint
     * contents.
     *
     * A customizer that cannot find the section it extends leaves the contents
     * unchanged; that is logged as a warning naming the form, so a change to the
     * shape core emits does not disable the settings without a trace.
     *
     * @param  array<string, mixed>  $contents  Blueprint contents tree from Statamic; mutated in place.
     * @param  Form  $form  Form whose handle populates conditional field bindings.
     * @return array<string, mixed> Same contents array reference after in-place mutation.
     */
    public function customizeBlueprintContents(
        array $contents,
        Form $form
    ): array {
        // Augment the email grid and append merge-tag instructions
        if (! $this->emailGridCustomizer->customizeEmailGrid($contents, $form)) {
            // Record that the email grid was not found in the contents
            Log::warning('Form configuration email grid not customized: no email grid in the blueprint contents.', [
                'form' => $form->handle(),
            ]);
        }

        // Inject description, submit, confirmation, restrictions, and quiz sections
        if (! $this->tabsInjector->injectFormConfigurationTabs($contents, $form)) {
            // Record that the name section was not found in the contents
            Log::warning('Form configuration sections not injected: no name section in the blueprint contents.', [
                'form' => $form->handle(),
            ]);
        }

        // Return the mutated contents tree for the caller to wrap in a Blueprint
        return $contents;
    }
}
