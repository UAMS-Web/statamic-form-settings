<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Tags;

use Statamic\Forms\Form;
use Statamic\Tags\Tags;
use UAMSWeb\FormSettings\Features;
use UAMSWeb\FormSettings\Scoring\Concerns\ResolvesScoredSubmission;
use UAMSWeb\FormSettings\Scoring\Survey\SurveyQuestionResult;
use UAMSWeb\FormSettings\Scoring\Survey\SurveyResults;
use UAMSWeb\FormSettings\Scoring\SurveyScorer;

/**
 * Emulates the Gravity Forms survey merge tags (UAMS-Web/uams-statamic-archive#210).
 *
 * Renders a scored survey submission inside form confirmations and email
 * notifications (both Antlers-render their message against the submission's
 * augmented array, which exposes the answers keyed by handle and the `form`).
 * The tag reads that context, scores the submission via {@see SurveyScorer}, and
 * outputs the requested value.
 *
 * Usage:
 *   - {{ survey }}                      every survey question with the respondent's answer(s)
 *   - {{ survey handle="q1" }}          a single survey question's answer(s)
 *   - {{ survey:score }}                the total survey score
 *   - {{ survey:score handle="q1" }}    a single question's score
 *
 * When the form has no survey (Likert) fields (or the tag runs outside a
 * submission), every method returns an empty string.
 *
 * Note: the bare `{{ survey }}` index form is shadowed by a submitted field whose
 * handle is literally `survey` (Antlers resolves the variable before the tag).
 * Use a `:method` (`{{ survey:score }}`) or `{{ survey handle="…" }}`, or avoid a
 * field handle named `survey`, if a form ever needs both.
 */
class Survey extends Tags
{
    use ResolvesScoredSubmission;

    /**
     * {{ survey }}: render the survey question(s) with the respondent's answer(s).
     */
    public function index(): string
    {
        $results = $this->results();

        if (! $results instanceof SurveyResults || ! $results->hasQuestions()) {
            return '';
        }

        $handle = $this->handleParam();

        if ($handle !== '') {
            $question = $results->question($handle);

            return $question instanceof SurveyQuestionResult ? $this->renderQuestion($question) : '';
        }

        return collect($results->questions)
            ->map(fn (SurveyQuestionResult $question): string => $this->renderQuestion($question))
            ->implode("\n");
    }

    /**
     * {{ survey:score }}: the total score, or a single question's score when `handle` is given.
     */
    public function score(): string
    {
        $results = $this->results();

        if (! $results instanceof SurveyResults || ! $results->hasQuestions()) {
            return '';
        }

        $handle = $this->handleParam();

        if ($handle !== '') {
            $question = $results->question($handle);

            return $question instanceof SurveyQuestionResult ? $this->formatNumber($question->score) : '';
        }

        return $this->formatNumber($results->score);
    }

    /**
     * Score the current submission, or null when no form is in context.
     */
    private function results(): ?SurveyResults
    {
        // With scoring switched off, every method on the tag renders nothing
        if (! Features::quizScoring()) {
            return null;
        }

        $form = $this->resolveForm();

        if (! $form instanceof Form) {
            return null;
        }

        return app(SurveyScorer::class)->score($form, $this->answers($form));
    }

    /**
     * Render a single scored question as an escaped HTML block.
     */
    private function renderQuestion(SurveyQuestionResult $question): string
    {
        $lines = [];
        $lines[] = '<div class="survey-result" data-handle="'.e($question->handle).'">';
        $lines[] = '<p class="survey-question">'.e($question->label).'</p>';

        if ($question->rows !== []) {
            $lines[] = '<ul class="survey-answers">';

            foreach ($question->rows as $row) {
                $prefix = $row['row'] !== null ? e($row['row']).': ' : '';
                $lines[] = '<li class="survey-answer">'.$prefix.e($row['label']).'</li>';
            }

            $lines[] = '</ul>';
        }

        $lines[] = '</div>';

        return implode("\n", $lines);
    }
}
