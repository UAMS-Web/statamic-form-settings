<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Scoring\Quiz;

/**
 * The aggregate result of scoring a Gravity-Forms-style quiz submission (UAMS-Web/uams-statamic-archive#209).
 *
 * Mirrors Gravity Forms' `get_quiz_results()` output: a total/max score, a
 * rounded percentage (plus the unrounded percentage used for grade/pass
 * thresholds), an optional letter grade and pass flag (populated only when the
 * form carries grading config), and the per-question breakdown.
 */
final readonly class QuizResults
{
    /**
     * @param  float  $score  The total points earned across every quiz question.
     * @param  float  $maxScore  The maximum achievable points.
     * @param  int  $percent  The rounded percentage score (for display).
     * @param  float  $rawPercent  The unrounded percentage (for grade/pass thresholds).
     * @param  string  $grade  The letter grade, or '' when no grading config exists.
     * @param  bool|null  $pass  The pass/fail result, or null when no pass threshold is configured.
     * @param  list<QuizQuestionResult>  $questions  The per-question breakdown.
     */
    public function __construct(
        public float $score,
        public float $maxScore,
        public int $percent,
        public float $rawPercent,
        public string $grade,
        public ?bool $pass,
        public array $questions,
    ) {}

    /**
     * Whether the submission contained any quiz questions to score.
     */
    public function hasQuestions(): bool
    {
        return $this->questions !== [];
    }

    /**
     * Find the scored result for a single question by its field handle.
     */
    public function question(string $handle): ?QuizQuestionResult
    {
        foreach ($this->questions as $question) {
            if ($question->handle === $handle) {
                return $question;
            }
        }

        return null;
    }
}
