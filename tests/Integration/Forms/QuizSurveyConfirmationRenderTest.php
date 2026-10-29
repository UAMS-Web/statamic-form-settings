<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Form;

/**
 * End-to-end proof that {{ quiz }} / {{ survey }} score merge tags (UAMS-Web/uams-statamic-archive#209, UAMS-Web/uams-statamic-archive#210)
 * render through the real confirmation pipeline: a submission POSTed to the
 * form-submit route flows through ApplyFormConfirmation, which Antlers-renders
 * the configured confirmation message against the submission's augmented array,
 * the same context an email notification uses, so the tags reach the answers +
 * form config exactly as they will in production.
 *
 * @covers \UAMSWeb\FormSettings\Tags\Quiz
 * @covers \UAMSWeb\FormSettings\Tags\Survey
 *
 * @command  vendor/bin/pest tests/Integration/Forms/QuizSurveyConfirmationRenderTest.php
 */
$renderFormsDir = '';

beforeEach(function () use (&$renderFormsDir): void {
    // A unique IP per test keeps each submission in its own form-submit rate-limit bucket.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.'.random_int(0, 255).'.'.random_int(1, 254)]);

    $renderFormsDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($renderFormsDir);
    Config::set('statamic.forms.forms', $renderFormsDir);
});

afterEach(function () use (&$renderFormsDir): void {
    if ($renderFormsDir !== '' && File::isDirectory($renderFormsDir)) {
        File::deleteDirectory($renderFormsDir);
    }
});

/**
 * Build + save a form with the given fields and a message confirmation.
 *
 * @param  array<string, array<string, mixed>>  $fields
 */
function makeRenderForm(string $handle, array $fields, string $message): Form
{
    app(BlueprintRepository::class)->setFallback('forms.'.$handle, fn () => Blueprint::makeFromFields($fields));

    $form = makeForm($handle);
    $form->store(false);
    $form->set('confirmation_settings_default', [
        'confirmation_type' => 'message',
        'confirmation_message' => $message,
    ]);
    $form->save();

    return $form;
}

it('renders the quiz score and percentage in the confirmation message', function (): void {
    $handle = 'quiz_render_'.str_replace('.', '', uniqid('', true));
    makeRenderForm($handle, [
        'q1' => [
            'type' => 'quiz',
            'display' => 'Capital of France?',
            'question_fieldtype' => 'radio',
            'options' => [
                ['label' => 'Paris', 'is_correct' => true],
                ['label' => 'London', 'is_correct' => false],
            ],
        ],
    ], 'You scored {{ quiz:score }}/1 ({{ quiz:percent }}%).');

    $this->post('/!/forms/'.$handle, ['q1' => 'Paris'])
        ->assertSessionHas('form.'.$handle.'.success', 'You scored 1/1 (100%).');
});

it('renders the survey score in the confirmation message', function (): void {
    $handle = 'survey_render_'.str_replace('.', '', uniqid('', true));
    makeRenderForm($handle, [
        'q1' => [
            'type' => 'likert_scale',
            'display' => 'How satisfied are you?',
            'scale_type' => 'satisfaction',
            'number_of_points' => 5,
        ],
    ], 'Your survey score: {{ survey:score }}.');

    $this->post('/!/forms/'.$handle, ['q1' => '4'])
        ->assertSessionHas('form.'.$handle.'.success', 'Your survey score: 4.');
});
