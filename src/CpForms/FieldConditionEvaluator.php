<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\CpForms;

use Statamic\Fields\Field;

/**
 * Evaluates Statamic blueprint field visibility conditions against a set of
 * submitted values.
 *
 * Supports the 'if', 'unless', 'show_when', and 'hide_when' condition types that
 * Statamic uses in blueprint field definitions, as well as its 'equals ',
 * 'not empty', 'empty', 'true', and 'false' condition-value syntax.
 *
 * Consumed from FormConfigurationValuesFilter to decide which top-level fields to
 * retain, and from EmailGridValuesFilter / ConfirmationSettingsValuesFilter when
 * deciding whether to retain individual subfields based on a presence check type.
 */
final class FieldConditionEvaluator
{
    /**
     * Determine whether a single keyed value should be included based on a presence
     * check.
     *
     * @param  array<string, mixed>  $values  The array containing the candidate value
     * @param  string  $field  The field name to look up in the array
     * @param  string  $checkType  One of '!empty', '!==null', 'exists' (default keeps the value)
     * @return bool True when the value passes the requested check, false otherwise
     */
    public function shouldIncludeField(
        array $values,
        string $field,
        string $checkType
    ): bool {
        // Reject the field outright when the key is missing
        if (! isset($values[$field])) {
            // Return false if field is not found
            return false;
        }

        // Evaluate the stored value against the requested check semantics
        // The isset() guard above already proves '!==null' and 'exists', so only '!empty' checks further
        return match ($checkType) {
            '!empty' => ! empty($values[$field]),
            default => true,
        };
    }

    /**
     * Determine whether a field should be visible given the current form values.
     *
     * @param  Field  $field  The field whose conditions to evaluate
     * @param  array<string, mixed>  $values  The current form values used as the condition context
     * @return bool True if the field should be visible, false if it should be hidden
     */
    public function shouldFieldBeVisible(
        Field $field,
        array $values
    ): bool {
        // Get the field's conditional logic configuration
        /** @var array<string, array<string, mixed>> $conditions */
        $conditions = $field->conditions();

        // Define condition types and their logic requirements
        $conditionTypes = [
            'if' => false,        // Must be true
            'unless' => true,     // Must be false
            'show_when' => false, // Must be true
            'hide_when' => true,   // Must be false
        ];

        // Loop through each condition type to evaluate field visibility
        foreach ($conditionTypes as $type => $invertLogic) {
            // Check if this condition type is defined for the field
            if (isset($conditions[$type])) {
                // ---
                // Evaluate each condition within this type
                // ---

                // Loop through each condition within the current condition type
                foreach ($conditions[$type] as $conditionField => $conditionValue) {
                    // Get the actual value from the form data
                    $actualValue = $this->getNestedValue(
                        $values,
                        $conditionField
                    );

                    // Check if the condition is met
                    $conditionMet = $this->evaluateCondition(
                        $actualValue,
                        $conditionValue
                    );

                    // Check if the condition logic is violated
                    if ($conditionMet === $invertLogic) {
                        // Return false if condition is not met
                        return false;
                    }
                }
            }
        }

        // All conditions passed, field should be visible
        return true;
    }

    /**
     * Get Nested Value
     *
     * Gets a value from a nested array using dot notation.
     *
     * @param  array<string, mixed>  $array  The array to search
     * @param  string  $key  The dot-notation key
     * @return mixed The value or null if not found
     */
    private function getNestedValue(
        array $array,
        string $key
    ): mixed {
        // Split the dot-notation key into individual array keys
        $keys = \explode(
            '.',
            $key
        );

        // Start with the root array
        $value = $array;

        // Loop through each key level to navigate the nested array
        foreach ($keys as $k) {
            // Check if the current level exists and is accessible
            if (
                ! \is_array($value)
                || ! \array_key_exists(
                    $k,
                    $value
                )
            ) {
                // Return null if the nested path is invalid
                return null;
            }

            // Move to the next level of nesting
            $value = $value[$k];
        }

        // Return the final nested value
        return $value;
    }

    /**
     * Evaluate Condition
     *
     * Evaluates a single condition between an actual value and expected value.
     * Supports various comparison operators like 'equals', 'not empty', etc.
     *
     * @param  mixed  $actualValue  The actual value from the form
     * @param  mixed  $expectedValue  The expected value from the condition
     * @return bool True if condition is met, false otherwise
     */
    private function evaluateCondition(
        mixed $actualValue,
        mixed $expectedValue
    ): bool {
        // ---
        // Handle Statamic-specific condition syntax
        // ---

        // Check if the expected value uses Statamic's 'equals ' prefix syntax
        if (
            \is_string($expectedValue)
            && str_starts_with($expectedValue, 'equals ')
        ) {
            // Extract the actual value by removing the 'equals ' prefix
            $expectedValue = \substr(
                $expectedValue,
                7
            ); // Remove 'equals ' prefix
        }

        // Check for 'not empty' condition
        if ($expectedValue === 'not empty') {
            // Return true if the actual value is not empty
            return ! empty($actualValue);
        }

        // Check for 'empty' condition
        if ($expectedValue === 'empty') {
            // Return true if the actual value is empty
            return empty($actualValue);
        }

        // Check for 'true' condition
        if ($expectedValue === 'true') {
            // Return true if the actual value is exactly true
            return $actualValue === true;
        }

        // Check for 'false' condition
        if ($expectedValue === 'false') {
            // Return true if the actual value is exactly false
            return $actualValue === false;
        }

        // Default: exact match
        return $actualValue === $expectedValue;
    }
}
