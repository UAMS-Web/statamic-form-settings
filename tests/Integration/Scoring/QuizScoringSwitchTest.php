<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Antlers;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Statamic\Fields\Field;
use Statamic\Forms\Submission;
use UAMSWeb\FormSettings\Features;
use UAMSWeb\FormSettings\Fieldtypes\Quiz;
use UAMSWeb\FormSettings\Tags\Quiz as QuizTag;
use UAMSWeb\FormSettings\Tags\Survey as SurveyTag;

/**
 * The `quiz_scoring` switch (UAMS-Web/uams-statamic#264): on, the `quiz` and `survey` tags render
 * a submission's results; off, every method on both renders nothing. Either way the `quiz` and
 * `likert_scale` fieldtypes keep their public form views, so a stored form that names them still
 * renders, and the quiz question delegates to the view Statamic itself would render its control with.
 *
 * Templates are parsed trusted, as a theme template is; a confirmation message or email is user
 * content and also needs the tags in the host's content allowlist, which
 * QuizSurveyConfirmationRenderTest covers.
 *
 * @command  vendor/bin/pest tests/Integration/Scoring/QuizScoringSwitchTest.php
 */
covers(Features::class, QuizTag::class, SurveyTag::class, Quiz::class);

$switchFormsDir = '';

beforeEach(function () use (&$switchFormsDir): void {
    $switchFormsDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($switchFormsDir);
    Config::set('statamic.forms.forms', $switchFormsDir);
});

afterEach(function () use (&$switchFormsDir): void {
    if ($switchFormsDir !== '' && File::isDirectory($switchFormsDir)) {
        File::deleteDirectory($switchFormsDir);
    }
});

/**
 * The augmented context of a submission answering one quiz question and one Likert question.
 *
 * @return array<string, mixed>
 */
function quizScoringSwitchContext(): array
{
    $handle = 'scoring_switch_'.str_replace('.', '', uniqid('', true));

    app(BlueprintRepository::class)->setFallback('forms.'.$handle, fn () => Blueprint::makeFromFields([
        'capital' => [
            'type' => 'quiz',
            'display' => 'Capital of France?',
            'question_fieldtype' => 'radio',
            'options' => [
                ['label' => 'Paris', 'is_correct' => true],
                ['label' => 'London', 'is_correct' => false],
            ],
        ],
        'satisfaction' => [
            'type' => 'likert_scale',
            'display' => 'How satisfied are you?',
            'scale_type' => 'satisfaction',
            'number_of_points' => 5,
        ],
    ]));

    $form = makeForm($handle);
    $form->set('quiz_grading', 'passfail');
    $form->set('quiz_pass_percent', 50);
    $form->store(false);
    $form->save();

    $submission = mustBe(Submission::class, $form->makeSubmission(), 'submission');
    $submission->data(['capital' => 'Paris', 'satisfaction' => '4']);

    return mustBeStringKeyed($submission->toAugmentedArray(), 'the augmented submission');
}

/**
 * Render a template against the submission, trusted.
 *
 * @param  array<string, mixed>  $context
 */
function quizScoringSwitchRender(string $template, array $context): string
{
    return trim((string) Antlers::parse($template, $context, true));
}

it('renders each tag method with scoring on, and nothing with it off', function (string $template, string $whenOn): void {
    $context = quizScoringSwitchContext();

    // On, the method renders the submission's result
    config(['statamic-form-settings.quiz_scoring' => true]);
    expect(quizScoringSwitchRender($template, $context))->toContain($whenOn);

    // Off, the same template renders nothing
    config(['statamic-form-settings.quiz_scoring' => false]);
    expect(quizScoringSwitchRender($template, $context))->toBe('');
})->with([
    'quiz' => ['{{ quiz }}', 'Capital of France?'],
    'quiz:score' => ['{{ quiz:score }}', '1'],
    'quiz:percent' => ['{{ quiz:percent }}', '100'],
    'quiz:passfail' => ['{{ quiz:passfail }}', 'Pass'],
    'survey' => ['{{ survey }}', 'How satisfied are you?'],
    'survey:score' => ['{{ survey:score }}', '4'],
]);

it('reads a missing quiz scoring setting as on, and a set one as given', function (): void {
    $all = config('statamic-form-settings');
    config(['statamic-form-settings' => is_array($all) ? array_diff_key($all, ['quiz_scoring' => true]) : []]);
    expect(config('statamic-form-settings.quiz_scoring'))->toBeNull()
        ->and(Features::quizScoring())->toBeTrue();

    config(['statamic-form-settings.quiz_scoring' => false]);
    expect(Features::quizScoring())->toBeFalse();

    config(['statamic-form-settings.quiz_scoring' => true]);
    expect(Features::quizScoring())->toBeTrue();
});

it('keeps the quiz and likert scale form views with scoring and every feature switched off', function (): void {
    $this->rebootWithConfig([
        'statamic-form-settings.quiz_scoring' => false,
        'statamic-form-settings.features.control_panel' => false,
        'statamic-form-settings.features.notifications' => false,
        'statamic-form-settings.features.confirmations' => false,
        'statamic-form-settings.features.restrictions' => false,
    ]);

    // Both resolve to this add-on's own field views
    $fieldViews = realpath(dirname(__DIR__, 3).'/resources/field-views/forms/fields');
    expect(realpath(view()->getFinder()->find('statamic::forms.fields.quiz')))->toBe($fieldViews.'/quiz.antlers.html')
        ->and(realpath(view()->getFinder()->find('statamic::forms.fields.likert_scale')))->toBe($fieldViews.'/likert_scale.antlers.html');
});

it("names the view Statamic renders the question's control with", function (?string $questionFieldtype, string $control): void {
    // A host with no published form views, as in this suite, gets Statamic's own
    $quiz = new Quiz;
    $quiz->setField(new Field('q', [
        'type' => 'quiz',
        'question_fieldtype' => $questionFieldtype,
        'options' => [['label' => 'Yes', 'is_correct' => true]],
    ]));
    $data = $quiz->extraRenderableFieldData();

    expect($data['question_view'])->toBe('statamic::forms.antlers.fields.'.$control);
})->with([
    'radio' => ['radio', 'radio'],
    'checkboxes' => ['checkboxes', 'checkboxes'],
    'select' => ['select', 'select'],
    'unset falls back to radio' => [null, 'radio'],
    'any other type falls back to radio' => ['text', 'radio'],
]);
