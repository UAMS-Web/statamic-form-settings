<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use UAMSWeb\FormSettings\Rules\SubfieldsAny;

/**
 * Behavior of the add-on's SubfieldsAny rule (src/Rules/SubfieldsAny.php) and its base
 * (src/Rules/BaseSubfieldsRule.php): a field group passes when at least one subfield
 * has a value.
 *
 * @command  vendor/bin/pest tests/Integration/Rules/SubfieldsAnyTest.php
 */

/**
 * Validate one `address` value against the rule.
 *
 * @param  mixed  $value  The submitted group.
 * @param  array<string, string>  $attributes  Display names the validator knows, keyed by attribute.
 * @return list<string> The validator's messages for `address`.
 */
function subfieldsAnyErrors(mixed $value, array $attributes = []): array
{
    $validator = Validator::make(['address' => $value], ['address' => [new SubfieldsAny]], [], $attributes);
    $validator->passes();

    return array_values(array_map(
        static fn (mixed $message): string => mustBeString($message, 'validation message'),
        $validator->errors()->get('address'),
    ));
}

it('counts a subfield holding a falsy but real value as filled', function (mixed $filled): void {
    expect(subfieldsAnyErrors(['street' => '', 'unit' => $filled]))->toBe([]);
})->with([
    'the integer 0' => [0],
    'the string 0' => ['0'],
    'false' => [false],
]);

it('refuses a group whose subfields are all empty', function (mixed $empty): void {
    expect(subfieldsAnyErrors(['street' => $empty, 'city' => null]))
        ->toBe(['At least one subfield of address must have a value.']);
})->with([
    'an empty string' => [''],
    'null' => [null],
    'an empty list' => [[]],
]);

it('reads the subfields of an object as it does those of an array', function (): void {
    expect(subfieldsAnyErrors((object) ['street' => '', 'city' => 'Little Rock']))->toBe([])
        ->and(subfieldsAnyErrors((object) ['street' => '', 'city' => null]))
        ->toBe(['At least one subfield of address must have a value.']);
});

it("passes a value that has no subfields, leaving it to the field's other rules", function (): void {
    expect(subfieldsAnyErrors('Little Rock'))->toBe([]);
});

it('names the group by its key, even when the validator has a display name for it', function (): void {
    expect(subfieldsAnyErrors(['street' => ''], ['address' => 'mailing address']))
        ->toBe(['At least one subfield of address must have a value.']);
});
