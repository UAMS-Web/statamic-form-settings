<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Statamic\Fields\Field;
use Statamic\Forms\Form;
use Statamic\Forms\Submission;
use UAMSWeb\FormSettings\Fieldtypes\LikertScale;
use UAMSWeb\FormSettings\Tags\Survey;

/**
 * Proves the {{ survey }} tag (UAMS-Web/uams-statamic-archive#210) renders a scored submission the way a form
 * confirmation / email notification would: it reads the submission's augmented
 * context (the `form` plus the answers keyed by handle), scores it, and outputs
 * the total score and the per-question answer breakdown — while a non-survey
 * form yields nothing.
 *
 * @covers \UAMSWeb\FormSettings\Tags\Survey
 *
 * @command  vendor/bin/pest tests/Integration/Tags/SurveyTagTest.php
 */
$surveyTagFormsDir = '';

beforeEach(function () use (&$surveyTagFormsDir): void {
    $surveyTagFormsDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($surveyTagFormsDir);
    Config::set('statamic.forms.forms', $surveyTagFormsDir);
});

afterEach(function () use (&$surveyTagFormsDir): void {
    if ($surveyTagFormsDir !== '' && File::isDirectory($surveyTagFormsDir)) {
        File::deleteDirectory($surveyTagFormsDir);
    }
});

/**
 * Build a form + submission and return the augmented context the render paths use.
 *
 * @param  array<string, array<string, mixed>>  $fields
 * @param  array<string, mixed>  $answers
 * @return array{context: array<string, mixed>, form: Form}
 */
function surveySubmissionContext(array $fields, array $answers): array
{
    $handle = 'survey_tag_'.str_replace('.', '', uniqid('', true));

    app(BlueprintRepository::class)->setFallback('forms.'.$handle, fn () => Blueprint::makeFromFields($fields));

    $form = makeForm($handle);
    $form->store(false);
    $form->save();

    $submission = mustBe(Submission::class, $form->makeSubmission(), 'submission');
    $submission->data($answers);

    /** @var array<string, mixed> $augmentedContext */
    $augmentedContext = $submission->toAugmentedArray();

    return ['context' => $augmentedContext, 'form' => $form];
}

/**
 * A single-row satisfaction Likert question.
 *
 * @return array<string, mixed>
 */
function tagLikertField(): array
{
    return [
        'type' => 'likert_scale',
        'display' => 'How satisfied are you?',
        'scale_type' => 'satisfaction',
        'number_of_points' => 5,
    ];
}

/**
 * @param  array<string, mixed>  $context
 * @param  array<string, mixed>  $params
 */
function surveyTag(array $context, array $params = []): Survey
{
    $tag = new Survey;
    $tag->setContext($context);
    $tag->setParameters($params);

    return $tag;
}

it('outputs the total survey score from the submission', function (): void {
    ['context' => $context] = surveySubmissionContext(
        ['q1' => tagLikertField(), 'q2' => tagLikertField()],
        ['q1' => '5', 'q2' => '3'],
    );

    expect(surveyTag($context)->score())->toBe('8');
});

it('outputs a single question score with a handle parameter', function (): void {
    ['context' => $context] = surveySubmissionContext(['q1' => tagLikertField()], ['q1' => '4']);

    expect(surveyTag($context, ['handle' => 'q1'])->score())->toBe('4');
});

it('renders the question and the respondent selected answer label', function (): void {
    ['context' => $context, 'form' => $form] = surveySubmissionContext(['q1' => tagLikertField()], ['q1' => '5']);

    // Derive the expected label from the fieldtype's own option table, rather than hardcoding it.
    /** @var Statamic\Fields\Blueprint $blueprint */
    $blueprint = $form->blueprint();
    /** @var Field $field */
    $field = $blueprint->field('q1');
    /** @var LikertScale $fieldtype */
    $fieldtype = $field->fieldtype();
    /** @var array<int, array<string, mixed>> $options */
    $options = $fieldtype->preload()['options'];

    /** @var array<string, mixed> $matched */
    $matched = collect($options)->firstWhere('value', 5);
    $expectedLabel = $matched['label'];

    $html = surveyTag($context, ['handle' => 'q1'])->index();

    expect($html)->toContain('How satisfied are you?')
        ->toContain($expectedLabel)
        ->toContain('data-handle="q1"');
});

it('outputs nothing for a form without survey fields', function (): void {
    ['context' => $context] = surveySubmissionContext(['name' => ['type' => 'text', 'display' => 'Name']], ['name' => 'Pat']);

    expect(surveyTag($context)->score())->toBe('')
        ->and(surveyTag($context)->index())->toBe('');
});

it('outputs nothing when there is no form in context', function (): void {
    expect(surveyTag([])->score())->toBe('')
        ->and(surveyTag([])->index())->toBe('');
});
