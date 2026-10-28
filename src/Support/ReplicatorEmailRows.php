<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Support;

/**
 * Turns a replicator's submitted rows into labelled set blocks for notification emails (UAMS-Web/uams-statamic-archive#2055).
 *
 * Statamic's automagic email template has no replicator branch, so a replicator falls through
 * to `{{ value | json | sanitize }}` and reaches the recipient as an escaped JSON array carrying
 * internal `id`/`type`/`enabled` keys and no subfield labels at all (UAMS-Web/uams-statamic-archive#2051). This class supplies
 * the missing shape: one block per set, each carrying the set's display label and its subfields
 * as label/value pairs.
 *
 * The rows arrive raw rather than augmented (a `toggle` is `true`, a `select` is its option
 * *key*), so the display mapping happens here, driven by the field config the blueprint already
 * carries.
 *
 * ## Known limitation: nested replicators
 *
 * Only one level is flattened into label/value pairs. A subfield that is itself a replicator or
 * grid falls back to a JSON string, so it stays **lossless but not legible**. Nesting does not
 * occur in the forms this was built for (the WordPress waiver's sets are flat), so the extra
 * recursion was not worth the ambiguity of how to indent a nested block inside an email.
 */
final class ReplicatorEmailRows
{
    /**
     * Field types whose submitted value is an option key that must be mapped to its label.
     *
     * @var list<string>
     */
    private const array OPTION_FIELDTYPES = ['select', 'radio', 'button_group', 'checkboxes'];

    /**
     * Row keys Statamic uses internally, which must never reach a recipient.
     *
     * @var list<string>
     */
    private const array INTERNAL_KEYS = ['id', '_id', 'type', 'enabled'];

    /**
     * Build the labelled set blocks for one replicator field.
     *
     * @param  mixed  $rows  Raw replicator value: a list of set rows.
     * @param  array<mixed, mixed>  $config  The replicator field's config, carrying `sets`.
     * @return list<array{set_label: string, pairs: list<array{label: string, text: string}>}>
     */
    public static function build(mixed $rows, array $config): array
    {
        if (! is_array($rows)) {
            return [];
        }

        // Flatten the blueprint's set-group structure into one type => definition map.
        $definitions = self::setDefinitions($config);

        $blocks = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            // A set toggled off in the CP is excluded from the submission's meaning, so it is
            // excluded from the email too rather than rendered as an empty block.
            if (array_key_exists('enabled', $row) && ! self::isTruthy($row['enabled'])) {
                continue;
            }

            $type = is_string($row['type'] ?? null) ? $row['type'] : '';
            $definition = $definitions[$type] ?? [];

            $blocks[] = [
                'set_label' => self::setLabel($definition, $type),
                'pairs' => self::pairs($row, $definition),
            ];
        }

        return $blocks;
    }

    /**
     * Flatten `sets` (groups of sets) into a single set-type => definition map.
     *
     * @param  array<mixed, mixed>  $config  The replicator field's config.
     * @return array<string, array<mixed, mixed>>
     */
    private static function setDefinitions(array $config): array
    {
        $groups = $config['sets'] ?? [];

        if (! is_array($groups)) {
            return [];
        }

        $definitions = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            // A group holds its set types under `sets`; a legacy flat config is the group itself.
            $sets = is_array($group['sets'] ?? null) ? $group['sets'] : $group;

            foreach ($sets as $handle => $definition) {
                if (is_string($handle) && is_array($definition)) {
                    $definitions[$handle] = $definition;
                }
            }
        }

        return $definitions;
    }

    /**
     * Resolve a set's display label, falling back to a humanized handle.
     *
     * @param  array<mixed, mixed>  $definition  The set definition from the field config.
     * @param  string  $type  The set's handle.
     * @return string The label to show above the set's fields.
     */
    private static function setLabel(array $definition, string $type): string
    {
        $display = $definition['display'] ?? null;

        if (is_string($display) && $display !== '') {
            return $display;
        }

        return $type === '' ? 'Set' : ucfirst(str_replace('_', ' ', $type));
    }

    /**
     * Build the label/value pairs for one set row, in the blueprint's field order.
     *
     * @param  array<mixed, mixed>  $row  One raw set row.
     * @param  array<mixed, mixed>  $definition  That set's definition from the field config.
     * @return list<array{label: string, text: string}>
     */
    private static function pairs(array $row, array $definition): array
    {
        $fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
        $pairs = [];
        $seen = [];

        // Walk the blueprint order first, so the email reads like the form.
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $handle = $field['handle'] ?? null;

            if (! is_string($handle)) {
                continue;
            }

            if (! array_key_exists($handle, $row)) {
                continue;
            }

            // `isset()` reads this marker, and `isset()` is true for false as well, so the value is never observed.
            // @pest-mutate-ignore: TrueToFalse
            $seen[$handle] = true;
            $subConfig = is_array($field['field'] ?? null) ? $field['field'] : [];

            $pairs[] = [
                'label' => self::fieldLabel($subConfig, $handle),
                'text' => self::displayValue($row[$handle], $subConfig),
            ];
        }

        // Then any submitted key the blueprint does not describe, so nothing is silently dropped.
        foreach ($row as $handle => $value) {
            $handle = (string) $handle;

            if (isset($seen[$handle])) {
                continue;
            }

            if (in_array($handle, self::INTERNAL_KEYS, true)) {
                continue;
            }

            $pairs[] = [
                'label' => ucfirst(str_replace('_', ' ', $handle)),
                'text' => self::displayValue($value, []),
            ];
        }

        return $pairs;
    }

    /**
     * Resolve a subfield's display label, falling back to a humanized handle.
     *
     * @param  array<mixed, mixed>  $config  The subfield's config.
     * @param  string  $handle  The subfield's handle.
     * @return string The label to show beside the text.
     */
    private static function fieldLabel(array $config, string $handle): string
    {
        $display = $config['display'] ?? null;

        if (is_string($display) && $display !== '') {
            return $display;
        }

        return ucfirst(str_replace('_', ' ', $handle));
    }

    /**
     * Render one submitted value the way its own fieldtype means it.
     *
     * A `toggle` reads Yes/No rather than `1`; an option fieldtype resolves its key to the
     * configured label; a list joins with commas. Anything else is cast to a string.
     *
     * @param  mixed  $value  The raw submitted value.
     * @param  array<mixed, mixed>  $config  The subfield's config.
     * @return string The display string.
     */
    private static function displayValue(mixed $value, array $config): string
    {
        // The fallback is only ever compared against fieldtype names, so any non-matching string behaves the same.
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $type = is_string($config['type'] ?? null) ? $config['type'] : '';

        // Booleans are meaningless to a recipient as 1/0.
        if ($type === 'toggle' || is_bool($value)) {
            return self::isTruthy($value) ? 'Yes' : 'No';
        }

        if (in_array($type, self::OPTION_FIELDTYPES, true)) {
            $options = is_array($config['options'] ?? null) ? $config['options'] : [];

            if (is_array($value)) {
                return implode(', ', array_map(
                    static fn (mixed $item): string => self::optionLabel($item, $options),
                    $value
                ));
            }

            return self::optionLabel($value, $options);
        }

        if (is_array($value)) {
            $scalars = array_filter($value, is_scalar(...));

            // A flat list (a `list` field, a multi-select) reads well as a comma-joined string.
            if (count($scalars) === count($value)) {
                // `implode()` casts each scalar exactly as `(string)` does, so dropping the map changes nothing.
                // @pest-mutate-ignore: UnwrapArrayMap
                return implode(', ', array_map(
                    static fn (bool|float|int|string $item): string => (string) $item,
                    $scalars
                ));
            }

            // Anything deeper (a NESTED replicator or grid) is not flattened. Falling back to
            // JSON keeps it lossless rather than silently rendering nothing; a nested replicator
            // therefore still reads poorly, which is a documented limitation rather than a bug.
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Resolve one option key to its configured label.
     *
     * @param  mixed  $value  The submitted option key.
     * @param  array<mixed, mixed>  $options  The subfield's `options` map.
     * @return string The label, or the key when no label is configured.
     */
    private static function optionLabel(mixed $value, array $options): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $key = (string) $value;
        $label = $options[$key] ?? null;

        return is_scalar($label) && (string) $label !== '' ? (string) $label : $key;
    }

    /**
     * Decide whether a submitted flag counts as on.
     *
     * @param  mixed  $value  The raw flag value.
     * @return bool Whether the flag is on.
     */
    private static function isTruthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
