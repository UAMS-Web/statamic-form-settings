<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Support;

use UAMSWeb\FormSettings\Fieldtypes\FormConditionalLogic;

/**
 * Evaluates a stored form conditional-logic configuration against submitted values.
 *
 * The `conditional_logic` config saved by the {@see FormConditionalLogic}
 * fieldtype (a fork of Statamic's field-conditions Builder) uses Statamic's standard
 * field-conditions shape:
 *
 *     [
 *         'if' => ['field_handle' => 'operator value', ...],  // or if_any / unless / unless_any
 *         'always_save' => bool,                               // ignored here
 *     ]
 *
 * The condition key encodes two axes, mirroring Statamic's JS `Validator`:
 *   - logic: a `_any` suffix means ANY condition passing satisfies it; otherwise ALL must.
 *   - polarity: `if` / `show_when` pass when the conditions are met; `unless` / `hide_when`
 *     pass when they are NOT met (the inverse).
 *
 * Imported Gravity Forms / Formidable rules arrive as a list of `{field, operator, value}`
 * triples under the same key; both the map form and the list form are accepted. In the map
 * form a field may carry a list of right-hand strings, which the Control Panel builder saves
 * when one field has more than one condition (statamic/cms 6.31.0, #14593); each becomes its
 * own condition.
 *
 * No conditions (missing key, a null group, or a non-evaluable custom JS string) means
 * "always passes": for an email notification that is "always send", matching Statamic's
 * default-visible behavior. An empty group is read differently: `[]`, `{}`, and a field map
 * whose every entry is an empty list (`{field: []}`) are evaluated as the public page
 * evaluates them, where they yield no conditions and ALL of none passes while ANY of none
 * fails, before the key's inversion (UAMS-Web/uams-statamic#2889, UAMS-Web/uams-statamic#2895). This is the runtime that consumes the
 * imported config; the importer side is tracked separately (wordpress-importer-archive#38).
 *
 * Evaluation mirrors Statamic's client-side semantics for the documented operators and
 * special values (empty / true / false / null); it does not bit-replicate the JS `eval`
 * coercion quirks, which no realistic string/array/bool form value depends on.
 */
final class ConditionalLogicEvaluator
{
    /**
     * Recognized condition keys mapped to [passOnAny, showOnPass].
     *
     * passOnAny: the `_any` variants pass when ANY condition passes (vs ALL).
     * showOnPass: `unless` / `hide_when` invert the result (pass when conditions fail).
     *
     * @var array<string, array{0: bool, 1: bool}>
     */
    private const array KEYS = [
        'if' => [false, true],
        'if_any' => [true, true],
        'show_when' => [false, true],
        'show_when_any' => [true, true],
        'unless' => [false, false],
        'unless_any' => [true, false],
        'hide_when' => [false, false],
        'hide_when_any' => [true, false],
    ];

    /**
     * Operators and aliases in the detection order Statamic's Converter uses
     * (OPERATORS first, then ALIASES keys). The space-delimited regex disambiguates
     * `>` from `>=`, etc.
     *
     * @var list<string>
     */
    private const array OPERATORS_AND_ALIASES = [
        'equals', 'not', 'contains', 'contains_any', 'includes_any', '===', '!==', '>', '>=', '<', '<=', 'custom',
        'is', '==', 'isnt', '!=', 'includes',
    ];

    /**
     * Operator aliases normalized to their canonical operator (mirrors Statamic ALIASES
     * + prepareOperator collapsing).
     *
     * `includes_any` is not an alias: since statamic/cms 6.35.0 (#13135) it is an operator of
     * its own, testing membership where `contains_any` tests for a substring.
     *
     * The aliases of `equals` (`is`, `==`, `===` and the empty operator) need no entry: every
     * operator {@see self::passesCondition()} does not recognize evaluates as `equals`.
     *
     * @var array<string, string>
     */
    private const array ALIASES = [
        'isnt' => 'not',
        '!=' => 'not',
        '!==' => 'not',
        'includes' => 'contains',
        // Imported Gravity Forms / Formidable routing tokens (FormConfigurationManager maps GF
        // `>`/`<` to these words). They reach the evaluator via the list form (routing rules),
        // which bypasses parseRhs, so they are normalized to the canonical operator here.
        'greater_than' => '>',
        'less_than' => '<',
        'greater_than_or_equal' => '>=',
        'less_than_or_equal' => '<=',
    ];

    /**
     * Decide whether a conditional-logic config passes against the submitted values.
     *
     * @param  mixed  $conditionalLogic  The stored `conditional_logic` value (array, or anything else → passes).
     * @param  array<string, mixed>  $values  The submitted field values, keyed by handle.
     * @return bool True when the notification/confirmation should fire.
     */
    public function passes(mixed $conditionalLogic, array $values): bool
    {
        // A missing or non-array config carries no gate: always pass.
        if (! \is_array($conditionalLogic)) {
            return true;
        }

        // Evaluate the first recognized condition key; `always_save` and unknown keys are ignored.
        foreach (self::KEYS as $key => [$passOnAny, $showOnPass]) {
            if (! \array_key_exists($key, $conditionalLogic)) {
                continue;
            }

            return $this->evaluateKey($conditionalLogic[$key], $values, $passOnAny, $showOnPass);
        }

        // No condition key present → always pass.
        return true;
    }

    /**
     * Whether a single `{field, operator, value}` rule matches the submitted values.
     *
     * Reuses the same operator + special-value semantics as {@see self::passes()}; used by
     * recipient routing (UAMS-Web/uams-statamic#201), where each routing row is exactly one such rule.
     *
     * @param  array<string, mixed>  $rule  A `{field, operator, value}` rule (extra keys ignored).
     * @param  array<string, mixed>  $values  The submitted field values, keyed by handle.
     */
    public function matchesRule(array $rule, array $values): bool
    {
        if (! isset($rule['field']) || ! \is_scalar($rule['field'])) {
            return false;
        }

        return $this->passesCondition(
            // `data_get()` is declared without strict types, so its `explode()` coerces a scalar key exactly as this cast does.
            // @pest-mutate-ignore: RemoveStringCast
            data_get($values, (string) $rule['field']),
            // A missing operator falls back to `''`, which evaluates as `equals` as every unknown operator does, so any other fallback behaves the same.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            \is_scalar($rule['operator'] ?? null) ? (string) $rule['operator'] : '',
            $this->normalizeConditionString($rule['value'] ?? ''),
        );
    }

    /**
     * Evaluate one condition group under a single key.
     *
     * @param  mixed  $conditions  The map or list of conditions stored under the key.
     * @param  array<string, mixed>  $values  The submitted values.
     * @param  bool  $passOnAny  Whether any-passing satisfies the group (vs all).
     * @param  bool  $showOnPass  Whether to keep the raw result or invert it (unless/hide_when).
     */
    private function evaluateKey(mixed $conditions, array $values, bool $passOnAny, bool $showOnPass): bool
    {
        $normalized = $this->normalizeConditions($conditions);

        // An empty group (`[]` or `{}`) and a field map whose lists are all empty yield no
        // conditions, and the public evaluator still applies its ALL/ANY logic and inversion
        // to none, so both fall through to the same steps below (UAMS-Web/uams-statamic#2889, UAMS-Web/uams-statamic#2895). Anything else
        // that yields none (a null or scalar group, a custom JS string condition that cannot run
        // server-side, or rules that all name no field) passes.
        if ($normalized === [] && ! $this->isEmptyGroup($conditions)) {
            return true;
        }

        $results = array_map(
            fn (array $condition): bool => $this->passesCondition(
                data_get($values, $condition['field']),
                $condition['operator'],
                $condition['value'],
            ),
            $normalized,
        );

        // ALL: no result is false. ANY: at least one result is true. Each annotation sits on a
        // strict flag alone, so the ANY needle stays in the mutation population (UAMS-Web/uams-statamic#3147).
        $passes = $passOnAny
            ? \in_array(
                true,
                $results,
                // Every result is a bool, so a loose comparison matches exactly what this does.
                // @pest-mutate-ignore: TrueToFalse
                true,
            )
            : ! \in_array(
                false,
                $results,
                // Every result is a bool, so a loose comparison matches exactly what this does.
                // @pest-mutate-ignore: TrueToFalse
                true,
            );

        // unless / hide_when invert the outcome.
        return $showOnPass ? $passes : ! $passes;
    }

    /**
     * Normalize stored conditions (map or list form) into a list of {field, operator, value}.
     *
     * Anything that is not an array, including a custom JS string condition, yields none.
     *
     * @param  mixed  $conditions  Map `{field: "op value"}` or list `[{field, operator, value}]`.
     * @return list<array{field: string, operator: string, value: string}>
     */
    private function normalizeConditions(mixed $conditions): array
    {
        if (! \is_array($conditions)) {
            return [];
        }

        // List form: imported `[{field, operator, value}]` rules.
        if (array_is_list($conditions)) {
            $out = [];
            foreach ($conditions as $rule) {
                if (! \is_array($rule)) {
                    continue;
                }

                if (! isset($rule['field'])) {
                    continue;
                }

                if (! \is_scalar($rule['field'])) {
                    continue;
                }

                $out[] = [
                    // The field reaches only `data_get()`, which coerces a scalar key as this cast does.
                    // @pest-mutate-ignore: RemoveStringCast
                    'field' => (string) $rule['field'],
                    // A missing operator falls back to `''`, which evaluates as `equals` as every unknown operator does, so any other fallback behaves the same.
                    // @pest-mutate-ignore: EmptyStringToNotEmpty
                    'operator' => \is_scalar($rule['operator'] ?? null) ? (string) $rule['operator'] : '',
                    'value' => $this->normalizeConditionString($rule['value'] ?? ''),
                ];
            }

            return $out;
        }

        // Map form: `{field: "operator value"}` saved by the CP builder.
        $out = [];
        foreach ($conditions as $field => $rhs) {
            foreach (\is_array($rhs) && array_is_list($rhs) ? $rhs : [$rhs] as $single) {
                [$operator, $value] = $this->parseRhs($this->normalizeConditionString($single));
                $out[] = [
                    // The cast only satisfies the declared `field: string` shape: a numeric key
                    // reaches data_get() as an int and reads the same value, so dropping it
                    // changes nothing a test can observe.
                    // @pest-mutate-ignore: RemoveStringCast
                    'field' => (string) $field,
                    'operator' => $operator,
                    'value' => $value,
                ];
            }
        }

        return $out;
    }

    /**
     * Whether stored conditions are a group the public evaluator reads as holding no
     * conditions: an empty group (`[]`, which `{}` also parses to), or a field map in which
     * every field carries an empty list (`{field: []}`).
     *
     * @param  mixed  $conditions  The map or list of conditions stored under a key.
     */
    private function isEmptyGroup(mixed $conditions): bool
    {
        if ($conditions === []) {
            return true;
        }

        if (! \is_array($conditions) || array_is_list($conditions)) {
            return false;
        }

        return \array_all($conditions, static fn (mixed $rhs): bool => $rhs === []);
    }

    /**
     * Split a stored condition string into [operator, value], mirroring Statamic's Converter.
     *
     * The default operator is `==` (equals). The space-delimited prefix match (and last-wins
     * ordering) reproduces `getOperatorFromRhs` / `getValueFromRhs`.
     *
     * @return array{0: string, 1: string}
     */
    private function parseRhs(string $rhs): array
    {
        $operator = '==';
        $detected = false;

        foreach (self::OPERATORS_AND_ALIASES as $candidate) {
            if (preg_match('/^'.preg_quote($candidate, '/').' [^=]/', $rhs) === 1) {
                $operator = $candidate;
                $detected = true;
            }
        }

        // Strip the resolved operator's prefix to recover the value. The strip is keyed on
        // whether an operator was actually detected, not on `$operator === '=='`, so an
        // explicit `== gold` (detected `==`) strips to `gold`, distinct from the bare `gold`
        // default (no operator detected) that is kept verbatim.
        // `preg_replace()` returns null only on a regex error, and the pattern is a quoted literal.
        // @pest-mutate-ignore: RemoveStringCast
        $value = $detected
            ? (string) preg_replace('/^'.preg_quote($operator, '/').'[ ]*/', '', $rhs)
            : $rhs;

        return [$operator, $value];
    }

    /**
     * Evaluate a single condition against the looked-up left-hand value.
     *
     * @param  mixed  $lhs  The submitted value at the condition's field path.
     * @param  string  $operator  The raw operator (alias or canonical).
     * @param  string  $value  The right-hand comparison value (already normalized to a string token).
     */
    private function passesCondition(mixed $lhs, string $operator, string $value): bool
    {
        $op = self::ALIASES[$operator] ?? $operator;

        return match ($op) {
            'contains' => $this->contains($lhs, $value),
            'contains_any' => $this->containsAny($lhs, $value),
            'includes_any' => $this->includesAny($lhs, $value),
            // These two arrive only as a list-form operator (the map-form parser does not detect them), and the list form
            // normalizes an empty right-hand value to `empty`, so `$value` is never `''` here.
            'starts_with' => \is_scalar($lhs) && str_starts_with((string) $lhs, $value),
            'ends_with' => \is_scalar($lhs) && str_ends_with((string) $lhs, $value),
            '>', '>=', '<', '<=' => $this->numericCompare($lhs, $op, $value),
            'not' => ! $this->equals($lhs, $value),
            'custom' => true, // custom JS condition: cannot evaluate server-side
            default => $this->equals($lhs, $value), // equals / == / === / unknown
        };
    }

    /**
     * Equality (with the `empty` / `true` / `false` / `null` special right-hand values).
     */
    private function equals(mixed $lhs, string $value): bool
    {
        // `empty` compares emptiness rather than literal equality.
        if ($value === 'empty') {
            return $this->isEmpty($lhs);
        }

        // Typed special values compare strictly so a text value "true" never equals boolean true.
        $expected = match ($value) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };

        if (\is_bool($expected) || $expected === null) {
            return $lhs === $expected;
        }

        // An array/object left value never equals a scalar (mirrors Statamic's isObject guard).
        if (\is_array($lhs)) {
            return false;
        }

        $left = \is_bool($lhs)
            ? ($lhs ? 'true' : 'false')
            : (\is_scalar($lhs) ? (string) $lhs : '');

        return trim($left) === trim($expected);
    }

    /**
     * Substring (string left value) or membership (array left value) containment.
     */
    private function contains(mixed $lhs, string $value): bool
    {
        if (\is_array($lhs)) {
            return \array_any($lhs, static fn ($item): bool => \is_scalar($item) && (string) $item === $value);
        }

        return \is_scalar($lhs) && str_contains((string) $lhs, $value);
    }

    /**
     * Containment against any of a comma-separated set of values.
     *
     * Each candidate is matched as a literal substring (via {@see self::contains()}), never as a
     * regular expression, so characters like `.`, `(` or `+` in a configured value are not read as
     * a pattern. Statamic's client side matches literally too, since 6.35.0 (#15531); a
     * regex-metacharacter test pins it here.
     */
    private function containsAny(mixed $lhs, string $value): bool
    {
        $candidates = array_filter(array_map(trim(...), explode(',', $value)), static fn (string $v): bool => $v !== '');

        return \array_any($candidates, fn (string $candidate): bool => $this->contains($lhs, $candidate));
    }

    /**
     * Membership in a comma-separated set of values (`includes_any`, statamic/cms 6.35.0, #13135).
     *
     * A list value passes when any of its items is one of the options; a keyed array is an object
     * to the public evaluator and never passes. Any other value must
     * equal one of the options exactly: unlike {@see self::containsAny()}, a part of the value is
     * not enough. The value is read the way Statamic's `prepareLhs` reads it (a falsy value
     * becomes the empty string, `true` becomes `'true'`), and the options are trimmed but kept
     * when empty, as the public form's evaluator in UAMS-Web/uams-statamic
     * (`resources/js/conditions/`) does, so the public page and the notification gate agree.
     */
    private function includesAny(mixed $lhs, string $value): bool
    {
        $options = array_map(trim(...), explode(',', $value));

        if (\is_array($lhs) && array_is_list($lhs)) {
            return \array_any($lhs, static fn ($item): bool => \is_scalar($item) && \in_array((string) $item, $options, true));
        }

        $needle = match (true) {
            $lhs === null, $lhs === false, $lhs === 0, $lhs === 0.0 => '',
            $lhs === true => 'true',
            \is_scalar($lhs) => (string) $lhs,
            default => null,
        };

        return $needle !== null && \in_array($needle, $options, true);
    }

    /**
     * Numeric comparison; a non-numeric operand fails (mirrors JS `Number(x)` → NaN).
     */
    private function numericCompare(mixed $lhs, string $op, string $value): bool
    {
        if (! \is_numeric($lhs) || ! \is_numeric($value)) {
            return false;
        }

        // PHP compares two numeric strings, or a number and a numeric string, numerically, so the casts change no result.
        // @pest-mutate-ignore: RemoveDoubleCast
        $left = (float) $lhs;
        // @pest-mutate-ignore: RemoveDoubleCast
        $right = (float) $value;

        // `$op` is one of the four comparison operators, so the last arm is `<=`.
        return match ($op) {
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            default => $left <= $right,
        };
    }

    /**
     * Statamic-style emptiness: null, empty string, or empty array.
     */
    private function isEmpty(mixed $value): bool
    {
        return in_array($value, [null, '', []], true);
    }

    /**
     * Coerce a raw right-hand value to the string token Statamic stores/compares.
     *
     * null → 'null', '' → 'empty', bool → 'true'/'false', other scalars cast; anything
     * else collapses to 'empty'.
     */
    private function normalizeConditionString(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if ($value === '') {
            return 'empty';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return \is_scalar($value) ? (string) $value : 'empty';
    }
}
