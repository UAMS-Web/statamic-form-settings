<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Antlers;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Submission;
use UAMSWeb\FormSettings\ServiceProvider;

/**
 * While quiz scoring is on, the add-on adds the `quiz` and `survey` tags to Antlers' user-content
 * allowlist (UAMS-Web/uams-statamic#264), so `{{ quiz:score }}` resolves in a confirmation message or
 * a notification email. Statamic's defaults name only its own tags and the host's, so without the
 * addition the tags render nothing there. The host can turn the addition off, and its own entries
 * are kept either way.
 *
 * The untrusted render is the check that Statamic still honours the addition: it reads the config
 * each time it makes a parser, which is what lets a change made while the add-on boots take effect.
 *
 * @command  vendor/bin/pest tests/Integration/Scoring/QuizTagsInUserContentTest.php
 */
covers(ServiceProvider::class);

/**
 * Render `{{ quiz:score }}` as user content against a submission that answers its one question right.
 */
function quizTagsInUserContentScore(): string
{
    // A test reboots before calling this, which discards any config a beforeEach set, so the form
    // store points at a directory of its own here, removed again once the render is done
    $formsDir = storage_path('framework/testing/forms-'.uniqid('', true));
    File::ensureDirectoryExists($formsDir);
    Config::set('statamic.forms.forms', $formsDir);

    try {
        return quizTagsInUserContentRender();
    } finally {
        File::deleteDirectory($formsDir);
    }
}

/**
 * Save the form and render its score, for {@see quizTagsInUserContentScore()}.
 */
function quizTagsInUserContentRender(): string
{
    $handle = 'user_content_'.str_replace('.', '', uniqid('', true));

    app(BlueprintRepository::class)->setFallback('forms.'.$handle, fn () => Blueprint::makeFromFields([
        'capital' => [
            'type' => 'quiz',
            'question_fieldtype' => 'radio',
            'options' => [
                ['label' => 'Paris', 'is_correct' => true],
                ['label' => 'London', 'is_correct' => false],
            ],
        ],
    ]));

    $form = makeForm($handle);
    $form->store(false);
    $form->save();

    $submission = mustBe(Submission::class, $form->makeSubmission(), 'submission');
    $submission->data(['capital' => 'Paris']);

    // Untrusted, as Statamic parses a confirmation message or an email body
    return trim((string) Antlers::parse('{{ quiz:score }}', mustBeStringKeyed($submission->toAugmentedArray(), 'the submission'), false));
}

it("adds the tags after Statamic's defaults when the host lists none, and they resolve in user content", function (): void {
    $this->rebootWithConfig(['statamic.antlers.allowedContentTags' => null]);

    expect(config('statamic.antlers.allowedContentTags'))->toBe(['@default', 'quiz', 'quiz:*', 'survey', 'survey:*'])
        ->and(quizTagsInUserContentScore())->toBe('1');
});

it("keeps the host's own entries, in their order, ahead of the tags", function (): void {
    $this->rebootWithConfig(['statamic.antlers.allowedContentTags' => ['@default', 'partial:*', 'survey']]);

    expect(config('statamic.antlers.allowedContentTags'))->toBe(['@default', 'partial:*', 'survey', 'quiz', 'quiz:*', 'survey:*']);
});

it('reads a single pattern the host gives as a string as a one-item list', function (): void {
    $this->rebootWithConfig(['statamic.antlers.allowedContentTags' => 'partial:*']);

    expect(config('statamic.antlers.allowedContentTags'))->toBe(['partial:*', 'quiz', 'quiz:*', 'survey', 'survey:*']);
});

it("writes the host's entries back as a list, whatever keys the host gave them", function (): void {
    $this->rebootWithConfig(['statamic.antlers.allowedContentTags' => [3 => '@default', 'tags' => 'partial:*']]);

    expect(config('statamic.antlers.allowedContentTags'))->toBe(['@default', 'partial:*', 'quiz', 'quiz:*', 'survey', 'survey:*']);
});

it("leaves the host's allowlist as it is when the host opts out, or scoring is off", function (string $switch): void {
    $this->rebootWithConfig(['statamic.antlers.allowedContentTags' => ['@default', 'partial:*'], $switch => false]);

    expect(config('statamic.antlers.allowedContentTags'))->toBe(['@default', 'partial:*']);

    // Scoring itself stays on for the opt-out, so the empty render is the sandbox refusing the tag
    if (config('statamic-form-settings.quiz_scoring')) {
        expect(quizTagsInUserContentScore())->toBe('');
    }
})->with([
    'opted out' => ['statamic-form-settings.quiz_tags_in_user_content'],
    'scoring off' => ['statamic-form-settings.quiz_scoring'],
]);
