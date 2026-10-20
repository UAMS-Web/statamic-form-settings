<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Fields;
use Statamic\Forms\Form;
use Statamic\Http\Controllers\CP\Forms\FormsController as BaseFormsController;
use UAMSWeb\FormSettings\CpForms\FormConfigurationBlueprintCustomizer;
use UAMSWeb\FormSettings\CpForms\FormConfigurationValuesFilter;

/**
 * Control panel extension of Statamic's form controller, registered against the vendor class in
 * FormEmailFieldsServiceProvider.
 *
 * Statamic keeps routing and inherited actions (listing, submissions, exports, etc.). This type
 * only adjusts blueprint editing and update persistence so UAMS-specific CP fields and save
 * filtering stay out of vendor packages.
 */
class FormsController extends BaseFormsController
{
    /**
     * Create the controller with Statamic's request dependency and UAMS form-configuration services.
     *
     * @param  Request  $request  Incoming HTTP request passed to Statamic's base controller.
     * @param  FormConfigurationBlueprintCustomizer  $blueprintCustomizer  Injects CP blueprint tabs and fields.
     * @param  FormConfigurationValuesFilter  $valuesFilter  Removes values hidden by blueprint conditions before save.
     */
    public function __construct(
        Request $request,
        private readonly FormConfigurationBlueprintCustomizer $blueprintCustomizer,
        private readonly FormConfigurationValuesFilter $valuesFilter,
    ) {
        // Initialize Statamic's base controller state from the current request
        parent::__construct($request);
    }

    /**
     * Resolve Statamic's stock form configuration blueprint (tests may override this hook).
     *
     * @param  Form  $form  Form whose configuration blueprint should load.
     * @return Blueprint Statamic default blueprint before UAMS customization.
     */
    protected function resolveStatamicFormConfigurationBlueprint(
        Form $form
    ): Blueprint {
        // Delegate to Statamic for the canonical form settings blueprint
        /** @var Blueprint $blueprint */
        $blueprint = parent::editFormBlueprint($form);

        return $blueprint;
    }

    /**
     * Build the editable form configuration blueprint shown in the CP, including UAMS extensions.
     *
     * @param  Form  $form  Form being configured in the control panel.
     * @return Blueprint Blueprint instance whose contents include injected tabs and fields.
     */
    #[\Override]
    protected function editFormBlueprint(
        $form
    ): Blueprint {
        // Load Statamic's base blueprint for this form handle
        $blueprint = $this->resolveStatamicFormConfigurationBlueprint($form);

        // Extract mutable tab and field definitions from the blueprint
        /** @var array<string, mixed> $contents */
        $contents = $blueprint->contents();

        // Apply UAMS-specific tabs, fields, and instructional copy
        $contents = $this->blueprintCustomizer->customizeBlueprintContents(
            $contents,
            $form
        );

        // Re-wrap the array into a Blueprint object for Statamic's field API
        /** @var Blueprint $blueprint */
        $blueprint = BlueprintFacade::makeFromFields([]);

        /** @var Blueprint $configured */
        $configured = $blueprint->setContents($contents);

        return $configured;
    }

    /**
     * Save CP form configuration while skipping processed values for fields hidden by conditions.
     *
     * Validates the submitted values against the blueprint first, as Statamic's own `update()`
     * does, so a failing field `validate` rule returns a validation error and writes nothing.
     *
     * @param  Form  $form  Form model being updated.
     * @param  Request  $request  Request carrying flattened blueprint configuration values.
     *
     * @throws ValidationException When a submitted value fails a field's validation rules.
     */
    #[\Override]
    public function update(
        $form,
        Request $request
    ): void {
        // Enforce Statamic authorization for editing this form
        $this->authorize(
            'edit',
            $form
        );

        // Hydrate blueprint fields with submitted values for validation and processing
        /** @var Fields $fields */
        $fields = $this->editFormBlueprint($form)->fields()->addValues($request->all());

        // Validate the submitted values against every field rule, including fields
        // appended through Form::appendConfigFields, before anything is written
        $fields->validate();

        // Run fieldtype processors to normalize values prior to filtering
        /** @var Fields $processedFields */
        $processedFields = $fields->process();

        /** @var Collection<string, mixed> $processedValues */
        $processedValues = $processedFields->values();

        /** @var array<string, mixed> $values */
        $values = $processedValues->all();

        // Strip keys that should not persist when their visibility rules fail
        $filteredValues = $this->valuesFilter->filterFieldsByConditions(
            $values,
            $fields
        );

        // Collect auxiliary keys merged separately from the core title, honeypot, store, and email handles
        $data = collect($filteredValues)->except([
            'title',
            'honeypot',
            'store',
            'email',
        ]);

        // Assign core scalar settings on the form model
        /** @var Form $form */
        $form->title($filteredValues['title']);
        $form->honeypot($filteredValues['honeypot']);
        $form->store($filteredValues['store']);
        $form->email($filteredValues['email']);
        $form->merge($data);

        // Persist the updated form definition to Statamic storage
        $form->save();

        // Flash the standard Statamic CP success response
        $this->success(__('Saved'));

        // Drop references to large graphs after save to reduce peak memory use
        unset(
            $fields,
            $values,
            $filteredValues,
            $data
        );
    }
}
