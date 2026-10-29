<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Scoring;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Scoring\Quiz\QuizQuestionResult;
use UAMSWeb\FormSettings\Scoring\Quiz\QuizResults;

/**
 * Scores a Gravity-Forms-style quiz submission (UAMS-Web/uams-statamic-archive#209).
 *
 * Faithful to the Gravity Forms Quiz add-on's `get_quiz_results()` /
 * `get_max_score()` (`gravityformsquiz/class-gf-quiz.php`):
 *
 *   - **Weighted scoring** (`weighted_score_query`): a question earns the sum of
 *     the `weighted_score` of every selected answer; its max is the sum of the
 *     positive weights for checkbox questions, or the single highest weight for
 *     radio/select questions.
 *   - **Unweighted scoring**: a question earns 1 point only when *completely*
 *     correct (every correct answer selected and no incorrect answer selected,
 *     mirroring GF's `completely_correct`); its max is 1.
 *   - The total percentage drives the optional letter **grade** (the highest
 *     grade whose threshold the percentage meets) and **pass/fail** (percentage
 *     ≥ the pass threshold). Both are read from optional form-level config
 *     ({@see self::grade()} / {@see self::pass()}) and stay empty/null until a
 *     form defines them.
 *
 * A submitted quiz answer is stored as the answer *label* (the Quiz fieldtype
 * maps each answer's value to its own label), so scoring matches submitted
 * labels against the configured answer labels.
 * An answer that matches no configured label earns no credit: the question is
 * not completely correct, and a weighted question has no weight for it (UAMS-Web/uams-statamic-archive#3105).
 */
final class QuizScorer
{
    /**
     * Score a quiz submission against its form's quiz fields.
     *
     * @param  Form  $form  The submitted form.
     * @param  array<string, mixed>  $answers  The submitted values, keyed by field handle.
     */
    public function score(Form $form, array $answers): QuizResults
    {
        $questions = [];
        $totalScore = 0.0;
        $maxScore = 0.0;

        foreach ($this->quizFields($form) as $handle => $field) {
            $result = $this->scoreQuestion($handle, $field, $answers[$handle] ?? null);

            $questions[] = $result;
            $totalScore += $result->score;
            $maxScore += $this->maxScoreForField($field);
        }

        // GF clamps a negative total (possible with negative weights) up to zero.
        $totalScore = max($totalScore, 0.0);
        $rawPercent = $maxScore > 0.0 ? $totalScore / $maxScore * 100 : 0.0;

        return new QuizResults(
            score: $totalScore,
            maxScore: $maxScore,
            percent: (int) round($rawPercent),
            rawPercent: $rawPercent,
            grade: $this->grade($form, $rawPercent),
            pass: $this->pass($form, $rawPercent),
            questions: $questions,
        );
    }

    /**
     * The form's quiz fields, keyed by handle.
     *
     * @return array<string, Field>
     */
    private function quizFields(Form $form): array
    {
        $blueprint = $form->blueprint();

        if (! $blueprint instanceof Blueprint) {
            return [];
        }

        $fields = [];

        foreach ($blueprint->fields()->all() as $handle => $field) {
            // Fields::all() holds only Field instances; the check narrows the type for PHPStan.
            // @pest-mutate-ignore: InstanceOfToTrue
            if ($field instanceof Field && $field->type() === 'quiz') {
                // PHP stores a numeric-string array key as an int whether or not it is cast.
                // @pest-mutate-ignore: RemoveStringCast
                $fields[(string) $handle] = $field;
            }
        }

        return $fields;
    }

    /**
     * Score a single quiz question against the respondent's submitted value.
     *
     * @param  mixed  $value  The submitted value for this field.
     */
    private function scoreQuestion(string $handle, Field $field, mixed $value): QuizQuestionResult
    {
        $config = $field->config();
        $weighted = $this->isTruthy($config['weighted_score_query'] ?? false);
        $isCheckbox = ($config['question_fieldtype'] ?? 'radio') === 'checkboxes';
        $options = $this->options($field);
        $submitted = $this->submittedLabels($value);
        $answered = $submitted !== [];

        $fieldScore = 0.0;
        $completelyCorrect = true;
        $choices = [];
        $correctAnswers = [];

        foreach ($options as $option) {
            $label = $option['label'];
            $isCorrect = $option['is_correct'];
            $matched = \in_array($label, $submitted, true);

            if ($isCorrect) {
                $correctAnswers[] = $label;
            }

            if ($matched && $weighted) {
                $fieldScore += $option['weighted_score'];
            }

            // GF's per-choice "wrong" test: an incorrect answer that was selected, a
            // correct answer that was missed (checkbox only), or a correct answer left
            // unanswered — any of these means the question is not completely correct.
            $isWrong = ((! $isCorrect) && $matched)
                || ($isCheckbox && $isCorrect && ! $matched)
                || ($isCorrect && ! $answered);

            if ($isWrong) {
                $completelyCorrect = false;
            }

            $choices[] = [
                'label' => $label,
                'is_correct' => $isCorrect,
                'is_selected' => $matched,
            ];
        }

        // An answer that matches none of the configured options is not a correct
        // answer, whatever else was selected, so the question is not completely
        // correct (UAMS-Web/uams-statamic-archive#3105). A weighted question already earns nothing for it.
        $labels = array_column($options, 'label');
        foreach ($submitted as $answer) {
            if (! \in_array($answer, $labels, true)) {
                $completelyCorrect = false;
            }
        }

        if (! $weighted && $completelyCorrect) {
            $fieldScore += 1.0;
        }

        return new QuizQuestionResult(
            handle: $handle,
            label: $this->fieldLabel($field, $handle),
            score: $fieldScore,
            isCorrect: $completelyCorrect,
            answered: $answered,
            submitted: $submitted,
            correctAnswers: $correctAnswers,
            explanation: $this->explanation($config),
            choices: $choices,
        );
    }

    /**
     * The maximum achievable points for a single quiz field.
     */
    private function maxScoreForField(Field $field): float
    {
        $config = $field->config();

        if (! $this->isTruthy($config['weighted_score_query'] ?? false)) {
            return 1.0;
        }

        $options = $this->options($field);

        // Checkboxes: every positive weight is achievable at once. Radio/select:
        // only the single highest weight is achievable.
        if (($config['question_fieldtype'] ?? 'radio') === 'checkboxes') {
            return array_reduce(
                $options,
                static fn (float $carry, array $option): float => $carry + max($option['weighted_score'], 0.0),
                0.0,
            );
        }

        return array_reduce(
            $options,
            static fn (float $carry, array $option): float => max($option['weighted_score'], $carry),
            0.0,
        );
    }

    /**
     * The normalized answer options for a quiz field.
     *
     * @return list<array{label: string, is_correct: bool, weighted_score: float}>
     */
    private function options(Field $field): array
    {
        $raw = $field->config()['options'] ?? [];

        if ($raw instanceof Collection) {
            $raw = $raw->all();
        }

        if (! \is_array($raw)) {
            return [];
        }

        $options = [];

        foreach ($raw as $row) {
            if ($row instanceof Collection) {
                $row = $row->all();
            }

            if (! \is_array($row)) {
                continue;
            }

            $label = $row['label'] ?? null;
            if (! \is_scalar($label)) {
                continue;
            }

            if ((string) $label === '') {
                continue;
            }

            $options[] = [
                'label' => (string) $label,
                'is_correct' => $this->isTruthy($row['is_correct'] ?? false),
                // An absent/non-numeric weight scores 0 (not GF's `?: 1` for a missing key): the CP
                // makes `weighted_score` required when weighted scoring is on, and GF's own
                // get_max_score treats the same missing key as 0 — so 0 keeps score and max consistent.
                'weighted_score' => is_numeric($row['weighted_score'] ?? null) ? (float) $row['weighted_score'] : 0.0,
            ];
        }

        return $options;
    }

    /**
     * The letter grade for a percentage, or '' when the form has no grading config.
     *
     * Form-level grading is optional config (`quiz_grades`: a list of
     * `{text, value}` where `value` is the minimum percentage). The highest grade
     * whose threshold the percentage meets wins — GF's `get_grade()`.
     */
    private function grade(Form $form, float $percent): string
    {
        $grades = $form->get('quiz_grades');

        if (! \is_array($grades)) {
            return '';
        }

        $normalized = [];

        foreach ($grades as $grade) {
            if ($grade instanceof Collection) {
                $grade = $grade->all();
            }

            if (! \is_array($grade)) {
                continue;
            }

            $value = $grade['value'] ?? null;
            $text = $grade['text'] ?? null;
            if (! is_numeric($value)) {
                continue;
            }

            if (! \is_scalar($text)) {
                continue;
            }

            // is_numeric() has passed, so PHP compares the raw value numerically in
            // both the sort and the threshold check; the cast only narrows the type.
            // @pest-mutate-ignore: RemoveDoubleCast
            $normalized[] = ['value' => (float) $value, 'text' => (string) $text];
        }

        // Highest threshold first, so the first threshold met is the best grade.
        usort($normalized, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        foreach ($normalized as $grade) {
            if ($grade['value'] <= $percent) {
                return $grade['text'];
            }
        }

        return '';
    }

    /**
     * The pass/fail result for a percentage, or null when no pass threshold is configured.
     */
    private function pass(Form $form, float $percent): ?bool
    {
        $passPercent = $form->get('quiz_pass_percent');

        if (! is_numeric($passPercent)) {
            return null;
        }

        // is_numeric() has passed, so a float compares with the raw value
        // numerically; the cast only narrows the type.
        // @pest-mutate-ignore: RemoveDoubleCast
        return $percent >= (float) $passPercent;
    }

    /**
     * The answer explanation for a question, or null when disabled or empty.
     *
     * @param  array<array-key, mixed>  $config  The field's config.
     */
    private function explanation(array $config): ?string
    {
        if (! $this->isTruthy($config['answer_explanation_query'] ?? false)) {
            return null;
        }

        $explanation = $config['answer_explanation'] ?? null;

        if (! \is_scalar($explanation)) {
            return null;
        }

        $explanation = (string) $explanation;

        return $explanation === '' ? null : $explanation;
    }

    /**
     * The submitted answer labels, normalized to a list of non-empty strings.
     *
     * @param  mixed  $value  The raw submitted value (scalar for radio/select, array for checkboxes).
     * @return list<string>
     */
    private function submittedLabels(mixed $value): array
    {
        $labels = [];

        foreach (Arr::wrap($value) as $item) {
            if (\is_scalar($item) && (string) $item !== '') {
                $labels[] = (string) $item;
            }
        }

        return $labels;
    }

    /**
     * A field's display label, falling back to its handle.
     */
    private function fieldLabel(Field $field, string $handle): string
    {
        $display = $field->display();

        return \is_string($display) && $display !== '' ? $display : $handle;
    }

    /**
     * Whether a stored toggle/boolean config value is on.
     *
     * @param  mixed  $value  The stored config value.
     */
    private function isTruthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }
}
