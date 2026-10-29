<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Scoring\Survey;

use UAMSWeb\FormSettings\Scoring\SurveyScorer;
use UAMSWeb\FormSettings\Tags\Survey;

/**
 * The scored outcome of a single survey (Likert) question within a submission (UAMS-Web/uams-statamic-archive#210).
 *
 * Produced by {@see SurveyScorer} and consumed by the
 * {@see Survey} merge tag. A single-row Likert question contributes one
 * row (`row` = null); a multi-row Likert contributes one row per configured row
 * label.
 */
final readonly class SurveyQuestionResult
{
    /**
     * @param  string  $handle  The Likert field's handle.
     * @param  string  $label  The question's display label.
     * @param  float  $score  The summed numeric score across the answered rows.
     * @param  bool  $answered  Whether the respondent selected any value.
     * @param  bool  $multipleRows  Whether this is a multi-row Likert matrix.
     * @param  list<array{row: string|null, label: string, score: float}>  $rows  Per-row selection (row label, chosen option label, its score).
     */
    public function __construct(
        public string $handle,
        public string $label,
        public float $score,
        public bool $answered,
        public bool $multipleRows,
        public array $rows,
    ) {}
}
