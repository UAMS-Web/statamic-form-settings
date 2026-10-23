<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Fieldtypes\Traits;

use Statamic\Fields\Field;

/**
 * Trait for detecting form blueprint context in fieldtypes.
 *
 * This trait provides methods to determine if a fieldtype is being configured
 * within a form blueprint context, allowing for conditional configuration
 * based on the blueprint type.
 */
trait FormBlueprintContext
{
    /**
     * Determine if the fieldtype is being configured within a Form blueprint.
     *
     * Attempts to detect the blueprint context by checking the request path first,
     * then falling back to the parent blueprint's namespace. This method is called
     * during configFieldItems() to conditionally show configuration only when the
     * field is used in forms.
     *
     * @return bool True if the fieldtype is being configured within a Form blueprint context, false otherwise
     */
    protected function isFormBlueprintContext(): bool
    {
        // Try to detect context from request path and parameters
        try {
            // Get the request object
            $request = request();

            // Get the request path
            $path = $request->path();

            // Check if we're in the forms section of the Control Panel
            if (
                \str_starts_with(
                    $path,
                    'cp/forms/'
                )
            ) {
                // Request path indicates form blueprint context
                return true;
            }

            // Check if we're in the collections section of the Control Panel
            if (
                \str_starts_with(
                    $path,
                    'cp/collections/'
                )
            ) {
                // Request path indicates collection context, not form
                return false;
            }

            // Check if we're in the fields/edit or fields/update context
            if (
                \str_starts_with(
                    $path,
                    'cp/fields/edit'
                )
                || \str_starts_with(
                    $path,
                    'cp/fields/update'
                )
            ) {
                // Get the referer header
                $referer = $request->header('referer');

                // Check if the referer header is set
                if ($referer) {
                    // Check if the referer contains the forms section
                    if (
                        \str_contains(
                            $referer,
                            '/cp/forms/'
                        )
                    ) {
                        // Referer indicates form blueprint context
                        return true;
                    }

                    // Check if the referer contains the collections section
                    if (
                        \str_contains(
                            $referer,
                            '/cp/collections/'
                        )
                    ) {
                        // Referer indicates collection context, not form
                        return false;
                    }
                }
            }

            // ---
            // Fallback to checking field parent context
            // ---

            // Get the field's parent; a fieldtype without a field has none. Without the instanceof check the
            // call on null throws, and the catch below returns the same false.
            // @pest-mutate-ignore: InstanceOfToTrue
            $parent = $this->field instanceof Field ? $this->field->parent() : null;

            // Check that the parent can report its blueprint. Calling a missing method throws, and the catch
            // below turns that into the same false, so neither half of this check changes the result.
            // @pest-mutate-ignore: BooleanOrToBooleanAnd, RemoveEarlyReturn
            if (! \is_object($parent) || ! method_exists($parent, 'blueprint')) {
                return false;
            }

            // Get the blueprint
            $blueprint = $parent->blueprint();

            // Check that the blueprint can report its namespace, on the same terms as the parent above
            // @pest-mutate-ignore: BooleanOrToBooleanAnd, RemoveEarlyReturn
            if (! \is_object($blueprint) || ! method_exists($blueprint, 'namespace')) {
                return false;
            }

            // Get the namespace
            $namespace = $blueprint->namespace();

            // ---
            // Check if the namespace is a string and starts with 'forms.'. Return true if
            // the namespace starts with 'forms.'.
            // ---
            return \is_string($namespace) && \str_starts_with(
                $namespace,
                'forms.'
            );
        }

        // ---
        // If any error occurs during context detection, default to false. This ensures
        // the field doesn't appear in unexpected contexts.
        // ---
        catch (\Throwable) {
            return false;
        }
    }
}
