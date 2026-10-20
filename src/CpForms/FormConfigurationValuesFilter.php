<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\CpForms;

use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Filters Statamic-processed form configuration values before they are written to
 * YAML storage.
 *
 * Delegates condition evaluation to FieldConditionEvaluator, email grid
 * normalization to EmailGridValuesFilter, and confirmation settings normalization
 * to ConfirmationSettingsValuesFilter.
 *
 * Used from UAMSWeb\FormSettings\Http\Controllers\FormsController::update after
 * Fields::process().
 */
final readonly class FormConfigurationValuesFilter
{
    /**
     * @param  FieldConditionEvaluator  $conditionEvaluator  Evaluates Statamic field visibility conditions
     * @param  EmailGridValuesFilter  $emailGridFilter  Normalizes email grid rows before persistence
     * @param  ConfirmationSettingsValuesFilter  $confirmationFilter  Normalizes confirmation settings before persistence
     */
    public function __construct(
        private FieldConditionEvaluator $conditionEvaluator,
        private EmailGridValuesFilter $emailGridFilter,
        private ConfirmationSettingsValuesFilter $confirmationFilter,
    ) {}

    /**
     * Produce a reduced value map that omits fields hidden by blueprint conditional
     * logic.
     *
     * @param  array<string, mixed>  $values  Flattened processed field values from the configuration blueprint
     * @param  Fields  $fields  Field instances carrying Statamic condition metadata
     * @return array<string, mixed> Filtered values safe to merge into the Form model
     */
    public function filterFieldsByConditions(
        array $values,
        Fields $fields
    ): array {
        // Initialize array to store filtered field values
        $filteredValues = [];

        // Loop through all field values to filter based on conditions
        foreach ($values as $key => $value) {
            // Get the field configuration to check conditions
            /** @var Field|null $field */
            $field = $fields->get($key);

            // Include unknown fields as-is to preserve custom functionality
            if (! $field) {
                $filteredValues[$key] = $value;

                continue;
            }

            // Check if field has conditional logic
            $conditions = $field->conditions();

            // No conditions: always include, but still filter nested content
            if (empty($conditions)) {
                $filteredValues[$key] = $this->processFieldValue(
                    $value,
                    $key
                );

                continue;
            }

            // Include when visible, exclude when hidden
            if (
                $this->conditionEvaluator->shouldFieldBeVisible(
                    $field,
                    $values
                )
            ) {
                $filteredValues[$key] = $this->processFieldValue(
                    $value,
                    $key
                );
            }
        }

        return $filteredValues;
    }

    /**
     * Process a single field value, applying nested filtering for array-typed values.
     *
     * @param  mixed  $value  The field value to process
     * @param  string  $key  The field key used to decide nested filtering behavior
     * @return mixed The processed value (filtered array for complex fields, unchanged otherwise)
     */
    private function processFieldValue(
        mixed $value,
        string $key
    ): mixed {
        // Return filtered array for complex fields, or value as-is for simple fields
        if (\is_array($value)) {
            /** @var array<string, mixed> $value */
            return $this->filterByBlueprintConditions(
                $value,
                $key
            );
        }

        return $value;
    }

    /**
     * Delegate nested filtering to the appropriate sub-service based on the parent
     * key.
     *
     * @param  array<string, mixed>  $value  Nested value array to normalize
     * @param  string  $parentKey  Parent field key used to choose the sub-service
     * @return array<array-key, mixed> Normalized nested value array (assoc for most fields, a list for email grid rows)
     */
    private function filterByBlueprintConditions(
        array $value,
        string $parentKey
    ): array {
        // Delegate confirmation settings normalization
        if ($parentKey === 'confirmation_settings_default') {
            return $this->confirmationFilter->filter($value);
        }

        // Delegate email grid normalization (the email value is a list of rows)
        if ($parentKey === 'email') {
            /** @var array<int, mixed> $value */
            return $this->emailGridFilter->filter($value);
        }

        // For other complex fields, return as-is
        return $value;
    }
}
