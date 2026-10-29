<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Scoring\Survey;

/**
 * The aggregate result of scoring a Gravity-Forms-style survey submission (UAMS-Web/uams-statamic-archive#210).
 *
 * Mirrors the Gravity Forms Survey add-on's `get_survey_score()`: the total is
 * the sum of every scored Likert field's value. In the Statamic model a Likert
 * option's value *is* its numeric point, so the score is read directly from the
 * submitted value rather than a separate score map.
 */
final readonly class SurveyResults
{
    /**
     * @param  float  $score  The total survey score across every Likert question.
     * @param  list<SurveyQuestionResult>  $questions  The per-question breakdown.
     */
    public function __construct(
        public float $score,
        public array $questions,
    ) {}

    /**
     * Whether the submission contained any survey (Likert) questions.
     */
    public function hasQuestions(): bool
    {
        return $this->questions !== [];
    }

    /**
     * Find the scored result for a single question by its field handle.
     */
    public function question(string $handle): ?SurveyQuestionResult
    {
        foreach ($this->questions as $question) {
            if ($question->handle === $handle) {
                return $question;
            }
        }

        return null;
    }
}
