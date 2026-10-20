<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Rules;

use Closure;

/**
 * Validation rule that requires at least one subfield of the current field to
 * have a value.
 *
 * This rule checks if at least one subfield within the current field group has a
 * non-null, non-empty value.
 *
 * Usage:
 * 'field' => [new SubfieldsAny()]
 */
class SubfieldsAny extends BaseSubfieldsRule
{
    /**
     * Fire on empty values so a group with all-blank subfields still fails.
     *
     * Without this, Laravel's `InvokableValidationRule::make()` skips the rule
     * when the attribute is missing or empty, defeating the rule's purpose.
     */
    public bool $implicit = true;

    /**
     * Run the validation rule.
     */
    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail
    ): void {
        // ---
        // Normalize field data to array
        // ---

        // Normalize the value to an array, or return early if it can't be normalized
        $fieldData = $this->normalizeFieldData($value);
        if ($fieldData === null) {
            // Return early since value can't have subfields
            return;
        }

        // ---
        // Pass as soon as one subfield has a value
        // ---

        // Loop through each subfield in the field data
        foreach ($fieldData as $subfieldValue) {
            // A subfield with a value satisfies the rule
            if ($this->subfieldHasValue($subfieldValue)) {
                return;
            }
        }

        // ---
        // No subfield has a value, so validation fails
        // ---

        // Fail validation with a specific message
        $fail(__('statamic-form-settings::validation.subfields_any', ['attribute' => $attribute]));
    }
}
