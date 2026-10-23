<?php

declare(strict_types=1);

use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\CpForms\EmailGridBlueprintCustomizer;

covers(EmailGridBlueprintCustomizer::class);

/**
 * Behavior of {@see EmailGridBlueprintCustomizer} on email grids other than core's
 * own (UAMS-Web/uams-statamic#3089): a grid missing fields core normally has, a grid repeating a handle, and
 * an email entry it cannot augment. Core's full grid is pinned by
 * FormSettingsCustomizedContentsTest.
 *
 * @command  vendor/bin/pest tests/Integration/CpForms/EmailGridBlueprintCustomizerTest.php
 */

/**
 * Form settings contents whose email section carries the given email entry.
 *
 * @param  array<string, mixed>  $emailEntry
 * @return array<string, mixed>
 */
function emailGridCustomizerContents(array $emailEntry): array
{
    return ['tabs' => ['main' => ['sections' => [
        ['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]],
        ['fields' => [['handle' => 'email'] + $emailEntry]],
    ]]]];
}

/**
 * An email entry whose grid has a text field for each handle.
 *
 * @param  list<string>  $handles
 * @param  array<string, mixed>  $extra  Further keys for the grid field.
 * @return array<string, mixed>
 */
function emailGridCustomizerGrid(array $handles, array $extra = []): array
{
    return ['field' => ['type' => 'grid', 'fields' => array_map(
        fn (string $handle): array => ['handle' => $handle, 'field' => ['type' => 'text']],
        $handles,
    )] + $extra];
}

/**
 * Customize the contents, returning the result and the customized email grid field.
 *
 * @param  array<string, mixed>  $contents
 * @return array{0: bool, 1: array<string, mixed>}
 */
function emailGridCustomized(array &$contents): array
{
    $form = mustBe(Form::class, FormFacade::make('probe'), 'form');
    $result = app(EmailGridBlueprintCustomizer::class)->customizeEmailGrid($contents, $form);

    $grid = mustBeStringKeyed(data_get($contents, 'tabs.main.sections.1.fields.0.field'), 'grid');

    return [$result, $grid];
}

/**
 * The handles of a grid's fields, in order.
 *
 * @param  array<string, mixed>  $grid
 * @return list<string>
 */
function emailGridHandles(array $grid): array
{
    /** @var list<array{handle: string}> $fields */
    $fields = $grid['fields'];

    return array_column($fields, 'handle');
}

it('adds only what fits a grid without cc, bcc, reply-to, from or text fields', function (): void {
    $contents = emailGridCustomizerContents(emailGridCustomizerGrid(['to', 'subject']));
    [$result, $grid] = emailGridCustomized($contents);

    expect($result)->toBeTrue()
        ->and(emailGridHandles($grid))->toBe(['name', 'enabled', 'to_type', 'to', 'to_field', 'to_routing', 'subject', 'conditional_logic'])
        ->and($grid)->not->toHaveKey('instructions');
});

it('works on the first of a repeated handle only', function (): void {
    $contents = emailGridCustomizerContents(emailGridCustomizerGrid(['to', 'cc', 'cc', 'from', 'from', 'subject', 'subject', 'text', 'text']));
    [, $grid] = emailGridCustomized($contents);

    /** @var list<array{handle: string, field: array<string, mixed>}> $fields */
    $fields = $grid['fields'];

    expect(emailGridHandles($grid))->toBe([
        'name', 'enabled', 'to_type', 'to', 'to_field', 'to_routing', 'cc_type', 'cc', 'cc_field', 'cc_routing', 'cc',
        'from', 'from_name', 'from', 'subject', 'subject', 'text', 'message', 'text', 'conditional_logic',
    ])
        ->and($fields[14]['field']['validate'] ?? null)->toBe(['required'])
        ->and($fields[15]['field'])->not->toHaveKey('validate');
});

it('starts the instructions with the recipients note when the existing ones are not text', function (): void {
    $contents = emailGridCustomizerContents(emailGridCustomizerGrid(['to'], ['instructions' => ['not', 'text']]));
    [, $grid] = emailGridCustomized($contents);

    expect($grid['instructions'])->toBeString()->toStartWith("\n\n**Notification recipients:**\n");
});

it('leaves an email entry it cannot augment unchanged', function (array $emailEntry): void {
    $contents = emailGridCustomizerContents(mustBeStringKeyed($emailEntry, 'email entry'));
    $before = $contents;

    expect(app(EmailGridBlueprintCustomizer::class)->customizeEmailGrid($contents, mustBe(Form::class, FormFacade::make('probe'), 'form')))->toBeFalse()
        ->and($contents)->toBe($before);
})->with([
    'no field' => [[]],
    'field not an array' => [['field' => 'grid']],
    'field without a grid' => [['field' => ['type' => 'grid']]],
]);

it('reports no email grid when the contents carry none', function (): void {
    $contents = ['tabs' => ['main' => ['sections' => [['fields' => [['handle' => 'title']]]]]]];

    expect(app(EmailGridBlueprintCustomizer::class)->customizeEmailGrid($contents, mustBe(Form::class, FormFacade::make('probe'), 'form')))->toBeFalse();
});

/**
 * Contents with a base email grid and a Sites section whose override grid carries the given
 * columns, as the multisite forms add-on appends it (UAMS-Web/uams-statamic#3164).
 *
 * @param  list<array<string, mixed>>  $overrideColumns  The `site_overrides` grid's columns.
 * @return array<string, mixed>
 */
function emailGridCustomizerContentsWithOverrides(array $overrideColumns): array
{
    $contents = emailGridCustomizerContents(emailGridCustomizerGrid(['to', 'subject']));

    /** @var array{tabs: array{main: array{sections: list<mixed>}}} $contents */
    $contents['tabs']['main']['sections'][] = ['display' => 'Sites', 'fields' => [
        ['handle' => 'sites', 'field' => ['type' => 'sites']],
        ['handle' => 'site_overrides', 'field' => ['type' => 'grid', 'fields' => $overrideColumns]],
    ]];

    return $contents;
}

it("gives a site override row's email grid the customized base columns and keeps its own settings", function (): void {
    $contents = emailGridCustomizerContentsWithOverrides([
        ['handle' => 'site', 'field' => ['type' => 'sites']],
        ['handle' => 'email', 'field' => ['type' => 'grid', 'add_row' => 'Add Email', 'fields' => [['handle' => 'to', 'field' => ['type' => 'text']]]]],
    ]);

    [$result, $grid] = emailGridCustomized($contents);

    $override = mustBeStringKeyed(data_get($contents, 'tabs.main.sections.2.fields.1.field.fields.1.field'), 'override email grid');

    expect($result)->toBeTrue()
        ->and($override['fields'])->toBe($grid['fields'])
        ->and($override['add_row'])->toBe('Add Email')
        ->and(data_get($contents, 'tabs.main.sections.2.fields.1.field.fields.0'))->toBe(['handle' => 'site', 'field' => ['type' => 'sites']]);
});

it('leaves a site override grid without an email column unchanged', function (array $overrideColumns): void {
    /** @var list<array<string, mixed>> $overrideColumns */
    $contents = emailGridCustomizerContentsWithOverrides($overrideColumns);
    $sites = data_get($contents, 'tabs.main.sections.2');

    emailGridCustomized($contents);

    expect(data_get($contents, 'tabs.main.sections.2'))->toBe($sites);
})->with([
    'no email column' => [[['handle' => 'site', 'field' => ['type' => 'sites']]]],
    'an email column without a field' => [[['handle' => 'email']]],
]);

it('leaves a site override entry that is not a grid with columns unchanged', function (mixed $field): void {
    $contents = emailGridCustomizerContents(emailGridCustomizerGrid(['to', 'subject']));

    /** @var array{tabs: array{main: array{sections: list<mixed>}}} $contents */
    $contents['tabs']['main']['sections'][] = ['fields' => [['handle' => 'site_overrides', 'field' => $field]]];
    $sites = data_get($contents, 'tabs.main.sections.2');

    [$result] = emailGridCustomized($contents);

    expect($result)->toBeTrue()
        ->and(data_get($contents, 'tabs.main.sections.2'))->toBe($sites);
})->with([
    'a field reference' => ['uamsweb_common.something'],
    'a grid with no columns' => [['type' => 'grid']],
]);
