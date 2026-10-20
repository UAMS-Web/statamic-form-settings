<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\CpForms;

/**
 * Normalizes the confirmation settings value array before it is written to YAML
 * storage.
 *
 * Retains only the fields that are relevant to the selected confirmation type
 * (message, redirect, entry, or replace), dropping type-specific fields that
 * belong to other branches.
 *
 * Consumed from FormConfigurationValuesFilter when the parent key is
 * 'confirmation_settings_default'.
 */
final readonly class ConfirmationSettingsValuesFilter
{
    /**
     * @param  FieldConditionEvaluator  $conditionEvaluator  Shared field-inclusion helper used to test branch-specific keys for presence
     */
    public function __construct(
        private FieldConditionEvaluator $conditionEvaluator,
    ) {}

    /**
     * Filter confirmation settings, keeping only fields relevant to the selected
     * confirmation type.
     *
     * @param  array<string, mixed>  $value  Raw confirmation settings from the processed blueprint
     * @return array<string, mixed> Normalized confirmation settings safe to persist
     */
    public function filter(
        array $value
    ): array {
        // Get the confirmation type or default to 'message'
        $confirmationType = $value['confirmation_type'] ?? 'message';

        // Initialize filtered array with confirmation type
        $filtered = ['confirmation_type' => $confirmationType];

        // ---
        // Always include the confirmation type
        // ---

        // Check if confirmation message exists and is not empty (empty() is also true for a missing key)
        if (! empty($value['confirmation_message'])) {
            // Include the confirmation message in filtered results
            $filtered['confirmation_message'] = $value['confirmation_message'];
        }

        // ---
        // Only include redirect-related fields if type is redirect
        // ---

        // Check if confirmation type is redirect
        if ($confirmationType === 'redirect') {
            // Define redirect fields and their validation requirements
            $redirectFields = [
                'confirmation_redirect_url' => '!empty',
                'confirmation_redirect_target' => '!==null',
                'confirmation_redirect_delay_query' => '!==null',
                'confirmation_redirect_delay_time' => '!empty',
                'confirmation_redirect_message' => '!empty',
            ];

            // ---
            // Process each redirect field based on its validation requirements
            // ---

            // Loop through each redirect field to validate and include
            foreach ($redirectFields as $field => $checkType) {
                // Check if the field should be included based on validation
                if (
                    $this->conditionEvaluator->shouldIncludeField(
                        $value,
                        $field,
                        $checkType
                    )
                ) {
                    // Include the field in filtered results
                    $filtered[$field] = $value[$field];
                }
            }
        }

        // Check if confirmation type is entry and page is specified
        if (
            $confirmationType === 'entry'
            && $this->conditionEvaluator->shouldIncludeField(
                $value,
                'confirmation_page',
                '!empty'
            )
        ) {
            // Include the confirmation page in filtered results
            $filtered['confirmation_page'] = $value['confirmation_page'];
        }

        // Check if confirmation type is replace and entry is specified
        if (
            $confirmationType === 'replace'
            && $this->conditionEvaluator->shouldIncludeField(
                $value,
                'confirmation_replace_entry',
                '!empty'
            )
        ) {
            // Include the confirmation replace entry in filtered results
            $filtered['confirmation_replace_entry'] = $value['confirmation_replace_entry'];
        }

        // Return the filtered confirmation settings
        return $filtered;
    }
}
