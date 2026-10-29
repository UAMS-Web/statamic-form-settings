<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Statamic\Facades\Antlers;
use Statamic\Fields\Field;
use UAMSWeb\FormSettings\Fieldtypes\Quiz;

/**
 * Tests the public-form quiz template (UAMS-Web/uams-statamic-archive#816). `quiz.antlers.html` renders the
 * configured answers as the control chosen in `question_fieldtype` by delegating
 * to the shared `radio` / `checkboxes` / `select` partials.
 *
 * The template is driven through the REAL sanitizer: renderQuizField() builds an
 * `UAMSWeb\FormSettings\Fieldtypes\Quiz` with a full scoring grid (`is_correct` / `weighted_score`
 * / `answer_explanation`) and feeds `extraRenderableFieldData()` into the
 * template exactly as `RendersForms::getRenderableField()` merges it (last, over
 * `$field->toArray()`). So these tests exercise the sanitizer end-to-end: if the
 * `value => label` sanitization regresses, the rendered answer options stop
 * carrying the answer labels and the dispatch tests go red. The exclusion of the
 * scoring metadata itself is asserted directly on the sanitized map by
 * QuizFieldtypeTest.
 *
 * @command  vendor/bin/pest tests/Integration/Views/FormsFieldsQuizTest.php
 */

/**
 * Render the quiz form template driven through the real Quiz sanitizer.
 *
 * @param  string|null  $questionType  The `question_fieldtype` config (null exercises the fieldtype's `?? 'radio'` fallback)
 */
function renderQuizField(?string $questionType = 'radio'): string
{
    // A real answers grid carrying the scoring config that must never reach HTML
    /** @var Quiz $fieldtype */
    $fieldtype = (new Quiz)->setField(new Field('stroke_signs', [
        'type' => 'quiz',
        'question_fieldtype' => $questionType,
        'options' => [
            ['is_correct' => true,  'label' => 'Balance loss',  'weighted_score' => 2.0, 'answer_explanation' => 'A stroke warning sign'],
            ['is_correct' => false, 'label' => 'Face drooping', 'weighted_score' => 0.0],
        ],
    ]));

    // Merge the sanitized field data last, mirroring RendersForms::getRenderableField()
    $context = [
        'handle' => 'stroke_signs',
        'name' => 'stroke_signs',
        'id' => 'form-stroke_signs',
        'visibility' => 'visible',
        'value' => '',
        'validate' => [],
        'error' => null,
        'instructions' => null,
        ...$fieldtype->extraRenderableFieldData(),
    ];

    return (string) Antlers::parse(
        File::get(dirname(__DIR__, 3).'/resources/field-views/forms/fields/quiz.antlers.html'),
        $context,
        true,
    );
}

it('renders radio inputs carrying the answer labels for a radio question type', function (): void {
    $out = renderQuizField('radio');

    expect(substr_count($out, 'type="radio"'))->toBe(2);
    expect($out)->toContain('value="Balance loss"');
    expect($out)->toContain('value="Face drooping"');
    expect($out)->toContain('name="stroke_signs"');
});

it('defaults to radio inputs when the question type is unset', function (): void {
    // question_fieldtype => null exercises the fieldtype's real `?? 'radio'` fallback
    $out = renderQuizField(null);

    expect(substr_count($out, 'type="radio"'))->toBe(2);
    expect($out)->toContain('value="Balance loss"');
});

it('renders checkbox inputs with the array submit shape for a checkboxes question type', function (): void {
    $out = renderQuizField('checkboxes');

    expect(substr_count($out, 'type="checkbox"'))->toBe(2);
    // Array submit shape, so multiple selections POST under one handle
    expect($out)->toContain('name="stroke_signs[]"');
    expect($out)->toContain('value="Balance loss"');
});

it('renders a select control carrying the answer labels for a select question type', function (): void {
    $out = renderQuizField('select');

    expect($out)->toContain('<select');
    expect($out)->toContain('value="Balance loss"');
    expect($out)->toContain('value="Face drooping"');
});

it('renders the answers as sanitized value=label options, never the raw scoring grid', function (): void {
    // Driven through the real extraRenderableFieldData(): the answer labels reach
    // the control as both the option value and label. If the sanitizer regressed to
    // exposing the raw grid, the options would carry list indices (value="0") and
    // array labels instead, so these label-as-value assertions would fail.
    foreach (['radio', 'checkboxes', 'select'] as $type) {
        $out = renderQuizField($type);

        expect($out)->toContain('value="Balance loss"');
        expect($out)->toContain('value="Face drooping"');
        expect($out)->not->toContain('value="0"');
        expect($out)->not->toContain('A stroke warning sign');
    }
});
