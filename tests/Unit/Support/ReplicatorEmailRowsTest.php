<?php

declare(strict_types=1);

use UAMSWeb\FormSettings\Support\ReplicatorEmailRows;

/**
 * Behavior of ReplicatorEmailRows (src/Support/ReplicatorEmailRows.php): the
 * transformation that turns a replicator's raw submitted rows into labelled set blocks
 * for notification emails (UAMS-Web/uams-statamic#2055), replacing the escaped-JSON blob Statamic's automagic
 * template produced (UAMS-Web/uams-statamic#2051).
 *
 * The rows arrive raw rather than augmented, so option keys and booleans are resolved here
 * from the blueprint config. These cases pin the mapping rules, the internal-key suppression,
 * and the deliberate JSON fallback for nested replicators.
 *
 * Mutation (UAMS-Web/uams-statamic#3089, macOS, measured on main 287d8ef41): 128 mutants, 120 reported tested and all
 * killed, 0 surviving. Three further mutants are equivalents, annotated `@pest-mutate-ignore` and
 * so not counted, each with its reason above the marker in the class:
 *
 * - `TrueToFalse` on the `$seen` marker: it is read with `isset()`, which is true for `false`.
 * - `EmptyStringToNotEmpty` on the fieldtype fallback: it is only compared against fieldtype
 *   names, so any non-matching string behaves the same.
 * - `UnwrapArrayMap` in the flat-list branch: `implode()` casts each scalar as `(string)` does.
 *
 * The other 8 are `RemoveArrayItem` on the two class constants, which PCOV cannot line-cover,
 * so Pest reports them uncovered rather than running them. Each was removed by hand and the
 * file goes red without it; the option-fieldtype dataset below exists to kill two of them.
 *
 * @command  vendor/bin/pest tests/Unit/Support/ReplicatorEmailRowsTest.php
 */

/**
 * The field config used by most cases: one `participant` set with mixed subfield types.
 *
 * @return array<string, mixed>
 */
function pestReplicatorRowsConfig(): array
{
    return [
        'sets' => [
            'main' => [
                'sets' => [
                    'participant' => [
                        'display' => 'Participant',
                        'fields' => [
                            ['handle' => 'first_name', 'field' => ['type' => 'text', 'display' => 'First Name']],
                            ['handle' => 'agrees', 'field' => ['type' => 'toggle', 'display' => 'Agrees']],
                            ['handle' => 'color', 'field' => ['type' => 'select', 'display' => 'Color', 'options' => ['r' => 'Red', 'b' => 'Blue']]],
                            ['handle' => 'tags', 'field' => ['type' => 'checkboxes', 'display' => 'Tags', 'options' => ['x' => 'Ex', 'y' => 'Why']]],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

it('labels each set and each subfield from the blueprint', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'enabled' => true, 'first_name' => 'Ada']],
        pestReplicatorRowsConfig()
    );

    expect($rows)->toHaveCount(1);
    expect($rows[0]['set_label'])->toBe('Participant');
    expect($rows[0]['pairs'][0])->toBe(['label' => 'First Name', 'text' => 'Ada']);
});

it('renders a toggle as Yes or No rather than a raw boolean', function (mixed $raw, string $expected): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'agrees' => $raw]],
        pestReplicatorRowsConfig()
    );

    expect($rows[0]['pairs'][0])->toBe(['label' => 'Agrees', 'text' => $expected]);
})->with([
    'boolean true' => [true, 'Yes'],
    'boolean false' => [false, 'No'],
    'posted 1' => ['1', 'Yes'],
    'posted 0' => ['0', 'No'],
]);

it('resolves an option key to its configured label', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'color' => 'b']],
        pestReplicatorRowsConfig()
    );

    expect($rows[0]['pairs'][0])->toBe(['label' => 'Color', 'text' => 'Blue']);
});

it('joins a multi-option value using its labels', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'tags' => ['x', 'y']]],
        pestReplicatorRowsConfig()
    );

    expect($rows[0]['pairs'][0])->toBe(['label' => 'Tags', 'text' => 'Ex, Why']);
});

it('falls back to the option key when no label is configured', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'color' => 'unmapped']],
        pestReplicatorRowsConfig()
    );

    expect($rows[0]['pairs'][0]['text'])->toBe('unmapped');
});

it('excludes a set that was toggled off', function (): void {
    $rows = ReplicatorEmailRows::build([
        ['type' => 'participant', 'enabled' => true, 'first_name' => 'Ada'],
        ['type' => 'participant', 'enabled' => false, 'first_name' => 'Hidden'],
        ['type' => 'participant', 'enabled' => '0', 'first_name' => 'AlsoHidden'],
    ], pestReplicatorRowsConfig());

    expect($rows)->toHaveCount(1);
    expect($rows[0]['pairs'][0]['text'])->toBe('Ada');
});

it('never emits the internal id, type or enabled keys as pairs', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'enabled' => true, 'id' => 'abc', '_id' => 'def', 'first_name' => 'Ada']],
        pestReplicatorRowsConfig()
    );

    $labels = array_column($rows[0]['pairs'], 'label');
    expect($labels)->toBe(['First Name']);
});

it('still renders a submitted key the blueprint does not describe', function (): void {
    // Nothing submitted should silently vanish, even if the blueprint has moved on.
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'first_name' => 'Ada', 'legacy_note' => 'kept']],
        pestReplicatorRowsConfig()
    );

    expect($rows[0]['pairs'])->toContain(['label' => 'Legacy note', 'text' => 'kept']);
});

it('humanizes labels when the set or field carries no display', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'some_set', 'some_field' => 'v']],
        ['sets' => ['main' => ['sets' => ['some_set' => ['fields' => []]]]]]
    );

    expect($rows[0]['set_label'])->toBe('Some set');
    expect($rows[0]['pairs'][0]['label'])->toBe('Some field');
});

it('falls back to JSON for a nested replicator rather than rendering nothing', function (): void {
    // Documented limitation: only one level is flattened. Lossless beats legible here.
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'first_name' => 'Ada', 'children' => [['type' => 'kid', 'name' => 'Bee']]]],
        pestReplicatorRowsConfig()
    );

    $text = $rows[0]['pairs'][1]['text'];
    expect($text)->toContain('Bee');
    expect($text)->toStartWith('[');
});

it('joins a flat list without JSON', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'aliases' => ['one', 'two']]],
        pestReplicatorRowsConfig()
    );

    expect($rows[0]['pairs'][0]['text'])->toBe('one, two');
});

it('returns nothing for a value that is not a list of rows', function (mixed $value): void {
    expect(ReplicatorEmailRows::build($value, pestReplicatorRowsConfig()))->toBe([]);
})->with([
    'null' => [null],
    'string' => ['nope'],
    'int' => [7],
]);

it('survives a config with no sets at all', function (): void {
    $rows = ReplicatorEmailRows::build([['type' => 'participant', 'first_name' => 'Ada']], []);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['set_label'])->toBe('Participant');
    expect($rows[0]['pairs'][0])->toBe(['label' => 'First name', 'text' => 'Ada']);
});

it('skips a malformed row without abandoning the rows after it', function (): void {
    // A `continue` rather than a `break`: one bad row must not truncate the email.
    $rows = ReplicatorEmailRows::build(
        ['not-an-array', ['type' => 'participant', 'first_name' => 'Ada']],
        pestReplicatorRowsConfig()
    );

    expect($rows)->toHaveCount(1);
    expect($rows[0]['pairs'][0]['text'])->toBe('Ada');
});

it('humanizes a set label whose display is an empty string', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'some_set', 'v' => '1']],
        ['sets' => ['main' => ['sets' => ['some_set' => ['display' => '', 'fields' => []]]]]]
    );

    expect($rows[0]['set_label'])->toBe('Some set');
});

it('humanizes a subfield label whose display is an empty string', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'p', 'some_field' => 'v']],
        ['sets' => ['main' => ['sets' => ['p' => ['display' => 'P', 'fields' => [
            ['handle' => 'some_field', 'field' => ['type' => 'text', 'display' => '']],
        ]]]]]]
    );

    expect($rows[0]['pairs'][0]['label'])->toBe('Some field');
});

it('labels a set whose type is missing rather than emitting a blank heading', function (): void {
    $rows = ReplicatorEmailRows::build([['first_name' => 'Ada']], pestReplicatorRowsConfig());

    expect($rows[0]['set_label'])->toBe('Set');
});

it('renders a numerically-keyed submitted value', function (): void {
    // The trailing walk casts the key, so an integer handle must not fatal or vanish.
    $rows = ReplicatorEmailRows::build([['type' => 'participant', 7 => 'seven']], pestReplicatorRowsConfig());

    expect($rows[0]['pairs'])->toContain(['label' => '7', 'text' => 'seven']);
});

it('falls back to the option key when the configured label is an empty string', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'p', 'color' => 'r']],
        ['sets' => ['main' => ['sets' => ['p' => ['display' => 'P', 'fields' => [
            ['handle' => 'color', 'field' => ['type' => 'select', 'display' => 'Color', 'options' => ['r' => '']]],
        ]]]]]]
    );

    expect($rows[0]['pairs'][0]['text'])->toBe('r');
});

it('resolves a numeric option key against its label', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'p', 'n' => 2]],
        ['sets' => ['main' => ['sets' => ['p' => ['display' => 'P', 'fields' => [
            ['handle' => 'n', 'field' => ['type' => 'select', 'display' => 'N', 'options' => [2 => 'Two']]],
        ]]]]]]
    );

    expect($rows[0]['pairs'][0]['text'])->toBe('Two');
});

it('renders an empty string for a non-scalar option value', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'p', 'color' => [['deep' => 1]]]],
        ['sets' => ['main' => ['sets' => ['p' => ['display' => 'P', 'fields' => [
            ['handle' => 'color', 'field' => ['type' => 'select', 'display' => 'Color', 'options' => ['r' => 'Red']]],
        ]]]]]]
    );

    expect($rows[0]['pairs'][0]['text'])->toBe('');
});

it('skips a set definition that is not an array', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'p', 'first_name' => 'Ada']],
        ['sets' => ['main' => ['sets' => ['p' => 'not-an-array']]]]
    );

    expect($rows[0]['set_label'])->toBe('P');
});

it('keeps the sets after a disabled one', function (): void {
    // A `continue` rather than a `break`: a set toggled off must not truncate the rest.
    $rows = ReplicatorEmailRows::build([
        ['type' => 'participant', 'enabled' => false, 'first_name' => 'Hidden'],
        ['type' => 'participant', 'enabled' => true, 'first_name' => 'Ada'],
    ], pestReplicatorRowsConfig());

    expect($rows)->toHaveCount(1);
    expect($rows[0]['pairs'][0]['text'])->toBe('Ada');
});

it('prefers a configured display over the humanized handle', function (): void {
    // Pins that the display is actually read, not merely coincident with the humanized handle.
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'first_name' => 'Ada']],
        ['sets' => ['main' => ['sets' => ['participant' => ['display' => 'Waiver Row', 'fields' => [
            ['handle' => 'first_name', 'field' => ['type' => 'text', 'display' => 'Given Name']],
        ]]]]]]
    );

    expect($rows[0]['set_label'])->toBe('Waiver Row');
    expect($rows[0]['pairs'][0]['label'])->toBe('Given Name');
});

it('stringifies non-string scalars in a flat list', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'counts' => [1, 2, true]]],
        pestReplicatorRowsConfig()
    );

    expect($rows[0]['pairs'][0]['text'])->toBe('1, 2, 1');
});

it('stringifies a non-string scalar value', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'first_name' => 42]],
        pestReplicatorRowsConfig()
    );

    expect($rows[0]['pairs'][0]['text'])->toBe('42');
});

it('keeps slashes and unicode unescaped in the nested-replicator fallback', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'nested' => [['url' => 'https://uams.edu/a/b', 'name' => 'Ré'], 'x']]],
        pestReplicatorRowsConfig()
    );

    $text = $rows[0]['pairs'][0]['text'];
    expect($text)->toContain('https://uams.edu/a/b');
    expect($text)->toContain('Ré');
});

it('returns nothing for a non-list value without raising a PHP warning', function (mixed $value): void {
    $warnings = [];
    set_error_handler(function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        $rows = ReplicatorEmailRows::build($value, pestReplicatorRowsConfig());
    } finally {
        restore_error_handler();
    }

    expect($rows)->toBe([])
        ->and($warnings)->toBe([]);
})->with([
    'null' => [null],
    'string' => ['nope'],
]);

it('renders an option key that is an integer with no label as that integer', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'color' => 5]],
        pestReplicatorRowsConfig()
    );

    expect($rows[0]['pairs'][0]['text'])->toBe('5');
});

it('falls back to the option key when the configured label is false', function (): void {
    $config = pestReplicatorRowsConfig();
    data_set($config, 'sets.main.sets.participant.fields.2.field.options', ['r' => false]);

    $rows = ReplicatorEmailRows::build([['type' => 'participant', 'color' => 'r']], mustBeArray($config, 'config'));

    expect($rows[0]['pairs'][0]['text'])->toBe('r');
});

it('renders an integer option label as text', function (): void {
    $config = pestReplicatorRowsConfig();
    data_set($config, 'sets.main.sets.participant.fields.2.field.options', ['r' => 7]);

    $rows = ReplicatorEmailRows::build([['type' => 'participant', 'color' => 'r']], mustBeArray($config, 'config'));

    expect($rows[0]['pairs'][0]['text'])->toBe('7');
});

it('reads a posted enabled flag as words, not as PHP truthiness', function (mixed $enabled, int $kept): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'enabled' => $enabled, 'first_name' => 'Ada']],
        pestReplicatorRowsConfig()
    );

    expect($rows)->toHaveCount($kept);
})->with([
    'the word false' => ['false', 0],
    'the word off' => ['off', 0],
    'the word no' => ['no', 0],
    'a word that is not a flag' => ['maybe', 1],
    'an empty list' => [[], 0],
]);

it('renders an empty string when the nested value cannot be encoded as JSON', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'children' => [['name' => "\xB1\x31"]]]],
        pestReplicatorRowsConfig()
    );

    expect($rows[0]['pairs'][0])->toBe(['label' => 'Children', 'text' => '']);
});

it('renders an empty string for a value that is neither a list nor a scalar', function (): void {
    $rows = ReplicatorEmailRows::build(
        [['type' => 'participant', 'first_name' => null]],
        pestReplicatorRowsConfig()
    );

    expect($rows[0]['pairs'][0])->toBe(['label' => 'First Name', 'text' => '']);
});

it('reads no set definitions from a sets value that is not a list, without a PHP warning', function (): void {
    $warnings = [];
    set_error_handler(function (int $errno, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        $rows = ReplicatorEmailRows::build([['type' => 'participant', 'first_name' => 'Ada']], ['sets' => 'participant']);
    } finally {
        restore_error_handler();
    }

    expect($warnings)->toBe([])
        ->and($rows[0]['set_label'])->toBe('Participant')
        ->and($rows[0]['pairs'])->toBe([['label' => 'First name', 'text' => 'Ada']]);
});

it('keeps reading the set groups after one that is not an array', function (): void {
    $config = pestReplicatorRowsConfig();
    $config['sets'] = ['broken' => 'not a group'] + mustBeArray($config['sets'], 'sets');

    $rows = ReplicatorEmailRows::build([['type' => 'participant', 'first_name' => 'Ada']], $config);

    expect($rows[0]['set_label'])->toBe('Participant')
        ->and($rows[0]['pairs'][0])->toBe(['label' => 'First Name', 'text' => 'Ada']);
});

it('keeps reading the blueprint fields after one that is not an array or has no string handle', function (mixed $broken): void {
    $config = pestReplicatorRowsConfig();
    $fields = mustBeArray(data_get($config, 'sets.main.sets.participant.fields'), 'fields');
    array_unshift($fields, $broken);
    data_set($config, 'sets.main.sets.participant.fields', $fields);

    $rows = ReplicatorEmailRows::build([['type' => 'participant', 'first_name' => 'Ada']], mustBeArray($config, 'config'));

    expect($rows[0]['pairs'][0])->toBe(['label' => 'First Name', 'text' => 'Ada']);
})->with([
    'a field that is not an array' => ['not a field'],
    'a handle that is not a string' => [['handle' => 7, 'field' => ['type' => 'text']]],
]);

it('maps an option key to its label for every option fieldtype', function (string $type): void {
    $config = ['sets' => ['main' => ['sets' => ['pick' => ['fields' => [
        ['handle' => 'choice', 'field' => ['type' => $type, 'display' => 'Choice', 'options' => ['r' => 'Red']]],
    ]]]]]];

    $rows = ReplicatorEmailRows::build([['type' => 'pick', 'choice' => 'r']], $config);

    expect($rows[0]['pairs'][0])->toBe(['label' => 'Choice', 'text' => 'Red']);
})->with(['select', 'radio', 'button_group', 'checkboxes']);
