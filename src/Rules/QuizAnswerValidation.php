<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Custom validation rule for Quiz fieldtype to ensure proper answer selection
 * based on the question fieldtype.
 *
 *   - For radio/select: exactly one answer must be marked as correct
 *   - For checkbox: at least one answer must be marked as correct
 */
class QuizAnswerValidation implements DataAwareRule, ValidationRule
{
    /**
     * Fire on empty values so a grid with no rows still fails the "at least one
     * correct answer" check.
     *
     * Without this, Laravel's `InvokableValidationRule::make()` skips the rule
     * when the attribute is missing or empty, defeating the rule's purpose.
     */
    public bool $implicit = true;

    /**
     * All of the data under validation.
     *
     * @var array<string, mixed>
     */
    protected $data = [];

    /**
     * Set the data under validation.
     *
     * @param  array<string, mixed>  $data
     */
    public function setData(
        array $data
    ): static {
        // Store the data array for use in validation
        $this->data = $data;

        // Return this instance for method chaining
        return $this;
    }

    /**
     * Run the validation rule.
     *
     * @param  string  $attribute  The name of the field being validated
     * @param  mixed  $value  The value of the field
     * @param  Closure  $fail  The closure to call if validation fails
     */
    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail
    ): void {
        // Get the question fieldtype from the data
        $questionFieldtype = $this->data['question_fieldtype'] ?? null;

        // Check if no question fieldtype is set
        if (! $questionFieldtype) {
            // Return early to skip validation
            return;
        }

        // ---
        // Ensure value is an array (grid data)
        // ---

        // Check if the value is not an array
        if (! \is_array($value)) {
            // Fail validation with a specific message
            $fail(__('statamic-form-settings::validation.quiz_answer.array'));

            // Return early to prevent further validation
            return;
        }

        // Count correct answers
        $correctAnswers = collect($value)->filter(
            fn ($option): bool => \is_array($option) && isset($option['is_correct']) && $option['is_correct'] === true
        )->count();

        // Check if the question takes a single answer (radio or select)
        if (\in_array($questionFieldtype, ['radio', 'select'], true)) {
            // Check if exactly one answer is marked as correct
            if ($correctAnswers !== 1) {
                // Fail validation with a specific message
                $fail(__('statamic-form-settings::validation.quiz_answer.marked_correct.exactly_one'));
            }

            // Return early, since a single-answer question is fully checked
            return;
        }

        // Check if at least one answer is marked as correct for checkboxes and
        // any other fieldtype
        if ($correctAnswers < 1) {
            // Fail validation with a specific message
            $fail(__('statamic-form-settings::validation.quiz_answer.marked_correct.at_least_one'));
        }
    }
}
