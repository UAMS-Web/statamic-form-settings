<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Scoring;

use Illuminate\Support\Collection;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fieldtype;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Fieldtypes\LikertScale;
use UAMSWeb\FormSettings\Scoring\Survey\SurveyQuestionResult;
use UAMSWeb\FormSettings\Scoring\Survey\SurveyResults;

/**
 * Scores a Gravity-Forms-style survey submission (UAMS-Web/uams-statamic-archive#210).
 *
 * Faithful to the Gravity Forms Survey add-on's `get_survey_score()` /
 * `get_field_score()` (`gravityformssurvey/class-gf-survey.php`): the survey
 * total is the sum of every Likert field's score, and a Likert field's score is
 * the sum of the numeric value(s) the respondent selected.
 *
 * In this project's model a {@see LikertScale} option's *value*
 * is its numeric point (its label lookup comes from the fieldtype's
 * `preload()`), so scoring reads the point straight from the submitted value:
 *
 *   - **Single-row** Likert: the submitted value is one point (or
 *     `not_applicable`, which scores 0).
 *   - **Multi-row** Likert matrix (`enable_multiple_rows`): the submitted value
 *     is a `rowLabel => point` map; the field score sums the per-row points.
 *
 * The public rendering that produces those submitted values is delivered by the
 * LikertScale fieldtype work (UAMS-Web/uams-statamic-archive#194); this scorer defines and consumes the
 * submit shape those controls write.
 */
final class SurveyScorer
{
    /**
     * Score a survey submission against its form's Likert fields.
     *
     * @param  Form  $form  The submitted form.
     * @param  array<string, mixed>  $answers  The submitted values, keyed by field handle.
     */
    public function score(Form $form, array $answers): SurveyResults
    {
        $questions = [];
        $total = 0.0;

        foreach ($this->likertFields($form) as $handle => $field) {
            $result = $this->scoreQuestion($handle, $field, $answers[$handle] ?? null);

            $questions[] = $result;
            $total += $result->score;
        }

        return new SurveyResults(score: $total, questions: $questions);
    }

    /**
     * The form's Likert fields, keyed by handle.
     *
     * @return array<string, Field>
     */
    private function likertFields(Form $form): array
    {
        $blueprint = $form->blueprint();

        if (! $blueprint instanceof Blueprint) {
            return [];
        }

        $fields = [];

        foreach ($blueprint->fields()->all() as $handle => $field) {
            // Fields::all() holds only Field instances; the check narrows the type for PHPStan.
            // @pest-mutate-ignore: InstanceOfToTrue
            if ($field instanceof Field && $field->type() === 'likert_scale') {
                // PHP stores a numeric-string array key as an int whether or not it is cast.
                // @pest-mutate-ignore: RemoveStringCast
                $fields[(string) $handle] = $field;
            }
        }

        return $fields;
    }

    /**
     * Score a single Likert question against the respondent's submitted value.
     *
     * @param  mixed  $value  The submitted value for this field.
     */
    private function scoreQuestion(string $handle, Field $field, mixed $value): SurveyQuestionResult
    {
        $config = $field->config();
        $labels = $this->optionLabels($field);
        $multiRow = $this->isTruthy($config['enable_multiple_rows'] ?? false);

        $rows = [];
        $score = 0.0;
        $answered = false;

        if ($multiRow) {
            $selections = $this->toMap($value);

            foreach ($this->rowLabels($config) as $rowLabel) {
                $resolved = $this->resolvePoint($selections[$rowLabel] ?? null, $labels);

                // Record the row only when the respondent rated it
                if ($resolved !== null) {
                    $answered = true;
                    $score += $resolved['score'];
                    $rows[] = ['row' => $rowLabel, 'label' => $resolved['label'], 'score' => $resolved['score']];
                }
            }
        } else {
            $resolved = $this->resolvePoint($value, $labels);

            // Record the single row only when the respondent rated it
            if ($resolved !== null) {
                $answered = true;
                $score += $resolved['score'];
                $rows[] = ['row' => null, 'label' => $resolved['label'], 'score' => $resolved['score']];
            }
        }

        return new SurveyQuestionResult(
            handle: $handle,
            label: $this->fieldLabel($field, $handle),
            score: $score,
            answered: $answered,
            multipleRows: $multiRow,
            rows: $rows,
        );
    }

    /**
     * Resolve a submitted point to its score and display label.
     *
     * @param  mixed  $point  The submitted value (a numeric point, `not_applicable`, or empty).
     * @param  array<string, string>  $labels  The point => label map for this field.
     * @return array{score: float, label: string}|null The resolved point, or null when
     *                                                 nothing was submitted or the value
     *                                                 is not on the configured scale.
     */
    private function resolvePoint(mixed $point, array $labels): ?array
    {
        // Treat a missing or non-scalar submission as unanswered
        if (! \is_scalar($point)) {
            return null;
        }

        $key = (string) $point;

        // Treat an empty value, or one outside the configured scale, as no rating:
        // it earns nothing (UAMS-Web/uams-statamic-archive#3105). No scale offers an empty value.
        if (! \array_key_exists($key, $labels)) {
            return null;
        }

        return [
            'score' => is_numeric($point) ? (float) $point : 0.0,
            'label' => $labels[$key],
        ];
    }

    /**
     * The point => label map for a Likert field, from its fieldtype preload.
     *
     * @return array<string, string>
     */
    private function optionLabels(Field $field): array
    {
        $fieldtype = $field->fieldtype();

        // A likert_scale field always resolves to its fieldtype; the check narrows the type.
        // @pest-mutate-ignore: InstanceOfToTrue
        if (! $fieldtype instanceof Fieldtype) {
            return [];
        }

        $preload = $fieldtype->preload();
        $options = \is_array($preload) ? ($preload['options'] ?? []) : [];

        if (! \is_array($options)) {
            return [];
        }

        $labels = [];

        foreach ($options as $option) {
            if (! \is_array($option)) {
                continue;
            }

            $value = $option['value'] ?? null;
            $label = $option['label'] ?? null;

            // preload() gives every option a scalar value and label, so both checks hold
            // together; they narrow the types for PHPStan.
            // @pest-mutate-ignore: BooleanAndToBooleanOr
            if (\is_scalar($value) && \is_scalar($label)) {
                // PHP stores a numeric-string array key as an int whether or not it is cast
                // @pest-mutate-ignore: RemoveStringCast
                $point = (string) $value;
                // preload() returns every label as a string already
                // @pest-mutate-ignore: RemoveStringCast
                $labels[$point] = (string) $label;
            }
        }

        return $labels;
    }

    /**
     * The configured row labels for a multi-row Likert field, in order.
     *
     * Delegates to {@see LikertScale::normalizeRowLabels()} — the single source
     * of truth for the row-key contract — so the keys this scorer looks up are
     * exactly the ones the front-end template writes as `name="{handle}[label]"`
     * (brackets stripped, trimmed, blanks dropped, de-duplicated). If the two
     * derived the labels independently they could disagree and silently misscore.
     *
     * @param  array<array-key, mixed>  $config  The field's config.
     * @return list<string>
     */
    private function rowLabels(array $config): array
    {
        return LikertScale::normalizeRowLabels($config['multiple_rows'] ?? []);
    }

    /**
     * Normalize a multi-row submitted value to a `rowLabel => point` map.
     *
     * @param  mixed  $value  The submitted value.
     * @return array<array-key, mixed>
     */
    private function toMap(mixed $value): array
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        return \is_array($value) ? $value : [];
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
