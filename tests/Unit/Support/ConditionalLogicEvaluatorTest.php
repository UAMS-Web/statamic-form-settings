<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use UAMSWeb\FormSettings\Support\ConditionalLogicEvaluator;

/**
 * Behavior of {@see ConditionalLogicEvaluator}, which gates a form email notification
 * (and, later, a confirmation) on its stored `conditional_logic` config, both the CP
 * map form `{field: "operator value"}` and the imported list form
 * `[{field, operator, value}]`, across the if / if_any / unless / unless_any keys and the
 * Statamic operator + special-value set (UAMS-Web/uams-statamic#202).
 *
 * Mutation (UAMS-Web/uams-statamic#3089, macOS, measured on main 287d8ef41, run with
 * FormConfirmationResolverMutationTest): 265 mutants, 199 reported tested and all killed,
 * 0 surviving. The other 66 are on the three class constants and the `match (true)` line,
 * which PCOV cannot line-cover, so Pest reports them uncovered; each was applied by hand
 * (every key, flag, operator and alias removed or flipped) and this file goes red on every
 * one. Nine further mutants are equivalents, annotated `@pest-mutate-ignore` with the reason
 * above each marker in the class:
 *
 * - `RemoveStringCast` on a field handle passed to `data_get()` (twice), which coerces a
 *   scalar key exactly as the cast does, and on the map-form field key for the same reason.
 * - `EmptyStringToNotEmpty` on the missing-operator fallback (twice): `''` evaluates as
 *   `equals`, as every unknown operator does.
 * - `TrueToFalse` on the ALL/ANY `in_array()`: every result is a bool.
 * - `RemoveStringCast` on `preg_replace()`, which returns null only on a regex error.
 * - `RemoveDoubleCast` on both numeric operands: PHP already compares numeric strings
 *   numerically.
 *
 * Dead code deleted here: the string branch of the conditions normalizer (a custom JS string
 * now passes as any non-array does), the empty-value guards on `starts_with`/`ends_with`, and
 * the four aliases of `equals`, which the unknown-operator fallback already covers.
 *
 * @param  array<array-key, mixed>  $values
 *
 * @command  vendor/bin/pest tests/Unit/Support/ConditionalLogicEvaluatorTest.php
 */
function evaluatorPasses(mixed $conditionalLogic, array $values): bool
{
    /** @var array<string, mixed> $values */
    return (new ConditionalLogicEvaluator)->passes($conditionalLogic, $values);
}

it('always passes when there is no evaluable conditional logic', function (mixed $config): void {
    // No gate present → always send (Statamic's default-visible behavior).
    expect(evaluatorPasses($config, ['status' => 'spam']))->toBeTrue();
})->with([
    'null' => [null],
    'non-array scalar' => ['garbage'],
    'empty array' => [[]],
    'only always_save (no condition key)' => [['always_save' => false]],
    // The public page skips a falsy key, so a null group is no condition there either.
    'null if_any group' => [['if_any' => null]],
    'null unless group' => [['unless' => null]],
    'custom JS string condition (not evaluable server-side)' => [['if' => 'custom myFn']],
]);

it('evaluates the CP map form: if = ALL must pass', function (array $values, bool $expected): void {
    // Send only when status is active AND tier equals gold.
    $config = ['if' => ['status' => 'active', 'tier' => 'gold'], 'always_save' => false];

    expect(evaluatorPasses($config, $values))->toBe($expected);
})->with([
    'both match → send' => [['status' => 'active', 'tier' => 'gold'], true],
    'one fails → no send' => [['status' => 'active', 'tier' => 'silver'], false],
    'other fails → no send' => [['status' => 'inactive', 'tier' => 'gold'], false],
    'missing field → no send' => [['status' => 'active'], false],
]);

it('evaluates if_any: ANY passing sends', function (array $values, bool $expected): void {
    $config = ['if_any' => ['status' => 'vip', 'tier' => 'gold']];

    expect(evaluatorPasses($config, $values))->toBe($expected);
})->with([
    'first matches → send' => [['status' => 'vip', 'tier' => 'bronze'], true],
    'second matches → send' => [['status' => 'normal', 'tier' => 'gold'], true],
    'neither matches → no send' => [['status' => 'normal', 'tier' => 'bronze'], false],
]);

it('evaluates unless: send UNLESS all conditions pass (inverse of if)', function (array $values, bool $expected): void {
    // Suppress the notification only when status equals spam.
    $config = ['unless' => ['status' => 'spam']];

    expect(evaluatorPasses($config, $values))->toBe($expected);
})->with([
    'condition met → suppressed' => [['status' => 'spam'], false],
    'condition not met → send' => [['status' => 'ham'], true],
]);

it('evaluates unless_any: suppress when any condition passes', function (array $values, bool $expected): void {
    $config = ['unless_any' => ['status' => 'spam', 'flagged' => 'true']];

    expect(evaluatorPasses($config, $values))->toBe($expected);
})->with([
    'one passes → suppressed' => [['status' => 'spam', 'flagged' => false], false],
    'other passes → suppressed' => [['status' => 'ham', 'flagged' => true], false],
    'none pass → send' => [['status' => 'ham', 'flagged' => false], true],
]);

it('reads the back-compat show_when / hide_when keys', function (mixed $config, array $values, bool $expected): void {
    expect(evaluatorPasses($config, $values))->toBe($expected);
})->with([
    'show_when met → send' => [['show_when' => ['status' => 'active']], ['status' => 'active'], true],
    'show_when unmet → no send' => [['show_when' => ['status' => 'active']], ['status' => 'off'], false],
    'hide_when met → suppressed' => [['hide_when' => ['status' => 'spam']], ['status' => 'spam'], false],
    'hide_when unmet → send' => [['hide_when' => ['status' => 'spam']], ['status' => 'ham'], true],
]);

it('supports the map-form operator syntax', function (string $rhs, mixed $lhs, bool $expected): void {
    $config = ['if' => ['field' => $rhs]];

    expect(evaluatorPasses($config, ['field' => $lhs]))->toBe($expected);
})->with([
    // equals (bare value)
    'equals match' => ['gold', 'gold', true],
    'equals mismatch' => ['gold', 'silver', false],
    // not
    'not match (differs)' => ['not gold', 'silver', true],
    'not mismatch (same)' => ['not gold', 'gold', false],
    // contains (substring)
    'contains substring present' => ['contains vip', 'super-vip-member', true],
    'contains substring absent' => ['contains vip', 'regular', false],
    // numeric comparisons
    'greater than passes' => ['> 18', '21', true],
    'greater than fails' => ['> 18', '12', false],
    'greater-or-equal boundary' => ['>= 18', '18', true],
    'less than passes' => ['< 5', '3', true],
    'non-numeric lhs fails numeric compare' => ['> 18', 'abc', false],
    // empty / not empty
    'empty matches blank' => ['empty', '', true],
    'empty fails non-blank' => ['empty', 'x', false],
    'not empty matches value' => ['not empty', 'x', true],
    'not empty fails blank' => ['not empty', '', false],
]);

it('treats true/false/null right-hand values as typed comparisons', function (string $rhs, mixed $lhs, bool $expected): void {
    $config = ['if' => ['agree' => $rhs]];

    expect(evaluatorPasses($config, ['agree' => $lhs]))->toBe($expected);
})->with([
    'boolean true matches toggle on' => ['true', true, true],
    'boolean true fails toggle off' => ['true', false, false],
    'boolean false matches toggle off' => ['false', false, true],
    'text "true" does not equal boolean true' => ['true', 'true', false],
    'null matches missing/null value' => ['null', null, true],
]);

it('matches a multi-value (array) submission with contains', function (mixed $lhs, bool $expected): void {
    // A checkboxes field stores an array; `contains` tests membership.
    $config = ['if' => ['interests' => 'contains sports']];

    expect(evaluatorPasses($config, ['interests' => $lhs]))->toBe($expected);
})->with([
    'array member present' => [['sports', 'music'], true],
    'array member absent' => [['music', 'art'], false],
]);

it('supports contains_any against a comma-separated set', function (mixed $lhs, bool $expected): void {
    $config = ['if' => ['tag' => 'contains_any vip,staff']];

    expect(evaluatorPasses($config, ['tag' => $lhs]))->toBe($expected);
})->with([
    'string contains one' => ['our-staff-list', true],
    'array contains one' => [['vip'], true],
    'contains none' => ['guest', false],
]);

it('reads includes_any as membership and contains_any as a substring (statamic/cms 6.35.0)', function (string $operator, mixed $lhs, bool $expected): void {
    // The same value against the same options: `includes_any` needs the whole value to be one of
    // them, `contains_any` only needs one of them to appear inside it (UAMS-Web/uams-statamic#2805).
    $config = ['if' => ['tag' => "{$operator} 5,vip, staff"]];

    expect(evaluatorPasses($config, ['tag' => $lhs]))->toBe($expected);
})->with([
    'includes_any: string equal to an option' => ['includes_any', 'vip', true],
    'includes_any: string equal to a trimmed option' => ['includes_any', 'staff', true],
    'includes_any: string only containing an option' => ['includes_any', 'our-staff-list', false],
    'contains_any: string only containing an option' => ['contains_any', 'our-staff-list', true],
    'includes_any: number equal to an option' => ['includes_any', 5, true],
    'includes_any: number only containing an option' => ['includes_any', 15, false],
    'contains_any: number only containing an option' => ['contains_any', 15, true],
    'includes_any: array sharing an option' => ['includes_any', ['guest', 'vip'], true],
    // Deliberately looser than the public evaluator, which compares items strictly (5 !== '5'):
    // items are compared as text here, as `contains` already does.
    'includes_any: array of numbers sharing an option (compared as text)' => ['includes_any', [3, 5], true],
    'includes_any: nested array is not an item' => ['includes_any', [['vip']], false],
    'includes_any: keyed array is an object, not a list' => ['includes_any', ['k' => 'vip'], false],
    'includes_any: object value' => ['includes_any', (object) ['tag' => 'vip'], false],
    'includes_any: array whose items only contain an option' => ['includes_any', ['vip-plus'], false],
    'contains_any: array whose items only contain an option' => ['contains_any', ['vip-plus'], false],
    'includes_any: no option matches' => ['includes_any', 'guest', false],
    'includes_any: missing value' => ['includes_any', null, false],
]);

it('reads includes_any the way the public evaluator reads the submitted value', function (string $rhs, mixed $lhs, bool $expected): void {
    // `prepareLhs` turns a falsy value into '' and `true` into 'true'; options are trimmed and
    // an empty one is kept, so a blank submission matches an option list with a blank in it.
    expect(evaluatorPasses(['if' => ['f' => $rhs]], ['f' => $lhs]))->toBe($expected);
})->with([
    'true matches the option true' => ['includes_any true,no', true, true],
    'false is blank, not an option' => ['includes_any false,no', false, false],
    'zero is blank, not an option' => ['includes_any 0,1', 0, false],
    'float zero is blank, not an option' => ['includes_any 0,1', 0.0, false],
    'string zero is still zero' => ['includes_any 0,1', '0', true],
    'blank matches an empty option' => ['includes_any a,,b', '', true],
    'missing value matches an empty option' => ['includes_any a,,b', null, true],
    'blank without an empty option' => ['includes_any a,b', '', false],
    // Compared as text, as JavaScript does: PHP's loose `==` would call '10' and '1e1' equal.
    'numeric-looking strings are compared as text' => ['includes_any 1e1', '10', false],
    'numeric-looking array items are compared as text' => ['includes_any 1e1', ['10'], false],
]);

it('reads includes_any on an imported routing rule', function (mixed $lhs, bool $expected): void {
    /** @var array<string, mixed> $values */
    $values = ['dept' => $lhs];

    expect((new ConditionalLogicEvaluator)->matchesRule(
        ['field' => 'dept', 'operator' => 'includes_any', 'value' => 'cardiology,oncology'],
        $values,
    ))->toBe($expected);
})->with([
    'member' => ['oncology', true],
    'substring only' => ['pediatric-oncology', false],
]);

it('reads a list of conditions on one field as separate conditions', function (string $key, mixed $lhs, bool $expected): void {
    // The CP builder saves `{field: [rhs, rhs]}` when one field has more than one condition
    // (statamic/cms 6.31.0, #14593). Each entry is its own condition under the key's ALL/ANY logic.
    $config = [$key => ['age' => ['>= 18', '< 65']]];

    expect(evaluatorPasses($config, ['age' => $lhs]))->toBe($expected);
})->with([
    'if: inside the range' => ['if', '40', true],
    'if: one condition fails' => ['if', '70', false],
    'if_any: one condition passes' => ['if_any', '70', true],
    'unless: both pass, suppressed' => ['unless', '40', false],
]);

it('reads an empty list on one field as the public evaluator does', function (string $key, array $conditions, bool $expected): void {
    // `{field: []}` yields no conditions. The public evaluator still applies its logic to
    // them (ALL of none passes, ANY of none fails) and then the key's inversion, rather than
    // treating the group as absent (UAMS-Web/uams-statamic#2889). The value of the field makes no difference.
    $config = [$key => $conditions];

    expect(evaluatorPasses($config, ['f' => 'vip', 'g' => 'vip']))->toBe($expected);
    expect(evaluatorPasses($config, ['f' => 'other', 'g' => 'other']))->toBe($expected);
})->with([
    'if' => ['if', ['f' => []], true],
    'if_any' => ['if_any', ['f' => []], false],
    'unless' => ['unless', ['f' => []], false],
    'unless_any' => ['unless_any', ['f' => []], true],
    'show_when' => ['show_when', ['f' => []], true],
    'show_when_any' => ['show_when_any', ['f' => []], false],
    'hide_when' => ['hide_when', ['f' => []], false],
    'hide_when_any' => ['hide_when_any', ['f' => []], true],
    'if_any, two fields' => ['if_any', ['f' => [], 'g' => []], false],
    'unless, two fields' => ['unless', ['f' => [], 'g' => []], false],
]);

it('reads an empty group as the public evaluator does', function (string $key, bool $expected): void {
    // `[]` and `{}` both parse to an empty array. The public page reads either as a group with
    // no conditions and applies its logic to it (ALL of none passes, ANY of none fails), then
    // the key's inversion, the same as a field map of empty lists (UAMS-Web/uams-statamic#2889, UAMS-Web/uams-statamic#2895). The value of
    // the field makes no difference.
    $fromYaml = Yaml::parse("{$key}: {}");

    expect($fromYaml)->toBe([$key => []]);

    foreach (['vip', '', null] as $value) {
        expect(evaluatorPasses([$key => []], ['f' => $value]))->toBe($expected)
            ->and(evaluatorPasses($fromYaml, ['f' => $value]))->toBe($expected);
    }
})->with([
    'if' => ['if', true],
    'if_any' => ['if_any', false],
    'unless' => ['unless', false],
    'unless_any' => ['unless_any', true],
    'show_when' => ['show_when', true],
    'show_when_any' => ['show_when_any', false],
    'hide_when' => ['hide_when', false],
    'hide_when_any' => ['hide_when_any', true],
]);

it('still passes a list whose rules all name no field', function (string $key): void {
    // Not an empty group: the list holds rules, and the gate drops each one for naming no
    // field, as before. Only `[]` itself is read as the public page reads it.
    expect(evaluatorPasses([$key => [['operator' => 'equals', 'value' => 'x']]], ['f' => 'y']))->toBeTrue();
})->with(['if_any', 'unless']);

it('still evaluates the other fields when only one field has an empty list', function (string $key, mixed $lhs, bool $expected): void {
    // The empty list adds no condition, so the rule is decided by `g` alone, as before.
    $config = [$key => ['f' => [], 'g' => 'vip']];

    expect(evaluatorPasses($config, ['f' => $lhs, 'g' => $lhs]))->toBe($expected);
})->with([
    'if: match' => ['if', 'vip', true],
    'if: no match' => ['if', 'other', false],
    'if_any: match' => ['if_any', 'vip', true],
    'if_any: no match' => ['if_any', 'other', false],
    'unless: match' => ['unless', 'vip', false],
    'unless: no match' => ['unless', 'other', true],
]);

it('reads a keyed array on one field as one value, not a list of conditions', function (): void {
    // Only a list is several conditions; anything else is coerced as before, to the `empty` check.
    $config = ['if' => ['f' => ['a' => '>= 18']]];

    expect(evaluatorPasses($config, ['f' => '']))->toBeTrue();
    expect(evaluatorPasses($config, ['f' => '40']))->toBeFalse();
});

it('evaluates the imported list form [{field, operator, value}]', function (array $rules, array $values, bool $expected): void {
    $config = ['if' => $rules];

    expect(evaluatorPasses($config, $values))->toBe($expected);
})->with([
    'is operator matches' => [
        [['field' => 'status', 'operator' => 'is', 'value' => 'active']],
        ['status' => 'active'],
        true,
    ],
    'isnt operator differs' => [
        [['field' => 'status', 'operator' => 'isnt', 'value' => 'spam']],
        ['status' => 'ham'],
        true,
    ],
    'contains operator' => [
        [['field' => 'tags', 'operator' => 'contains', 'value' => 'vip']],
        ['tags' => 'a-vip-b'],
        true,
    ],
    'multiple rules, all must pass (if)' => [
        [
            ['field' => 'a', 'operator' => 'is', 'value' => 'x'],
            ['field' => 'b', 'operator' => 'is', 'value' => 'y'],
        ],
        ['a' => 'x', 'b' => 'z'],
        false,
    ],
    'malformed rule (no field) is skipped' => [
        [['operator' => 'is', 'value' => 'x']],
        ['status' => 'whatever'],
        true,
    ],
]);

it('resolves dot-notation field paths against nested values', function (): void {
    $config = ['if' => ['address.state' => 'AR']];

    expect(evaluatorPasses($config, ['address' => ['state' => 'AR']]))->toBeTrue();
    expect(evaluatorPasses($config, ['address' => ['state' => 'TX']]))->toBeFalse();
});

it('strips an explicit equality/inequality operator prefix in the map form', function (string $rhs, mixed $lhs, bool $expected): void {
    // A detected `==` / `===` / `!=` prefix is stripped to the bare value (it must not be
    // confused with the default no-operator sentinel, which keeps the value verbatim).
    expect(evaluatorPasses(['if' => ['f' => $rhs]], ['f' => $lhs]))->toBe($expected);
})->with([
    'explicit == matches bare value' => ['== gold', 'gold', true],
    'explicit == mismatches' => ['== gold', 'silver', false],
    'strict === matches' => ['=== gold', 'gold', true],
    'inequality != differs' => ['!= gold', 'silver', true],
    'inequality != same fails' => ['!= gold', 'gold', false],
]);

it('honors imported Gravity Forms routing operators via matchesRule', function (array $rule, array $values, bool $expected): void {
    // greater_than/less_than (+_or_equal) and starts_with/ends_with arrive on routing rules in the
    // list form. They must map to numeric/prefix/suffix semantics, not silently fall through to equality.
    /** @var array<string, mixed> $rule */
    /** @var array<string, mixed> $values */
    expect((new ConditionalLogicEvaluator)->matchesRule($rule, $values))->toBe($expected);
})->with([
    'greater_than passes above' => [['field' => 'age', 'operator' => 'greater_than', 'value' => '18'], ['age' => '25'], true],
    'greater_than fails at boundary' => [['field' => 'age', 'operator' => 'greater_than', 'value' => '18'], ['age' => '18'], false],
    'greater_than fails below' => [['field' => 'age', 'operator' => 'greater_than', 'value' => '18'], ['age' => '12'], false],
    'less_than passes below' => [['field' => 'age', 'operator' => 'less_than', 'value' => '65'], ['age' => '40'], true],
    'greater_than_or_equal at boundary' => [['field' => 'age', 'operator' => 'greater_than_or_equal', 'value' => '18'], ['age' => '18'], true],
    'starts_with prefix present' => [['field' => 'name', 'operator' => 'starts_with', 'value' => 'Dr'], ['name' => 'Dr. Smith'], true],
    'starts_with prefix absent' => [['field' => 'name', 'operator' => 'starts_with', 'value' => 'Dr'], ['name' => 'Mr. Smith'], false],
    'ends_with suffix present' => [['field' => 'file', 'operator' => 'ends_with', 'value' => '.pdf'], ['file' => 'report.pdf'], true],
    'ends_with suffix absent' => [['field' => 'file', 'operator' => 'ends_with', 'value' => '.pdf'], ['file' => 'report.docx'], false],
]);

it('matches contains_any candidates as literal substrings, not regex', function (): void {
    // '.' and '|' are literal here, as they are on Statamic's client side since 6.35.0
    // (statamic/cms#15531). A config value is never read as a pattern.
    expect(evaluatorPasses(['if' => ['t' => 'contains_any a.c']], ['t' => 'axc']))->toBeFalse();
    expect(evaluatorPasses(['if' => ['t' => 'contains_any a.c']], ['t' => 'a.c-zone']))->toBeTrue();
    expect(evaluatorPasses(['if' => ['t' => 'contains_any x|y']], ['t' => 'zxz']))->toBeFalse();
});

it('gates form notifications independently, each config evaluated against the same values', function (): void {
    // The same submission, two notifications: one whose condition passes, one whose fails.
    $values = ['department' => 'cardiology'];

    $sends = ['if' => ['department' => 'cardiology']];
    $skips = ['if' => ['department' => 'oncology']];

    expect(evaluatorPasses($sends, $values))->toBeTrue();
    expect(evaluatorPasses($skips, $values))->toBeFalse();
});

// ---
// Branch-level coverage (UAMS-Web/uams-statamic#3089): each case below pins one decision the evaluator makes, so the
// class can move to the form settings add-on mutation-clean.
// ---

/**
 * Evaluate one imported routing rule.
 *
 * @param  array<string, mixed>  $rule
 * @param  array<array-key, mixed>  $values
 */
function evaluatorMatchesRule(array $rule, array $values): bool
{
    /** @var array<string, mixed> $values */
    return (new ConditionalLogicEvaluator)->matchesRule($rule, $values);
}

/**
 * Evaluate one list-form rule under `if`.
 *
 * @param  array<string, mixed>  $rule
 * @param  array<array-key, mixed>  $values
 */
function evaluatorPassesRule(array $rule, array $values): bool
{
    return evaluatorPasses(['if' => [$rule]], $values);
}

it('matches no routing rule whose field is missing or not a scalar', function (array $rule): void {
    expect(evaluatorMatchesRule(mustBeStringKeyed($rule + ['operator' => 'equals', 'value' => ''], 'rule'), ['' => '', 'Array' => 'Array']))->toBeFalse();
})->with([
    'no field' => [[]],
    'a list field' => [['field' => ['Array'], 'value' => 'Array']],
]);

it('reads a numeric routing operator as equals', function (): void {
    expect(evaluatorMatchesRule(['field' => 'tier', 'operator' => 5, 'value' => 'gold'], ['tier' => 'gold']))->toBeTrue();
});

it('reads a rule with no value as an emptiness check, in routing and in the list form', function (): void {
    expect(evaluatorMatchesRule(['field' => 'note', 'operator' => 'equals'], ['note' => []]))->toBeTrue()
        ->and(evaluatorPassesRule(['field' => 'note', 'operator' => 'equals'], ['note' => []]))->toBeTrue();
});

it('reads a numeric list-form operator as equals', function (): void {
    expect(evaluatorPassesRule(['field' => 'tier', 'operator' => 5, 'value' => 'gold'], ['tier' => 'gold']))->toBeTrue();
});

it('skips a list entry that is not a usable rule and still evaluates the rules after it', function (mixed $broken): void {
    expect(evaluatorPasses(['if' => [$broken, ['field' => 'tier', 'operator' => 'equals', 'value' => 'gold']]], ['tier' => 'silver']))
        ->toBeFalse();
})->with([
    'not an array' => ['not a rule'],
    'no field' => [['operator' => 'equals', 'value' => 'x']],
    'a field that is not a scalar' => [['field' => ['tier'], 'value' => 'x']],
]);

it('keeps a map-form value that only starts with an operator, without its space, verbatim', function (): void {
    expect(evaluatorPasses(['if' => ['tier' => '==gold']], ['tier' => 'gold']))->toBeFalse()
        ->and(evaluatorPasses(['if' => ['tier' => '==gold']], ['tier' => '==gold']))->toBeTrue();
});

it('reads starts_with and ends_with on scalar values only', function (string $operator, mixed $lhs, string $value, bool $expected): void {
    expect(evaluatorPassesRule(['field' => 'code', 'operator' => $operator, 'value' => $value], ['code' => $lhs]))->toBe($expected);
})->with([
    'a number starts with its digit' => ['starts_with', 123, '1', true],
    'a number ends with its digit' => ['ends_with', 123, '3', true],
    'a list never starts with anything' => ['starts_with', ['Array'], 'Arr', false],
    'a list never ends with anything' => ['ends_with', ['Array'], 'ray', false],
]);

it('passes a custom JS condition in the list form, whatever the value', function (): void {
    expect(evaluatorPassesRule(['field' => 'tier', 'operator' => 'custom', 'value' => 'isGold'], ['tier' => 'silver']))->toBeTrue();
});

it('never finds a list value equal to a scalar, even a blank one', function (string $value): void {
    expect(evaluatorPassesRule(['field' => 'tags', 'operator' => 'equals', 'value' => $value], ['tags' => ['a']]))->toBeFalse();
})->with([
    'its own item' => ['a'],
    'a space' => [' '],
]);

it('compares a boolean left value as the word true or false against an untyped value', function (bool $lhs, string $value): void {
    expect(evaluatorPassesRule(['field' => 'agree', 'operator' => 'equals', 'value' => $value], ['agree' => $lhs]))->toBeTrue();
})->with([
    'true' => [true, ' true'],
    'false' => [false, ' false'],
]);

it('compares a number, a missing value and padded text as trimmed strings', function (mixed $lhs, string $value): void {
    expect(evaluatorPassesRule(['field' => 'x', 'operator' => 'equals', 'value' => $value], ['x' => $lhs]))->toBeTrue();
})->with([
    'a number' => [5, '5'],
    'a missing value against a space' => [null, ' '],
    'padded on the left value' => [' gold ', 'gold'],
    'padded on the right value' => ['gold', ' gold'],
]);

it('matches a numeric list item with contains', function (): void {
    expect(evaluatorPassesRule(['field' => 'ids', 'operator' => 'contains', 'value' => '5'], ['ids' => [5]]))->toBeTrue();
});

it('ignores empty candidates in a contains_any list', function (): void {
    expect(evaluatorPassesRule(['field' => 'note', 'operator' => 'contains_any', 'value' => 'a,,b'], ['note' => 'zzz']))->toBeFalse();
});

it('reads includes_any against a single scalar value', function (): void {
    expect(evaluatorPassesRule(['field' => 'tier', 'operator' => 'includes_any', 'value' => 'gold,silver'], ['tier' => 'gold']))->toBeTrue();
});

it('never compares a non-numeric value numerically', function (): void {
    expect(evaluatorPassesRule(['field' => 'age', 'operator' => '<', 'value' => '5'], ['age' => 'abc']))->toBeFalse();
});

it('compares at the boundary with the strict and inclusive operators', function (string $operator, int $lhs, bool $expected): void {
    expect(evaluatorPassesRule(['field' => 'age', 'operator' => $operator, 'value' => '5'], ['age' => $lhs]))->toBe($expected);
})->with([
    '5 < 5' => ['<', 5, false],
    '5 <= 5' => ['<=', 5, true],
    '4 <= 5' => ['<=', 4, true],
    '6 <= 5' => ['<=', 6, false],
]);

it('treats null, an empty string and an empty list as empty', function (mixed $lhs): void {
    expect(evaluatorPassesRule(['field' => 'note', 'operator' => 'equals', 'value' => 'empty'], ['note' => $lhs]))->toBeTrue();
})->with([
    'null' => [null],
    'an empty string' => [''],
    'an empty list' => [[]],
]);

it('reads a typed rule value as its token', function (mixed $value, mixed $lhs, bool $expected): void {
    expect(evaluatorPassesRule(['field' => 'x', 'operator' => 'equals', 'value' => $value], ['x' => $lhs]))->toBe($expected);
})->with([
    'an empty string reads as empty, so an empty list matches' => ['', [], true],
    'true matches boolean true' => [true, true, true],
    'false matches boolean false' => [false, false, true],
    'a number matches its digits' => [5, '5', true],
]);

it('reads a null map-form value as null, which an empty string does not equal', function (): void {
    expect(evaluatorPasses(['if' => ['x' => null]], ['x' => '']))->toBeFalse()
        ->and(evaluatorPasses(['if' => ['x' => null]], ['x' => null]))->toBeTrue();
});

it('detects each map-form operator and alias before its value', function (string $rhs, mixed $lhs): void {
    expect(evaluatorPasses(['if' => ['x' => $rhs]], ['x' => $lhs]))->toBeTrue();
})->with([
    'is' => ['is gold', 'gold'],
    'isnt' => ['isnt gold', 'silver'],
    '!==' => ['!== gold', 'silver'],
    '<=' => ['<= 5', 4],
    'includes' => ['includes a', ['a']],
    'custom' => ['custom isGold', 'silver'],
]);

it('evaluates hide_when_any: hide when any condition passes', function (array $values, bool $expected): void {
    expect(evaluatorPasses(['hide_when_any' => ['a' => 'yes', 'b' => 'yes']], $values))->toBe($expected);
})->with([
    'one passes, so hidden' => [['a' => 'yes', 'b' => 'no'], false],
    'none passes, so shown' => [['a' => 'no', 'b' => 'no'], true],
]);

it('reads the imported or-equal routing words as their operators', function (string $operator, int $lhs): void {
    expect(evaluatorMatchesRule(['field' => 'age', 'operator' => $operator, 'value' => '5'], ['age' => $lhs]))->toBeTrue();
})->with([
    'greater_than_or_equal' => ['greater_than_or_equal', 6],
    'less_than_or_equal' => ['less_than_or_equal', 4],
]);
