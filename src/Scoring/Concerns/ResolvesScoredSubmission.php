<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Scoring\Concerns;

use Statamic\Fields\Blueprint;
use Statamic\Fields\Value;
use Statamic\Forms\Form;
use Statamic\Tags\Context;
use Statamic\Tags\Parameters;
use UAMSWeb\FormSettings\Support\TagParam;
use UAMSWeb\FormSettings\Tags\Quiz;
use UAMSWeb\FormSettings\Tags\Survey;

/**
 * Shared plumbing for the {@see Quiz} and {@see Survey}
 * scoring tags.
 *
 * Both tags run inside a form confirmation or email notification, whose message
 * Antlers-renders against the submission's augmented array, the submitted
 * answers keyed by handle, plus the `form`. This trait resolves the form and the
 * answers from that context, reads the `handle` parameter, and formats a score.
 */
trait ResolvesScoredSubmission
{
    /**
     * Resolve the submitted form from the augmented submission context.
     */
    private function resolveForm(): ?Form
    {
        /** @var Context $context */
        $context = $this->context;
        $form = $context->get('form');

        if ($form instanceof Value) {
            $form = $form->value();
        }

        return $form instanceof Form ? $form : null;
    }

    /**
     * Extract the submitted answers, keyed by field handle, from the context.
     *
     * @return array<string, mixed>
     */
    private function answers(Form $form): array
    {
        /** @var Context $context */
        $context = $this->context;
        $blueprint = $form->blueprint();

        if (! $blueprint instanceof Blueprint) {
            return [];
        }

        $answers = [];

        foreach ($blueprint->fields()->all()->keys()->all() as $handle) {
            $value = $context->get($handle);
            // PHP stores a numeric-string array key as an int whether or not it is cast.
            // @pest-mutate-ignore: RemoveStringCast
            $answers[(string) $handle] = $value instanceof Value ? $value->value() : $value;
        }

        return $answers;
    }

    /**
     * The `handle` parameter, coerced to a string ('' when absent).
     */
    private function handleParam(): string
    {
        /** @var Parameters $params */
        $params = $this->params;
        $handle = TagParam::get($params, 'handle');

        return \is_scalar($handle) ? (string) $handle : '';
    }

    /**
     * Format a score as a compact string (whole numbers lose their decimals).
     */
    private function formatNumber(float $number): string
    {
        // Trim trailing zeros, then a bare decimal point, from the two-place form
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
