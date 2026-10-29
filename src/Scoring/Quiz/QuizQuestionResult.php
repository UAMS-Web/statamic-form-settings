<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Scoring\Quiz;

use UAMSWeb\FormSettings\Scoring\QuizScorer;
use UAMSWeb\FormSettings\Tags\Quiz;

/**
 * The scored outcome of a single quiz question within a submission (UAMS-Web/uams-statamic-archive#209).
 *
 * Produced by {@see QuizScorer} and consumed by the
 * {@see Quiz} merge tag to render score/answer output in form
 * confirmations and email notifications.
 */
final readonly class QuizQuestionResult
{
    /**
     * @param  string  $handle  The quiz field's handle.
     * @param  string  $label  The question's display label.
     * @param  float  $score  The points earned for this question.
     * @param  bool  $isCorrect  Whether the answer was completely correct.
     * @param  bool  $answered  Whether the respondent selected any answer.
     * @param  list<string>  $submitted  The submitted answer label(s).
     * @param  list<string>  $correctAnswers  The configured correct answer label(s).
     * @param  string|null  $explanation  The answer explanation, when enabled and provided.
     * @param  list<array{label: string, is_correct: bool, is_selected: bool}>  $choices  Every configured answer, with correct/selected flags.
     */
    public function __construct(
        public string $handle,
        public string $label,
        public float $score,
        public bool $isCorrect,
        public bool $answered,
        public array $submitted,
        public array $correctAnswers,
        public ?string $explanation,
        public array $choices,
    ) {}
}
