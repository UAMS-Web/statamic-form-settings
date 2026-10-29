<?php

declare(strict_types=1);

use Statamic\Fields\Field;
use UAMSWeb\FormSettings\Fieldtypes\Traits\RandomizesOptions;
use UAMSWeb\FormSettings\Tests\Support\Doubles\RandomizesOptionsHarness;

covers(RandomizesOptions::class);

/**
 * Behavior of the RandomizesOptions trait (app/Fieldtypes/Traits/RandomizesOptions.php):
 * getOptions() returns the preset-applied options, shuffled only when the field's
 * `randomize_order` config is truthy, and randomizeOrderConfigField() builds the
 * toggle that controls it.
 *
 * @command  vendor/bin/pest tests/Integration/Fieldtypes/Traits/RandomizesOptionsTest.php
 */

/**
 * A harness bound to a field with the given config.
 *
 * @param  array<string, mixed>  $config
 */
function randomizesOptionsHarness(array $config = []): RandomizesOptionsHarness
{
    $fieldtype = new RandomizesOptionsHarness;
    $fieldtype->setField(new Field('choice', array_merge(['type' => 'radio'], $config)));

    return $fieldtype;
}

it('returns the preset options in order when randomizing is off', function (array $config): void {
    /** @var array<string, mixed> $typedConfig */
    $typedConfig = $config;

    expect(randomizesOptionsHarness($typedConfig)->options())->toBe(range('a', 'j'));
})->with([
    'not configured' => [[]],
    'false' => [['randomize_order' => false]],
    'null' => [['randomize_order' => null]],
]);

it('shuffles the preset options when randomizing is on', function (): void {
    // Seeding makes shuffle() deterministic, so the reordering can be asserted;
    // with ten options the seeded order differs from the input.
    mt_srand(3345);
    $options = randomizesOptionsHarness(['randomize_order' => true])->options();
    mt_srand();

    expect($options)->not->toBe(range('a', 'j'))
        ->and($options)->toEqualCanonicalizing(range('a', 'j'));
});

it('builds the randomize toggle with default copy', function (): void {
    expect(RandomizesOptions::randomizeOrderConfigField())->toBe([
        'randomize_order' => [
            'display' => __('Randomize Order of Options'),
            'instructions' => __('Randomize the order in which the options are displayed to the user.'),
            'type' => 'toggle',
            'default' => false,
        ],
    ]);
});

it('builds the randomize toggle with custom copy', function (): void {
    expect(RandomizesOptions::randomizeOrderConfigField('Shuffle', 'Mix them up.'))->toBe([
        'randomize_order' => [
            'display' => 'Shuffle',
            'instructions' => 'Mix them up.',
            'type' => 'toggle',
            'default' => false,
        ],
    ]);
});
