<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Support;

use UAMSWeb\FormSettings\CpForms\EmailGridBlueprintCustomizer;

/**
 * Resolves a form email notification's TO / CC / BCC / Reply-To recipients from the
 * configured recipient source (UAMS-Web/uams-statamic#201).
 *
 * Each recipient kind carries a `{kind}_type` selector: `email`, `field`, or `routing`
 * (added to the CP email grid by {@see EmailGridBlueprintCustomizer}
 * to receive imported Gravity Forms / Formidable recipient configs):
 *
 *   - `email`   → the literal address in `{kind}` (admin-authored; may carry merge tags,
 *                 so it is returned untouched for the mailable's Antlers pass to render).
 *   - `field`   → the submitted value of the form field named in `{kind}_field`.
 *   - `routing` → the GF/Formidable routing model in `{kind}_routing`: a list of
 *                 `{field, operator, value, email}` rows; every row whose condition matches
 *                 the submission contributes its address.
 *
 * Addresses resolved from submission data (`field` / `routing`) are filtered to syntactically
 * valid emails AND rejected if they contain template delimiters (`{` / `}`) or control characters.
 * The email check alone is not enough: PHP's `FILTER_VALIDATE_EMAIL` accepts a quoted local part
 * such as `"{{secret}}"@x.test`, so without the delimiter/control-char rejection a submitter could
 * smuggle Antlers into a recipient that the mailable's `parseConfig` pass later renders server-side
 * (an SSTI), or inject extra recipients via a comma the post-render address split would re-introduce.
 * A real address never needs braces or control characters, so rejecting them closes that hole, while
 * the admin-authored `email`-type literal (which legitimately may carry merge tags) is left untouched.
 */
final class RecipientResolver
{
    public function __construct(
        private readonly ConditionalLogicEvaluator $evaluator = new ConditionalLogicEvaluator,
    ) {}

    /**
     * Resolve one recipient kind to a comma-joined address string ('' when unresolved).
     *
     * @param  array<string, mixed>  $config  The notification config.
     * @param  array<string, mixed>  $values  The submitted field values, keyed by handle.
     * @param  string  $kind  One of 'to', 'cc', 'bcc', 'reply_to'.
     */
    public function resolve(array $config, array $values, string $kind): string
    {
        $type = $config[$kind.'_type'] ?? 'email';
        $type = \is_string($type) ? $type : 'email';

        return match ($type) {
            'field' => $this->resolveField($config, $values, $kind),
            'routing' => $this->resolveRouting($config, $values, $kind),
            default => $this->literal($config, $kind),
        };
    }

    /**
     * The literal admin-authored address for the kind, untouched (may carry merge tags).
     *
     * @param  array<string, mixed>  $config
     */
    private function literal(array $config, string $kind): string
    {
        $value = $config[$kind] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * Resolve a `field`-type recipient from the submitted value of the configured field.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $values
     */
    private function resolveField(array $config, array $values, string $kind): string
    {
        $handle = $config[$kind.'_field'] ?? null;

        if (! \is_scalar($handle) || (string) $handle === '') {
            return '';
        }

        // `data_get()` is declared without strict types, so its `explode()` coerces a scalar key exactly as this cast does.
        // @pest-mutate-ignore: RemoveStringCast
        return implode(', ', $this->validAddresses(data_get($values, (string) $handle)));
    }

    /**
     * Resolve a `routing`-type recipient: the address of every routing row whose condition matches.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $values
     */
    private function resolveRouting(array $config, array $values, string $kind): string
    {
        $rules = $config[$kind.'_routing'] ?? null;

        if (! \is_array($rules)) {
            return '';
        }

        $emails = [];
        foreach ($rules as $rule) {
            if (! \is_array($rule)) {
                continue;
            }

            /** @var array<string, mixed> $rule */
            if ($this->evaluator->matchesRule($rule, $values)) {
                $emails[] = $rule['email'] ?? null;
            }
        }

        return implode(', ', $this->validAddresses($emails));
    }

    /**
     * Split a value (scalar or array, comma-delimited) into unique, syntactically valid emails.
     *
     * @return list<string>
     */
    private function validAddresses(mixed $value): array
    {
        $candidates = [];

        foreach (\is_array($value) ? $value : [$value] as $entry) {
            if (! \is_scalar($entry)) {
                continue;
            }

            foreach (explode(',', (string) $entry) as $part) {
                $candidates[] = trim($part);
            }
        }

        $valid = [];
        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            // Reject template delimiters and control characters. FILTER_VALIDATE_EMAIL accepts a
            // quoted local part like "{{secret}}"@x.test, so the email check alone does NOT stop a
            // submitter from smuggling Antlers ({{ }}) or header content into a field/routing-derived
            // recipient, which the mailable's Antlers/address pass would otherwise execute or re-split.
            // A real address never needs braces or control chars.
            if (preg_match('/[{}\x00-\x1F\x7F]/', $candidate) === 1) {
                continue;
            }

            $valid[] = $candidate;
        }

        // Both callers only `implode()` this list, so the keys `array_values()` renumbers are never read.
        // @pest-mutate-ignore: UnwrapArrayValues
        return array_values(array_unique($valid));
    }
}
