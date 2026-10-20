<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\CpForms;

/**
 * Normalizes the email grid value array before it is written to YAML storage.
 *
 * Strips routing arrays that contain only empty rows and retains only the
 * recipient variant fields (direct address, field selector, or routing) that
 * match the chosen type value for each email item.
 *
 * Consumed from FormConfigurationValuesFilter when the parent key is 'email'.
 */
final readonly class EmailGridValuesFilter
{
    /**
     * @param  FieldConditionEvaluator  $conditionEvaluator  Shared field-inclusion helper used to test core columns for presence
     */
    public function __construct(
        private FieldConditionEvaluator $conditionEvaluator,
    ) {}

    /**
     * Filter an email grid value array, removing empty routing payloads and
     * unselected recipient variants.
     *
     * @param  array<int, mixed>  $value  Raw email grid rows from the processed blueprint
     * @return array<int, mixed> Normalized email grid rows safe to persist
     */
    public function filter(
        array $value
    ): array {
        // Initialize array to store filtered email items
        $filtered = [];

        // ---
        // Process each email configuration item
        // ---

        // Loop through each email configuration item in the grid
        foreach ($value as $index => $emailItem) {
            // Check if the email item is not an array
            if (! \is_array($emailItem)) {
                // Preserve non-array items as-is
                $filtered[$index] = $emailItem;

                // Skip to next item
                continue;
            }

            /** @var array<string, mixed> $emailItem */

            // Initialize filtered email item
            $filteredEmail = [];

            // ---
            // Define core email fields that should always be included
            //
            // Note: recipient fields (to, cc, bcc, reply_to) and their variants are handled
            // by conditional logic below
            // ---
            $coreFields = [
                'id',
                'name',
                'enabled',
                'to_type',
                'cc_type',
                'bcc_type',
                'from',
                'from_name',
                'reply_to_type',
                'subject',
                'html',
                'text',
                'message',
                'markdown',
                'attachments',
                'mailer',
                'conditional_logic',
            ];

            // Loop through each core field to include if present
            foreach ($coreFields as $field) {
                // Check if the field exists in the email item
                if (
                    $this->conditionEvaluator->shouldIncludeField(
                        $emailItem,
                        $field,
                        'exists'
                    )
                ) {
                    // Include the field in filtered email item
                    $filteredEmail[$field] = $emailItem[$field];
                }
            }

            // Include conditional fields based on their corresponding type values
            $conditionalFieldMapping = [
                'to_type' => [
                    'email' => 'to',
                    'field' => 'to_field',
                    'routing' => 'to_routing',
                ],
                'cc_type' => [
                    'email' => 'cc',
                    'field' => 'cc_field',
                    'routing' => 'cc_routing',
                ],
                'bcc_type' => [
                    'email' => 'bcc',
                    'field' => 'bcc_field',
                    'routing' => 'bcc_routing',
                ],
                'reply_to_type' => [
                    'email' => 'reply_to',
                    'field' => 'reply_to_field',
                    'routing' => 'reply_to_routing',
                ],
            ];

            // Loop through each conditional field mapping to include appropriate fields
            foreach ($conditionalFieldMapping as $typeField => $fieldMapping) {
                // Get the current type value (e.g., 'email', 'field', 'routing')
                $typeValue = $emailItem[$typeField] ?? null;

                // Check that the type value is one of the mapped type names
                if (
                    \is_string($typeValue)
                    && isset($fieldMapping[$typeValue])
                ) {
                    // Get the target field name from the mapping
                    $targetField = $fieldMapping[$typeValue];

                    // Check if the target field exists in the email item
                    if (isset($emailItem[$targetField])) {
                        // Check if this is a routing field
                        if (
                            str_contains(
                                $targetField,
                                '_routing'
                            )
                        ) {
                            // Check if routing data contains meaningful information
                            if ($this->hasMeaningfulRoutingData($emailItem[$targetField])) {
                                // Include the routing field in filtered email item
                                $filteredEmail[$targetField] = $emailItem[$targetField];
                            }
                        } else { // Otherwise
                            // For non-routing fields, include them directly
                            $filteredEmail[$targetField] = $emailItem[$targetField];
                        }
                    }
                }
            }

            // Add the filtered email item to the results
            $filtered[] = $filteredEmail;
        }

        // Return the filtered email grid
        return $filtered;
    }

    /**
     * Has Meaningful Routing Data
     *
     * Checks if routing data contains meaningful information
     * (not just null/empty values).
     *
     * @param  mixed  $routingData  The routing data to check
     * @return bool True if routing data is meaningful, false otherwise
     */
    private function hasMeaningfulRoutingData(
        mixed $routingData
    ): bool {
        // Check if routing data is not an array
        if (! \is_array($routingData)) {
            // Return false if routing data is not an array
            return false;
        }

        // Loop through each routing rule to check for meaningful data
        foreach ($routingData as $route) {
            // ---
            // Ensure route is an array with proper structure
            // ---

            // Check if the route is an array
            if (\is_array($route)) {
                // ---
                // Check if this route has meaningful data for actual routing
                // ---

                // Verify field selector is specified (empty() is also true for a missing key)
                $hasField = ! empty($route['field']);

                // Verify condition value is specified
                $hasValue = ! empty($route['value']);

                // Verify target email is specified
                $hasEmail = ! empty($route['email']);

                // ---
                // Only consider routing meaningful if it has the essential data for routing logic
                // ---

                // Check if all essential routing data is present
                if (
                    $hasField
                    && $hasValue
                    && $hasEmail
                ) {
                    // Return true if routing data is meaningful
                    return true;
                }
            }
        }

        // No meaningful routing data found
        return false;
    }
}
