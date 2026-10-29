<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Tags;

use Statamic\Forms\Form;
use Statamic\Tags\Tags;
use UAMSWeb\FormSettings\Features;
use UAMSWeb\FormSettings\Scoring\Concerns\ResolvesScoredSubmission;
use UAMSWeb\FormSettings\Scoring\Quiz\QuizQuestionResult;
use UAMSWeb\FormSettings\Scoring\Quiz\QuizResults;
use UAMSWeb\FormSettings\Scoring\QuizScorer;

/**
 * Emulates the Gravity Forms quiz merge tags (UAMS-Web/uams-statamic-archive#209).
 *
 * Renders a scored quiz submission inside form confirmations and email
 * notifications, both of which Antlers-render their message against the
 * submission's augmented array — which exposes the submitted answers (keyed by
 * field handle) and the `form` itself. The tag reads that context, scores the
 * submission via {@see QuizScorer}, and outputs the requested figure.
 *
 * Usage:
 *   - {{ quiz }}                     — every quiz question with the answers, correct answer(s), and explanation
 *   - {{ quiz handle="q1" }}         — a single quiz question's breakdown
 *   - {{ quiz:score }}               — the total score (weighted-aware)
 *   - {{ quiz:score handle="q1" }}   — a single question's score
 *   - {{ quiz:percent }}             — the percentage score (a bare number, e.g. `85`)
 *   - {{ quiz:grade }}               — the letter grade (empty until grading config exists)
 *   - {{ quiz:passfail }}            — `Passed` / `Failed` (empty until a pass threshold exists)
 *
 * When the form has no quiz fields (or the tag runs outside a submission), every
 * method returns an empty string, so non-quiz forms are unaffected.
 *
 * Note: the bare `{{ quiz }}` index form is shadowed by a submitted field whose
 * handle is literally `quiz` (Antlers resolves the variable before the tag). Use
 * a `:method` (`{{ quiz:score }}`) or `{{ quiz handle="…" }}`, or avoid a field
 * handle named `quiz`, if a form ever needs both.
 */
class Quiz extends Tags
{
    use ResolvesScoredSubmission;

    /**
     * {{ quiz }} — render the quiz question(s) with answers, correct answer(s), and explanation.
     */
    public function index(): string
    {
        $results = $this->results();

        if (! $results instanceof QuizResults || ! $results->hasQuestions()) {
            return '';
        }

        $handle = $this->handleParam();

        if ($handle !== '') {
            $question = $results->question($handle);

            return $question instanceof QuizQuestionResult ? $this->renderQuestion($question) : '';
        }

        return collect($results->questions)
            ->map(fn (QuizQuestionResult $question): string => $this->renderQuestion($question))
            ->implode("\n");
    }

    /**
     * {{ quiz:grade }} — the letter grade, or empty when no grading config exists.
     */
    public function grade(): string
    {
        $results = $this->results();

        return $results instanceof QuizResults && $results->hasQuestions() ? $results->grade : '';
    }

    /**
     * {{ quiz:passfail }} — `Passed` / `Failed`, or empty when no pass threshold is configured.
     */
    public function passfail(): string
    {
        $results = $this->results();

        if (! $results instanceof QuizResults || ! $results->hasQuestions() || $results->pass === null) {
            return '';
        }

        return $results->pass ? 'Passed' : 'Failed';
    }

    /**
     * {{ quiz:percent }} — the percentage score as a bare number (the message adds any `%`).
     */
    public function percent(): string
    {
        $results = $this->results();

        return $results instanceof QuizResults && $results->hasQuestions() ? (string) $results->percent : '';
    }

    /**
     * {{ quiz:score }} — the total score, or a single question's score when `handle` is given.
     */
    public function score(): string
    {
        $results = $this->results();

        if (! $results instanceof QuizResults || ! $results->hasQuestions()) {
            return '';
        }

        $handle = $this->handleParam();

        if ($handle !== '') {
            $question = $results->question($handle);

            return $question instanceof QuizQuestionResult ? $this->formatNumber($question->score) : '';
        }

        return $this->formatNumber($results->score);
    }

    /**
     * Score the current submission, or null when no form is in context.
     */
    private function results(): ?QuizResults
    {
        // With scoring switched off, every method on the tag renders nothing
        if (! Features::quizScoring()) {
            return null;
        }

        $form = $this->resolveForm();

        if (! $form instanceof Form) {
            return null;
        }

        return app(QuizScorer::class)->score($form, $this->answers($form));
    }

    /**
     * Render a single scored question as an escaped HTML block.
     */
    private function renderQuestion(QuizQuestionResult $question): string
    {
        $lines = [];
        $lines[] = '<div class="quiz-result" data-handle="'.e($question->handle).'">';
        $lines[] = '<p class="quiz-question">'.e($question->label).'</p>';
        $lines[] = '<ul class="quiz-answers">';

        foreach ($question->choices as $choice) {
            $classes = ['quiz-answer'];
            $markers = [];

            if ($choice['is_correct']) {
                $classes[] = 'is-correct';
                $markers[] = 'correct answer';
            }

            if ($choice['is_selected']) {
                $classes[] = 'is-selected';
                $markers[] = 'your answer';
            }

            $suffix = $markers === [] ? '' : ' ('.implode(', ', $markers).')';
            $lines[] = '<li class="'.e(implode(' ', $classes)).'">'.e($choice['label'].$suffix).'</li>';
        }

        $lines[] = '</ul>';

        if ($question->explanation !== null) {
            $lines[] = '<p class="quiz-explanation">'.e($question->explanation).'</p>';
        }

        $lines[] = '</div>';

        return implode("\n", $lines);
    }
}
