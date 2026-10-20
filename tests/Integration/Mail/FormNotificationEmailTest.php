<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Site;
use Statamic\Fields\Field;
use Statamic\Fields\Value;
use Statamic\Sites\Site as StatamicSite;
use UAMSWeb\FormSettings\Mail\FormNotificationEmail;
use UAMSWeb\FormSettings\Support\ReplicatorEmailRows;

uses(MockeryPHPUnitIntegration::class);

covers(FormNotificationEmail::class);

/**
 * Unit coverage for UAMSWeb\FormSettings\Mail\FormNotificationEmail's protected hooks.
 *
 * The end-to-end sends live in FormEmailAttachToEmailTest and FormEmailFromNameTest. These
 * call the hooks directly on a mailable built over a submission double, so each branch is
 * reached without a form, a container or a mail transport.
 *
 * @command  vendor/bin/pest tests/Integration/Mail/FormNotificationEmailTest.php
 */

/**
 * A notification mailable over a submission double with the given config.
 *
 * @param  array<mixed>  $config
 */
function formNotificationEmail(array $config = []): FormNotificationEmail
{
    /** @var StatamicSite $site */
    $site = Site::default();
    /** @var array<string, mixed> $config */

    return new FormNotificationEmail(Mockery::mock(Submission::class), $config, $site);
}

/**
 * A field value double whose field reports the given type and config.
 *
 * @param  array<string, mixed>  $config
 */
function formNotificationValue(string $type, mixed $raw, array $config = []): Value
{
    $field = Mockery::mock(Field::class);
    $field->shouldReceive('display')->andReturn(ucfirst($type));
    $field->shouldReceive('type')->andReturn($type);
    $field->shouldReceive('config')->andReturn($config);

    $value = Mockery::mock(Value::class);
    $value->shouldReceive('field')->andReturn($field);
    $value->shouldReceive('raw')->andReturn($raw);

    return $value;
}

/**
 * The mailable's renderable field data for `$values`, as a plain list.
 *
 * @param  array<string, mixed>  $values
 * @return list<array<string, mixed>>
 */
function formNotificationRenderable(FormNotificationEmail $mail, array $values): array
{
    /** @var Collection<int, array<string, mixed>> $fields */
    $fields = (fn () => $this->getRenderableFieldData($values))->call($mail);

    return array_values($fields->all());
}

it('adds readable email rows to a replicator field, built from its raw rows and config', function (): void {
    $rows = [['type' => 'quote', 'text' => 'Hello', 'enabled' => true]];
    $config = ['sets' => ['main' => ['sets' => ['quote' => ['display' => 'Featured quote', 'fields' => [['handle' => 'text', 'field' => ['type' => 'text', 'display' => 'Quoted text']]]]]]]];

    $fields = formNotificationRenderable(formNotificationEmail(), ['story' => formNotificationValue('replicator', $rows, $config)]);

    expect($fields[0]['email_rows'])->toBe(ReplicatorEmailRows::build($rows, $config))
        ->and($fields[0]['email_rows'])->not->toBe([]);
});

it('leaves every other field without email rows', function (): void {
    $fields = formNotificationRenderable(formNotificationEmail(), ['name' => formNotificationValue('text', 'Kirk')]);

    expect($fields[0])->not->toHaveKey('email_rows')
        ->and($fields[0]['fieldtype'])->toBe('text');
});

it('builds the rows of a replicator whose config is empty', function (): void {
    $rows = [['type' => 'quote', 'text' => 'Hello', 'enabled' => true]];

    $fields = formNotificationRenderable(formNotificationEmail(), ['story' => formNotificationValue('replicator', $rows, [])]);

    expect($fields[0]['email_rows'])->toBe(ReplicatorEmailRows::build($rows, []));
});

it('passes a replicator value that is not a Value through to the row builder as-is', function (): void {
    // The parent reads ->field() off each value, so any object exposing field() reaches here.
    $field = Mockery::mock(Field::class);
    $field->shouldReceive('display')->andReturn('Story');
    $field->shouldReceive('type')->andReturn('replicator');
    $field->shouldReceive('config')->andReturn([]);

    $value = new class($field)
    {
        public function __construct(private Field $field) {}

        public function field(): Field
        {
            return $this->field;
        }
    };

    $fields = formNotificationRenderable(formNotificationEmail(), ['story' => $value]);

    expect($fields[0]['email_rows'])->toBe(ReplicatorEmailRows::build($value, []));
});

it('keeps non-string config entries verbatim while parsing the string ones', function (): void {
    $config = ['subject' => '{{ "hello" | upper }}', 'enabled' => true, 'conditional_logic' => ['rules' => [1]], 'to' => 'a@example.test'];

    /** @var Collection<string, mixed> $parsed */
    $parsed = (function () use ($config) {
        // The parent renders against the submission data, which build() normally sets.
        $this->submissionData = [];

        return $this->parseConfig($config);
    })->call(formNotificationEmail());

    // The Antlers subject shows the string entries were rendered, not just copied.
    expect($parsed->all())->toBe(['subject' => 'HELLO', 'enabled' => true, 'conditional_logic' => ['rules' => [1]], 'to' => 'a@example.test']);
});

it('returns itself and leaves submission data that is not an array alone', function (): void {
    $mail = formNotificationEmail(['attachments' => false]);

    /** @var FormNotificationEmail $result */
    $result = (function () {
        $this->submissionData = null;

        return $this->addAttachments();
    })->call($mail);

    /** @var mixed $after */
    $after = (fn () => $this->submissionData)->call($mail);

    expect($result)->toBe($mail)
        ->and($after)->toBeNull();
});

it("uses a config whose rows differ from an empty config's", function (): void {
    // A precondition of the first test: with this config, passing the field's config and
    // passing [] give different rows, so that test can tell the two apart.
    $rows = [['type' => 'quote', 'text' => 'Hello', 'enabled' => true]];
    $config = ['sets' => ['main' => ['sets' => ['quote' => ['display' => 'Featured quote', 'fields' => [['handle' => 'text', 'field' => ['type' => 'text', 'display' => 'Quoted text']]]]]]]];

    expect(ReplicatorEmailRows::build($rows, $config))->not->toBe(ReplicatorEmailRows::build($rows, []));
});

/**
 * The From header the mailable ends with, after applyFromName() over `$config`,
 * starting from a parent-wired header of `$parentFrom`.
 *
 * @param  array<mixed>  $config
 * @param  list<array{address: string, name: string|null}>  $parentFrom
 * @return list<array{address: string, name: string|null}>
 */
function formNotificationFromAfterName(array $config, array $parentFrom = [['address' => 'parent@example.test', 'name' => null]]): array
{
    $mail = formNotificationEmail($config);

    /** @var list<array{address: string, name: string|null}> $from */
    $from = (function () use ($parentFrom) {
        $this->from = $parentFrom;
        $this->applyFromName();

        return $this->from;
    })->call($mail);

    return $from;
}

it('rebuilds the From header with the trimmed display name on every sender address', function (): void {
    expect(formNotificationFromAfterName(['from' => 'a@example.test, b@example.test', 'from_name' => '  Dr. Kirk  ']))->toBe([
        ['name' => 'Dr. Kirk', 'address' => 'a@example.test'],
        ['name' => 'Dr. Kirk', 'address' => 'b@example.test'],
    ]);
});

it('casts a numeric display name to a string', function (): void {
    expect(formNotificationFromAfterName(['from' => 'a@example.test', 'from_name' => 42]))->toBe([
        ['name' => '42', 'address' => 'a@example.test'],
    ]);
});

it('keeps the parent From header when there is no usable display name or no sender', function (array $config): void {
    expect(formNotificationFromAfterName($config))->toBe([['address' => 'parent@example.test', 'name' => null]]);
})->with([
    'no display name' => [['from' => 'a@example.test']],
    'blank display name' => [['from' => 'a@example.test', 'from_name' => '   ']],
    'non-scalar display name' => [['from' => 'a@example.test', 'from_name' => ['Kirk']]],
    'no sender' => [['from_name' => 'Dr. Kirk']],
    'empty sender' => [['from' => '', 'from_name' => 'Dr. Kirk']],
]);

/**
 * A submission value double for withoutUnflaggedAssetFields().
 *
 * @param  array<string, mixed>|null  $config  The field config, or null for no field at all
 */
function formNotificationSubmissionValue(?string $type, ?array $config, bool $hasFieldtype = true): Value
{
    $value = Mockery::mock(Value::class);
    $value->shouldReceive('fieldtype')->andReturn($hasFieldtype ? new stdClass : null);

    if ($type === null) {
        $value->shouldReceive('field')->andReturnNull();

        return $value;
    }

    $field = Mockery::mock(Field::class);
    $field->shouldReceive('type')->andReturn($type);
    $field->shouldReceive('config')->andReturn($config ?? []);
    $value->shouldReceive('field')->andReturn($field);

    return $value;
}

it('drops only the Assets fields not flagged to attach', function (): void {
    $data = [
        'id' => 'not a value',
        'date' => formNotificationSubmissionValue(null, null, false),
        'orphan' => formNotificationSubmissionValue(null, null),
        'name' => formNotificationSubmissionValue('text', []),
        'files' => formNotificationSubmissionValue('files', []),
        'flagged' => formNotificationSubmissionValue('assets', ['attach_to_email' => true]),
        'flagged_text' => formNotificationSubmissionValue('assets', ['attach_to_email' => 'true']),
        'unflagged' => formNotificationSubmissionValue('assets', ['attach_to_email' => false]),
        'unset' => formNotificationSubmissionValue('assets', []),
    ];

    /** @var array<string, mixed> $kept */
    $kept = (fn (): array => $this->withoutUnflaggedAssetFields($data))->call(formNotificationEmail());

    expect(array_keys($kept))->toBe(['id', 'date', 'orphan', 'name', 'files', 'flagged', 'flagged_text']);
});
