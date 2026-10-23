<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Support;

use Illuminate\Contracts\Support\Arrayable;
use Statamic\Assets\Asset;
use Statamic\Fields\ArrayableString;
use Statamic\Fields\Value;

/**
 * Renders one submitted form field as the escaped HTML a notification email shows for it (UAMS-Web/uams-statamic#3149).
 *
 * It mirrors the branches of the add-on's automagic email override at
 * `resources/views/forms/automagic-email.antlers.html`, so the `{{ all_fields }}`
 * table and the `{{ submission:<handle> }}` merge tags show a field exactly as the field list
 * does: assets as their URLs, option fields as their labels, replicators as their labelled set
 * blocks (UAMS-Web/uams-statamic#2055), other arrays as JSON, and text with its line breaks kept. An asset whose
 * container has no public URL shows its path, where the template showed nothing.
 *
 * Every submitted string is HTML-escaped, and its `{` and `}` are encoded as entities, so a
 * value carrying markup or Antlers syntax reaches the recipient as text and is never parsed.
 */
final class SubmittedFieldDisplay
{
    /**
     * Render a renderable field entry as escaped HTML.
     *
     * @param  array<array-key, mixed>  $field  One entry of the mailable's renderable field data:
     *                                          `fieldtype`, `config`, `value` and, for a
     *                                          replicator, `email_rows`.
     * @return string Escaped HTML, or an empty string when the field holds no value.
     */
    public static function html(array $field): string
    {
        // Unwrap an augmented value so the branches see what the template's `value` sees
        $rawValue = $field['value'] ?? null;
        $value = $rawValue instanceof Value ? $rawValue->value() : $rawValue;

        // Render nothing for an empty value, as the template's `{{ if value }}` does
        if (self::isEmpty($value)) {
            return '';
        }

        // Read the fieldtype from either key: the mailable writes `fieldtype`, older callers `type`
        $fieldtype = $field['fieldtype'] ?? $field['type'] ?? null;
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];

        return match (true) {
            $fieldtype === 'assets' => self::assets($value),
            $fieldtype === 'replicator' => self::replicator($field),
            // A single select augments to one labelled value, which the labels branch below renders
            // identically, so the default for a missing `multiple` cannot change the output
            // @pest-mutate-ignore: FalseToTrue
            $fieldtype === 'radio',
            $fieldtype === 'select' && ! ($config['multiple'] ?? false) => self::escape(self::label($value)),
            in_array($fieldtype, ['select', 'checkboxes', 'dictionary'], true) => self::labels($value),
            is_iterable($value) => self::escape(json_encode(self::plain($value), JSON_THROW_ON_ERROR)),
            default => nl2br(self::escape(self::label($value)), false),
        };
    }

    /**
     * Decide whether a value counts as empty the way the template's `{{ if value }}` does.
     *
     * @param  mixed  $value  The unwrapped field value.
     */
    public static function isEmpty(mixed $value): bool
    {
        // An option field augments to a labelled value whose own value may be empty
        if ($value instanceof ArrayableString) {
            return self::isEmpty($value->value());
        }

        // A query builder or collection is empty when it yields no items
        if (is_object($value) && method_exists($value, 'get') && ! $value instanceof \ArrayAccess) {
            $value = $value->get();
        }

        if ($value instanceof Arrayable) {
            return $value->toArray() === [];
        }

        return in_array($value, [null, '', [], false], true);
    }

    /**
     * Render an assets value as a comma-separated list of absolute URLs.
     *
     * @param  mixed  $value  A single asset, a list of assets, or a query builder for them.
     */
    private static function assets(mixed $value): string
    {
        // Resolve a query builder to its results, and a single asset to a one-item list
        if (is_object($value) && method_exists($value, 'get') && ! $value instanceof Asset) {
            $value = $value->get();
        }

        $assets = $value instanceof Asset ? [$value] : (is_iterable($value) ? $value : []);

        // Collect each asset's URL, escaped, or its path when its container has no public URL
        $urls = [];

        foreach ($assets as $asset) {
            $url = $asset instanceof Asset ? ($asset->absoluteUrl() ?: $asset->path()) : null;

            if (is_string($url)) {
                $urls[] = self::escape($url);
            }
        }

        return implode(', ', $urls);
    }

    /**
     * Render a replicator's labelled set blocks, as the template's replicator branch does.
     *
     * @param  array<array-key, mixed>  $field  The replicator field entry carrying `email_rows`.
     */
    private static function replicator(array $field): string
    {
        $rows = is_array($field['email_rows'] ?? null) ? $field['email_rows'] : [];

        // Build one block per set: the set label in bold, then each subfield's label and value
        $blocks = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $block = '<b>'.self::escape(self::label($row['set_label'] ?? '')).'</b><br>';

            foreach (is_array($row['pairs'] ?? null) ? $row['pairs'] : [] as $pair) {
                if (! is_array($pair)) {
                    continue;
                }

                $block .= self::escape(self::label($pair['label'] ?? '')).': '
                    .nl2br(self::escape(self::label($pair['text'] ?? '')), false).'<br>';
            }

            $blocks[] = $block;
        }

        return implode('', $blocks);
    }

    /**
     * Render a multiple-option value as its labels, comma-separated.
     *
     * @param  mixed  $value  A list of labelled values or option keys.
     */
    private static function labels(mixed $value): string
    {
        $items = is_iterable($value) ? $value : [$value];

        $labels = [];

        foreach ($items as $item) {
            $labels[] = self::escape(self::label($item));
        }

        return implode(', ', $labels);
    }

    /**
     * Resolve the text a single value displays as: an option's label when it has one.
     *
     * @param  mixed  $value  A labelled value, a dictionary item, or a scalar.
     */
    private static function label(mixed $value): string
    {
        // Prefer the option label, falling back to the stored key, as `label ?? value` does
        if ($value instanceof ArrayableString) {
            $extra = $value->extra();
            $label = is_array($extra) ? $extra['label'] ?? null : null;

            return self::scalar($label ?? $value->value());
        }

        return self::scalar($value);
    }

    /**
     * Cast a scalar or stringable value to a string, and anything else to an empty string.
     *
     * @param  mixed  $value  The value to cast.
     */
    private static function scalar(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    /**
     * Convert an iterable value to plain arrays so it encodes as JSON.
     *
     * @param  iterable<mixed>  $value  The value to convert.
     * @return array<array-key, mixed>
     */
    private static function plain(iterable $value): array
    {
        return $value instanceof Arrayable ? $value->toArray() : iterator_to_array($value);
    }

    /**
     * Escape text for HTML, encoding braces so the result is never parsed as Antlers.
     *
     * @param  string  $text  The submitted text.
     */
    private static function escape(string $text): string
    {
        return strtr(e($text), ['{' => '&#123;', '}' => '&#125;']);
    }
}
