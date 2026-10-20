<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Mail;

use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Statamic\Fields\Field;
use Statamic\Fields\Value;
use Statamic\Forms\Email;
use Statamic\Forms\Submission;
use Statamic\Support\Arr;
use UAMSWeb\FormSettings\Support\EmailMessageBody;
use UAMSWeb\FormSettings\Support\ReplicatorEmailRows;
use UAMSWeb\FormSettings\Support\SubmittedFieldDisplay;

/**
 * Form-notification mailable that honors the email config's `from_name` field.
 *
 * Statamic's base {@see Email} mailable wires the `From:` header from the bare
 * `from` address only: it has no awareness of the project-specific `from_name`
 * column added to the CP email grid. This subclass layers the resolved display
 * name on top of the parent's address wiring so outgoing notifications read
 * `"Resolved Name" <sender@example.com>` instead of a bare email address.
 *
 * The `from_name` value is Antlers / merge-tag resolved by the parent's
 * {@see Email::parseConfig()} (which maps over every config key) before
 * {@see self::addAddresses()} runs, so submitted field references such as
 * `{{ submission:name }}` resolve against the same submission context as the
 * subject and body.
 *
 * It also guards the parent's config parsing against non-string values: the project's
 * email config carries control keys that are arrays/bools (the `conditional_logic` array
 * for UAMS-Web/uams-statamic#202, the recipient routing arrays for UAMS-Web/uams-statamic#201, the `enabled` toggle for UAMS-Web/uams-statamic#204), and the
 * parent maps `Parse::env()` (a `str_contains`) over every config value, which would crash
 * on a non-string. {@see self::parseConfig()} renders only the string fields and passes the
 * rest through untouched.
 *
 * It also makes the custom Assets fieldtype's per-field `attach_to_email` toggle the single
 * source of truth for which uploads attach (UAMS-Web/uams-statamic#196): {@see self::addAttachments()} hides
 * un-flagged Assets fields from the parent's (notification-gated) attachment pass.
 *
 * And it sends the email row's `message` as the body (UAMS-Web/uams-statamic#3149): {@see self::build()} renders
 * the row's Bard message with the submission's merge tags into the HTML part and a plain-text
 * part, and keeps Statamic's field list only when the message is empty.
 *
 * Swapped in for the base mailable by UAMSWeb\FormSettings\Jobs\SendFormNotification, which
 * the `statamic.forms.send_email_job` config points at. Settles issues UAMS-Web/uams-statamic#213, UAMS-Web/uams-statamic#196.
 */
final class FormNotificationEmail extends Email
{
    /**
     * Config keys left out of the parent's Antlers pass.
     *
     * The `message` is rendered by {@see self::applyMessageBody()} against escaped submitted
     * values, so parsing it here first would evaluate it against the raw ones.
     *
     * @var list<string>
     */
    private const array UNPARSED_CONFIG_KEYS = ['message'];

    /**
     * The rendered `message` HTML sent as the body, or null to send the parent's view.
     *
     * @var string|null HTML part set by applyMessageBody() when the row's message has text.
     */
    private ?string $messageHtml = null;

    /**
     * The plain-text form of the rendered `message`, sent as the text part beside it.
     *
     * @var string Text part derived from the HTML part by EmailMessageBody::text().
     */
    private string $messageText = '';

    /**
     * Build the message, then replace the field list with the row's `message` when it has text.
     */
    #[\Override]
    public function build(): void
    {
        // Let the parent wire the subject, addresses, view, attachments and view data
        parent::build();

        // Send the row's message as the body in place of the field list
        $this->applyMessageBody();
    }

    /**
     * Send the rendered `message` as the HTML and text parts, when there is one.
     *
     * @return array<string, HtmlString>|array<int, string>|string|null
     */
    #[\Override]
    protected function buildView()
    {
        // Without a rendered message, send whichever view the parent configured
        if ($this->messageHtml === null) {
            /** @var array<string, HtmlString>|array<int, string>|string|null */
            return parent::buildView();
        }

        return [
            'html' => new HtmlString($this->messageHtml),
            'text' => new HtmlString($this->messageText),
        ];
    }

    /**
     * Attach readable set blocks to every replicator field so the email can render them (UAMS-Web/uams-statamic#2055).
     *
     * Statamic's automagic template has no replicator branch, so a replicator falls through to
     * `{{ value | json | sanitize }}` and reaches the recipient as escaped JSON (UAMS-Web/uams-statamic#2051). The
     * template override in `resources/views/vendor/statamic/forms/automagic-email.antlers.html`
     * consumes the `email_rows` added here; every other fieldtype is passed through untouched.
     *
     * @param  mixed  $values  The augmented submission values, keyed by handle.
     * @return Collection<int, mixed>
     */
    #[\Override]
    protected function getRenderableFieldData($values)
    {
        // The parent is untyped, but always returns collect(...)->map(...)->values().
        /** @var Collection<int, mixed> $fields */
        $fields = parent::getRenderableFieldData($values);

        return $fields->map(function (mixed $field): mixed {
            // Only replicators need the extra shape; everything else renders as it always has.
            if (! is_array($field) || ($field['fieldtype'] ?? null) !== 'replicator') {
                return $field;
            }

            $value = $field['value'] ?? null;
            $config = is_array($field['config'] ?? null) ? $field['config'] : [];

            $field['email_rows'] = ReplicatorEmailRows::build(
                $value instanceof Value ? $value->raw() : $value,
                $config
            );

            return $field;
        });
    }

    /**
     * Parse only the string-valued config entries through the parent's Antlers/env pipeline.
     *
     * Non-string control keys (the `conditional_logic` array, the `enabled` toggle, the
     * recipient routing arrays) are send-pipeline metadata, not renderable fields, and the
     * parent's `Parse::env()` would `str_contains` them and crash. They are kept verbatim so
     * the consumers downstream still see them while the string fields render exactly as before.
     *
     * @param  array<string, mixed>  $config  The raw email-notification config.
     * @return Collection<string, mixed>
     */
    #[\Override]
    protected function parseConfig(array $config)
    {
        // Render only the string-valued entries, then overlay them back onto the original so
        // the non-string control keys survive unchanged.
        $result = $config;
        $parsed = parent::parseConfig(array_filter(
            $config,
            static fn (mixed $value, string|int $key): bool => is_string($value)
                && ! in_array($key, self::UNPARSED_CONFIG_KEYS, true),
            ARRAY_FILTER_USE_BOTH
        ));

        // Equivalent: the parent always returns a Collection. And without the (string) cast,
        // an array key writes the same entry, since PHP normalizes numeric-string keys either way.
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveStringCast
        if ($parsed instanceof Collection) {
            foreach ($parsed->all() as $key => $value) {
                $result[(string) $key] = $value;
            }
        }

        return collect($result);
    }

    /**
     * Attach uploads honoring each Assets field's `attach_to_email` opt-in (UAMS-Web/uams-statamic#196).
     *
     * The parent gates on the notification's `attachments` toggle (which selects *which*
     * notifications carry attachments) and then attaches every `assets`/`files` field's
     * uploads. This project's custom Assets fieldtype adds a per-field `attach_to_email`
     * toggle that is the single source of truth for *whether* that field's uploads attach.
     * We honor it by hiding the un-flagged Assets fields from the parent's attachment pass
     * (so only flagged fields attach, none twice), then restoring the data the later
     * `addData()` render reads. `files` fields and the notification-level gate are left to
     * the parent unchanged.
     *
     * @return $this
     */
    #[\Override]
    protected function addAttachments()
    {
        // Snapshot the augmented submission data the parent attaches from.
        $original = $this->submissionData;

        // Without an array there is nothing to filter: defer entirely to the parent.
        // Not observable through build(), which always sets an array from toAugmentedArray().
        // @pest-mutate-ignore: RemoveMethodCall
        if (! is_array($original)) {
            parent::addAttachments();

            return $this;
        }

        // Hide Assets fields that are not flagged to attach, then let the parent run
        // its (notification-gated) attachment pass over what remains.
        $this->submissionData = $this->withoutUnflaggedAssetFields($original);

        parent::addAttachments();

        // Restore the full data for the subsequent addData() render context.
        $this->submissionData = $original;

        return $this;
    }

    /**
     * Remove Assets fields whose `attach_to_email` toggle is off from a submission-data map.
     *
     * `files` fields and every non-Assets entry are left untouched; only an Assets field
     * with a falsy `attach_to_email` config is dropped, so the parent never attaches it.
     *
     * @param  array<array-key, mixed>  $data  The augmented submission data, keyed by handle.
     * @return array<array-key, mixed>
     */
    private function withoutUnflaggedAssetFields(array $data): array
    {
        return collect($data)->reject(function ($value): bool {
            // Keep entries that are not resolvable field values: the synthetic id / date /
            // form keys are Value objects with a null fieldtype, so field() would error.
            if (! $value instanceof Value || $value->fieldtype() === null) {
                return false;
            }

            $field = $value->field();

            // Only Assets fields are subject to the per-field opt-in; leave the rest.
            if (! $field instanceof Field || $field->type() !== 'assets') {
                return false;
            }

            // Drop the Assets field when its attach_to_email toggle is not truthy.
            $attach = $field->config()['attach_to_email'] ?? false;

            return ! filter_var($attach, FILTER_VALIDATE_BOOLEAN);
        })->all();
    }

    /**
     * Wire the message addresses, then apply the optional `from_name` display name.
     *
     * @return $this
     */
    #[\Override]
    protected function addAddresses(): self
    {
        // Let the parent wire to / from / reply_to / cc / bcc from the resolved config
        parent::addAddresses();

        // Layer the resolved from_name display name onto the From header
        return $this->applyFromName();
    }

    /**
     * Rebuild the From header so each sender address carries the resolved `from_name`.
     *
     * Falls back to the parent's bare-email From header when `from_name` is absent
     * or resolves to an empty string after Antlers / merge-tag resolution.
     *
     * @return $this
     */
    private function applyFromName(): self
    {
        // The parent stores the resolved email config as an array
        /** @var array<string, mixed> $config */
        $config = $this->config;

        // Read the already-resolved display name, trimming render whitespace
        $rawFromName = Arr::get($config, 'from_name', '');
        $fromName = trim(is_scalar($rawFromName) ? (string) $rawFromName : '');

        // Read the sender address the parent used for the From header
        $from = Arr::get($config, 'from');

        // Keep the parent's behavior when there is no display name or no sender
        if ($fromName === '' || ! $from) {
            return $this;
        }

        // Discard the parent's bare-email From header so it can be rebuilt with the name
        $this->from = [];

        // The parent parses the sender string into address/name pairs
        /** @var iterable<array{email: string, name: string|null}> $parsedAddresses */
        $parsedAddresses = $this->addresses($from) ?? [];

        // Re-add every sender address carrying the resolved display name
        foreach ($parsedAddresses as $address) {
            $this->from($address['email'], $fromName);
        }

        return $this;
    }

    /**
     * Replace the field-list view with the row's rendered `message`, when it holds text (UAMS-Web/uams-statamic#3149).
     *
     * A row with its own `html` or `text` template keeps that template, and a row whose message
     * is empty keeps Statamic's field list, so only a written message changes what is sent. The
     * message is parsed against the view data with every submitted value swapped for its escaped
     * display HTML, both as a top-level variable and under `submission`, so a value carrying
     * markup or Antlers syntax arrives as text.
     */
    private function applyMessageBody(): void
    {
        // Read the parsed config, which parseConfig() always leaves as a collection, so the
        // instanceof guard only narrows the type
        // @pest-mutate-ignore: InstanceOfToTrue
        $config = $this->config instanceof Collection ? $this->config->all() : [];

        // Keep an explicitly configured template, and the field list for an empty message
        $message = $config['message'] ?? null;

        if (! empty($config['html']) || ! empty($config['text']) || EmailMessageBody::isEmpty($message)) {
            return;
        }

        // Render the message against the view data with every submitted value escaped, and
        // keep it and its plain-text form for buildView()
        $this->messageHtml = EmailMessageBody::html($message, $this->messageContext($this->viewData));
        $this->messageText = EmailMessageBody::text($this->messageHtml);
    }

    /**
     * Build the merge-tag context for the message from the mailable's view data.
     *
     * @param  array<array-key, mixed>  $viewData  The data the parent's `addData()` set.
     * @return array<array-key, mixed>
     */
    private function messageContext(array $viewData): array
    {
        // Resolve every submitted field's escaped display HTML, keyed by its handle. This reads
        // the whole submission rather than the view's `fields`, which leaves out spacers and
        // attached uploads, so no handle is left in the context as an unescaped value.
        $submitted = [];
        $submission = $this->submission;

        // The constructor only accepts a submission, so the instanceof guard only narrows the type
        // @pest-mutate-ignore: InstanceOfToTrue
        $augmented = $submission instanceof Submission ? $submission->toAugmentedArray() : null;

        if (! is_array($augmented)) {
            return $viewData;
        }

        $fields = $this->getRenderableFieldData(Arr::except($augmented, ['id', 'date', 'form']));

        foreach ($fields as $field) {
            // Every entry is an array with a string handle, so this guard only narrows the type
            // @pest-mutate-ignore: BooleanAndToBooleanOr
            if (is_array($field) && is_string($field['handle'] ?? null)) {
                $submitted[$field['handle']] = SubmittedFieldDisplay::html($field);
            }
        }

        // Expose them as top-level variables and under `submission`, beside the submission id
        return array_merge($viewData, $submitted, [
            'submission' => array_merge($submitted, ['id' => $submission->id()]),
        ]);
    }
}
