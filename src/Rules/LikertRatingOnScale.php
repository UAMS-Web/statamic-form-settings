<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accept a Likert submission only when every rating is a value on the field's
 * scale and, for a matrix question, every row is one the question lists (UAMS-Web/uams-statamic-archive#3105).
 *
 * A single-row question submits one value; a matrix question submits a map of
 * row label to value. A matrix row submitted without a rating is skipped,
 * because whether a rating is required is the field's own `required` rule.
 */
class LikertRatingOnScale implements ValidationRule
{
    /**
     * The scale values as strings, the form a submission arrives in.
     *
     * @var list<string>
     */
    private array $scale;

    /**
     * Build the rule for one field's scale and matrix rows.
     *
     * @param  list<int|string>  $scaleValues  The values the scale offers, `not_applicable` included
     *                                         where that option is enabled.
     * @param  list<string>  $rows  The matrix row labels; empty for a single-row question.
     */
    public function __construct(array $scaleValues, private readonly array $rows)
    {
        $this->scale = array_map(fn (int|string $value): string => (string) $value, $scaleValues);
    }

    /**
     * Fail a rating off the scale, or a matrix row the question does not list.
     *
     * @param  string  $attribute  The field being validated.
     * @param  mixed  $value  The submitted rating, or row map for a matrix question.
     * @param  Closure  $fail  Called with the message when validation fails.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Treat a single-row rating as one unnamed row
        $ratings = $this->rows === [] ? [$value] : $value;

        // Fail a matrix rating that does not arrive as a row map
        if (! \is_array($ratings)) {
            $fail(__('statamic-form-settings::validation.likert_scale.not_on_scale'));

            return;
        }

        foreach ($ratings as $row => $rating) {
            // Fail on a row key the matrix does not list
            if ($this->rows !== [] && ! \in_array((string) $row, $this->rows, true)) {
                $fail(__('statamic-form-settings::validation.likert_scale.unknown_row'));

                return;
            }

            // Skip a matrix row submitted without a rating
            if ($rating === null) {
                continue;
            }

            if ($rating === '') {
                continue;
            }

            // Fail on a value off the scale
            if (! \is_scalar($rating) || ! \in_array((string) $rating, $this->scale, true)) {
                $fail(__('statamic-form-settings::validation.likert_scale.not_on_scale'));

                return;
            }
        }
    }
}
