<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\CpForms;

/**
 * Finds sections in the Statamic 6 CP form-configuration blueprint contents.
 *
 * Core's `FormsController::editFormBlueprint()` returns one tab whose
 * `sections` is a positional list: the keys core assembles the sections under
 * (`name`, `fields`, `submissions`, `email`) are discarded. What survives is
 * each section's list of `['handle' => …, 'field' => …]` entries, so a section
 * is located by the handle of a field it carries, never by a section key.
 *
 * Consumed by FormConfigurationTabsInjector and EmailGridBlueprintCustomizer.
 */
final class FormConfigurationSectionLocator
{
    /**
     * Locate the section that carries a field with the given handle.
     *
     * @param  array<string, mixed>  $contents  Blueprint contents in the `tabs.*.sections` shape.
     * @param  string  $fieldHandle  Handle of a field the section carries, such as `title` or `email`.
     * @return array{tab: string, section: int, field: int}|null Tab key, section index and field
     *                                                           index, or null when no section carries the field.
     */
    public static function locateField(
        array $contents,
        string $fieldHandle
    ): ?array {
        // Check that the contents carry a tabs map to search
        $tabs = $contents['tabs'] ?? null;
        if (! is_array($tabs)) {
            return null;
        }

        // Search every section of every tab for a field with the handle
        foreach ($tabs as $tabKey => $tab) {
            $sections = is_array($tab) ? ($tab['sections'] ?? null) : null;
            if (! is_array($sections)) {
                continue;
            }

            foreach ($sections as $sectionIndex => $section) {
                $fields = is_array($section) ? ($section['fields'] ?? null) : null;
                if (! is_array($fields)) {
                    continue;
                }

                foreach ($fields as $fieldIndex => $field) {
                    // Return the position of the first field carrying the handle
                    if (is_array($field) && ($field['handle'] ?? null) === $fieldHandle) {
                        return [
                            'tab' => (string) $tabKey,
                            'section' => (int) $sectionIndex,
                            'field' => (int) $fieldIndex,
                        ];
                    }
                }
            }
        }

        return null;
    }
}
