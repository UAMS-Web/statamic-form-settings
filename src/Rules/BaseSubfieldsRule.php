<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Base class for subfield validation rules.
 *
 * Provides common functionality for validating subfields within a field group,
 * including data normalization and value checking.
 */
abstract class BaseSubfieldsRule implements DataAwareRule, ValidationRule
{
    /**
     * All of the data under validation.
     *
     * @var array<string, mixed> All of the data under validation
     */
    protected array $data = [];

    /**
     * Run the validation rule.
     *
     * @param  string  $attribute  The name of the attribute being validated
     * @param  mixed  $value  The value of the attribute being validated
     * @param  Closure  $fail  The closure to call if validation fails
     */
    abstract public function validate(
        string $attribute,
        mixed $value,
        Closure $fail
    ): void;

    /**
     * Normalize field data to an array.
     *
     * Checks if the value is an array or object, and converts it to an array
     * if necessary. Returns null if the value cannot be normalized.
     *
     * @param  mixed  $value  The value to normalize
     * @return ?array<string, mixed> The normalized array, or null if value cannot be normalized
     */
    protected function normalizeFieldData(
        mixed $value
    ): ?array {
        // Check if the value is not an array or object
        if (
            ! \is_array($value)
            && ! \is_object($value)
        ) {
            // Return null since value can't have subfields
            return null;
        }

        // Convert to array if it's an object
        /** @var array<string, mixed> $normalized */
        $normalized = \is_object($value) ? (array) $value : $value;

        return $normalized;
    }

    /**
     * Check if a subfield value is non-empty.
     *
     * Returns true if the subfield has a non-null, non-empty value. The value
     * '0' (string), 0 (integer), and false (boolean) are considered valid
     * values.
     *
     * @param  mixed  $subfieldValue  The subfield value to check
     * @return bool True if the subfield has a value, false otherwise
     */
    protected function subfieldHasValue(
        mixed $subfieldValue
    ): bool {
        return ! (
            empty($subfieldValue)
            && $subfieldValue !== '0'
            && $subfieldValue !== 0
            && $subfieldValue !== false
        );
    }

    /**
     * Set the data under validation.
     *
     * @param  array<string, mixed>  $data  All of the data under validation
     * @return static This instance for method chaining
     */
    public function setData(
        array $data
    ): static {
        // Store the data array for use in validation
        $this->data = $data;

        // Return this instance for method chaining
        return $this;
    }
}
