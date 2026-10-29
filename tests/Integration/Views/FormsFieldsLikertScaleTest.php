<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Statamic\Facades\Antlers;
use Statamic\Fields\Field;
use UAMSWeb\FormSettings\Fieldtypes\LikertScale;

/**
 * The public-form template for the LikertScale fieldtype (UAMS-Web/uams-statamic-archive#194),
 * resources/views/vendor/statamic/forms/fields/likert_scale.antlers.html. A
 * Likert field has no `options` config key, so without this template (and the
 * fieldtype's extraRenderableFieldData) a Likert field falls back to a plain
 * text input. These tests build the render scope from the REAL fieldtype output
 * (LikertScale::extraRenderableFieldData() merged with the wrapper-provided
 * keys, mirroring RendersForms::getRenderableField()) and assert the template
 * renders accessible radio groups, honors the config, reflects the submitted
 * value as `checked`, uses the submit-shape UAMSWeb\FormSettings\Scoring\SurveyScorer
 * consumes, and escapes author-controlled labels. Rendering a template against a
 * hand-built scope has no mutable PHP surface.
 *
 * @command  vendor/bin/pest tests/Integration/Views/FormsFieldsLikertScaleTest.php
 */

/**
 * The front-end render context for a likert_scale field: the fieldtype's
 * extraRenderableFieldData() (options / inline / matrix rows) merged over the
 * keys the parent wrapper supplies (handle / id / value / validate / …).
 *
 * @param  array<string, mixed>  $config  The blueprint field config.
 * @param  mixed  $value  The submitted / repopulated value.
 * @param  array<string, mixed>  $overrides  Context overrides (visibility, validate, …).
 * @return array<string, mixed>
 */
function likertContext(array $config, mixed $value = '', array $overrides = []): array
{
    $fieldtype = new LikertScale;
    $fieldtype->setField(new Field('q1', array_merge(['type' => 'likert_scale'], $config)));

    return array_merge([
        'handle' => 'q1',
        'id' => 'contact-form-q1-field',
        'display' => 'Rate your visit',
        'type' => 'likert_scale',
        'visibility' => 'visible',
        'validate' => [],
        'error' => null,
        'instructions' => null,
        'value' => $value,
    ], $fieldtype->extraRenderableFieldData(), $overrides);
}

/**
 * Render the likert_scale form template against a real-fieldtype context.
 *
 * @param  array<string, mixed>  $config
 * @param  array<string, mixed>  $overrides
 */
function renderLikert(array $config, mixed $value = '', array $overrides = []): string
{
    return (string) Antlers::parse(
        File::get(dirname(__DIR__, 3).'/resources/field-views/forms/fields/likert_scale.antlers.html'),
        likertContext($config, $value, $overrides),
        true,
    );
}

it('renders a single-row scale as radio inputs, not a text input', function (): void {
    $out = renderLikert(['scale_type' => 'agreement', 'number_of_points' => 5]);

    expect(substr_count($out, 'type="radio"'))->toBe(5);
    expect($out)->not->toContain('type="text"');
    expect($out)->toContain('role="radiogroup"');
    // Every radio submits under the bare handle (single scale point).
    expect(substr_count($out, 'name="q1"'))->toBe(5);
    expect($out)->toContain('Strongly Disagree');
    expect($out)->toContain('Strongly Agree');
});

it('checks exactly the option matching the submitted value', function (): void {
    $out = renderLikert(['scale_type' => 'agreement', 'number_of_points' => 5], '3');

    expect(substr_count($out, 'checked'))->toBe(1);
    // The value="3" radio is the checked one (POSTed string vs int point).
    expect($out)->toMatch('/<input\b[^>]*\bname="q1"[^>]*\bvalue="3"[^>]*\bchecked\b[^>]*>/');
    expect($out)->not->toMatch('/<input\b[^>]*\bvalue="1"[^>]*\bchecked\b[^>]*>/');
});

it('checks nothing when there is no submitted value', function (): void {
    $out = renderLikert(['scale_type' => 'agreement', 'number_of_points' => 5], '');

    expect($out)->not->toContain('checked');
});

it('renders the zero-point option with its configured label', function (): void {
    $out = renderLikert([
        'scale_type' => 'agreement', 'number_of_points' => 5,
        'enable_not_applicable' => true, 'not_applicable_label' => 'Prefer not to say',
    ]);

    expect(substr_count($out, 'type="radio"'))->toBe(6);
    expect($out)->toContain('value="not_applicable"');
    expect($out)->toContain('Prefer not to say');
});

it('applies the display order to the rendered sequence', function (): void {
    $asc = renderLikert(['scale_type' => 'agreement', 'number_of_points' => 5, 'display_order' => 'asc']);
    $desc = renderLikert(['scale_type' => 'agreement', 'number_of_points' => 5, 'display_order' => 'desc']);

    // asc lists value 1 before value 5; desc reverses it.
    expect(positionOf($asc, 'value="1"'))->toBeLessThan(positionOf($asc, 'value="5"'));
    expect(positionOf($desc, 'value="5"'))->toBeLessThan(positionOf($desc, 'value="1"'));
});

it('adds the inline modifier only when inline is set', function (): void {
    $inline = renderLikert(['scale_type' => 'agreement', 'number_of_points' => 5, 'inline' => true]);
    $stacked = renderLikert(['scale_type' => 'agreement', 'number_of_points' => 5, 'inline' => false]);

    expect($inline)->toContain('form-check-inline');
    expect($stacked)->not->toContain('form-check-inline');
});

it('emits required on the radios when the field is required', function (): void {
    $out = renderLikert(['scale_type' => 'agreement', 'number_of_points' => 5], '', ['validate' => ['required']]);

    expect($out)->toContain('required');
});

it('disables every radio when the field is read_only', function (): void {
    $out = renderLikert(['scale_type' => 'agreement', 'number_of_points' => 5], '', ['visibility' => 'read_only']);

    expect(substr_count($out, 'type="radio"'))->toBe(5);
    expect(substr_count($out, 'disabled'))->toBe(5);
});

it('does not disable radios when visible', function (): void {
    $out = renderLikert(['scale_type' => 'agreement', 'number_of_points' => 5]);

    expect($out)->not->toContain('disabled');
});

it('renders a matrix as one radio group per row using the array submit shape', function (): void {
    $out = renderLikert([
        'scale_type' => 'agreement', 'number_of_points' => 5,
        'enable_multiple_rows' => true, 'multiple_rows' => ['Cleanliness', 'Staff Friendliness'],
    ]);

    expect(substr_count($out, '<fieldset'))->toBe(2);
    expect($out)->toContain('<legend');
    expect($out)->toContain('Cleanliness');
    expect($out)->toContain('Staff Friendliness');
    // Each row submits under name="q1[<row>]" (the rowLabel => point map SurveyScorer reads).
    expect(substr_count($out, 'name="q1[Cleanliness]"'))->toBe(5);
    expect(substr_count($out, 'name="q1[Staff Friendliness]"'))->toBe(5);
    expect($out)->not->toContain('name="q1"');
});

it('checks the per-row option matching each row of the submitted map', function (): void {
    $out = renderLikert([
        'scale_type' => 'agreement', 'number_of_points' => 5,
        'enable_multiple_rows' => true, 'multiple_rows' => ['Cleanliness', 'Staff', 'Wait Time'],
    ], ['Cleanliness' => '5', 'Staff' => '2']);

    // Two rows answered, one left blank → exactly two checked radios.
    expect(substr_count($out, 'checked'))->toBe(2);
    expect($out)->toMatch('/<input\b[^>]*\bname="q1\[Cleanliness\]"[^>]*\bvalue="5"[^>]*\bchecked\b[^>]*>/');
    expect($out)->toMatch('/<input\b[^>]*\bname="q1\[Staff\]"[^>]*\bvalue="2"[^>]*\bchecked\b[^>]*>/');
    // The unanswered row has no checked radio.
    expect($out)->not->toMatch('/<input\b[^>]*\bname="q1\[Wait Time\]"[^>]*\bchecked\b[^>]*>/');
});

it('falls back to a single row when multi-row is enabled but every row label is blank', function (): void {
    $out = renderLikert([
        'scale_type' => 'agreement', 'number_of_points' => 5,
        'enable_multiple_rows' => true, 'multiple_rows' => ['', '   '],
    ]);

    // No usable row labels → degrade to the single-row group, not a broken matrix.
    expect($out)->not->toContain('<fieldset');
    expect(substr_count($out, 'name="q1"'))->toBe(5);
});

it('escapes an author-controlled row label everywhere it lands in markup', function (): void {
    $out = renderLikert([
        'scale_type' => 'agreement', 'number_of_points' => 5,
        'enable_multiple_rows' => true, 'multiple_rows' => ['<script>alert(1)</script>'],
    ], []);

    expect($out)->not->toContain('<script>alert(1)</script>');
    expect($out)->toContain('&lt;script&gt;');
});

it('escapes an author-controlled zero-point label', function (): void {
    $out = renderLikert([
        'scale_type' => 'agreement', 'number_of_points' => 5,
        'enable_not_applicable' => true, 'not_applicable_label' => '<b>N/A</b>',
    ]);

    expect($out)->not->toContain('<b>N/A</b>');
    expect($out)->toContain('&lt;b&gt;');
});

it('emits a parse-safe bracket-free name for a bracketed row label', function (): void {
    // A `]` in the name would truncate PHP's array-key parse, so the submitted key
    // no longer matches what SurveyScorer looks up, the row silently scores 0.
    $out = renderLikert([
        'scale_type' => 'agreement', 'number_of_points' => 5,
        'enable_multiple_rows' => true, 'multiple_rows' => ['Wait [min]'],
    ]);

    expect($out)->toContain('name="q1[Wait min]"');
    expect($out)->not->toContain('Wait [min]');
});

it('collapses duplicate matrix row labels to a single group', function (): void {
    // Two identical labels would emit the same name and double-count in scoring.
    $out = renderLikert([
        'scale_type' => 'agreement', 'number_of_points' => 5,
        'enable_multiple_rows' => true, 'multiple_rows' => ['Food', 'Food'],
    ]);

    expect(substr_count($out, '<fieldset'))->toBe(1);
    expect(substr_count($out, 'name="q1[Food]"'))->toBe(5);
});
