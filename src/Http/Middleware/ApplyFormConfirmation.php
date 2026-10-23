<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Entries\Entry as EntryInstance;
use Statamic\Facades\Antlers;
use Statamic\Facades\Entry;
use Statamic\Facades\URL;
use Statamic\Forms\Submission;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\FormSettings\Support\EmailMessageBody;
use UAMSWeb\FormSettings\Support\FormConfirmationResolver;

/**
 * Applies a form's configured confirmation to the post-submit response (UAMS-Web/uams-statamic#203).
 *
 * Statamic's `FormController` flashes the submission and a generic success message on a
 * successful POST, then redirects back. This middleware runs after that, on the `/!/forms/*`
 * submit route, and, for a successful submission, replaces the default behavior with the
 * confirmation {@see FormConfirmationResolver} selects (the first additional confirmation whose
 * conditional logic matches, else the default):
 *
 *   - `message`         → override the flashed success message with the configured, Antlers-rendered
 *                         message (the form template shows it in place of the form).
 *   - `redirect`        → redirect to the configured URL (+ optional query string).
 *   - `entry` / `replace` → redirect to the chosen Statamic entry's URL (+ optional query string).
 *                         (`replace`, "show the entry's content in place of the form", is a
 *                         server-side approximation: it navigates to the entry's page.)
 *
 * Only an INTERNAL redirect target is honored; an external/protocol-relative URL is refused (it
 * falls back to the message branch), matching Statamic's own form-redirect guard, so a rendered
 * config/submission value cannot turn the confirmation into an open redirect. It acts only on a
 * submission flashed by the current request, so a stale one-request flash cannot leak a confirmation
 * onto a following failed / different-form / restriction-blocked POST.
 *
 * A form with no confirmation config (resolver returns null) is left to Statamic's default
 * behavior. Registered on the `web` group so it wraps the form-submit route.
 */
class ApplyFormConfirmation
{
    /**
     * Rewrite a successful form-submit response per the form's confirmation config.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('post') || ! $request->is('!/forms/*')) {
            return $response;
        }

        // Act only on a submission flashed by THIS request (a genuine current-request success).
        // session('submission') is one-request flash data that survives into the NEXT request, so
        // reading it alone would apply a confirmation to a following validation failure, a different
        // form's submit, or a restriction-blocked POST (UAMS-Web/uams-statamic#205). `_flash.new` lists the keys flashed
        // by the current request, so a stale carry-over (now in `_flash.old`) is correctly ignored.
        // Laravel writes `_flash.new` as a list of key strings (its own `flash()` fails on anything else), so the cast
        // never sees a non-list and a loose comparison against non-numeric strings matches exactly as the strict one does.
        // @pest-mutate-ignore: RemoveArrayCast, TrueToFalse
        if (! \in_array('submission', (array) session()->get('_flash.new', []), true)) {
            return $response;
        }

        $submission = session('submission');
        if (! $submission instanceof Submission) {
            return $response;
        }

        $form = $submission->form();

        /** @var array<string, mixed> $values */
        $values = $submission->data()->all();
        $settings = (new FormConfirmationResolver)->resolve($form, $values);
        // With no settings every read below falls back to a default that renders no redirect and no message, so this only saves work.
        // @pest-mutate-ignore: RemoveEarlyReturn
        if ($settings === null) {
            return $response;
        }

        $augmented = $submission->toAugmentedArray();
        $context = \is_array($augmented) ? $augmented : [];
        $type = \is_string($settings['confirmation_type'] ?? null) ? $settings['confirmation_type'] : 'message';

        // redirect → a configured URL; entry/replace → the chosen entry's page URL.
        $url = match ($type) {
            // A missing URL falls back to `''`; the redirect guard refuses any text that is not an internal URL, so a non-empty fallback is refused exactly as `''` is.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            'redirect' => $this->withQueryString($this->render($settings['confirmation_redirect_url'] ?? '', $context), $settings, $context),
            'entry' => $this->withQueryString($this->entryUrl($settings['confirmation_page'] ?? null), $settings, $context),
            'replace' => $this->withQueryString($this->entryUrl($settings['confirmation_replace_entry'] ?? null), $settings, $context),
            // An unknown type never redirects, because the check below also requires one of the three redirecting types.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            default => '',
        };

        // Redirect only to an INTERNAL URL: an external target is refused (and falls through to the
        // message branch), matching Statamic's own form-redirect guard, so a rendered config/data
        // value cannot turn the confirmation into an open redirect.
        // `$type` is a string and none of the three types is numeric, so a loose comparison matches exactly the same values.
        // @pest-mutate-ignore: TrueToFalse
        if (\in_array($type, ['redirect', 'entry', 'replace'], true) && $url !== '' && ! URL::isExternalToApplication($url)) {
            return $this->redirectTo($response, $url);
        }

        // message (and a redirect/entry/replace that resolved no usable internal URL): show the
        // configured message in place of Statamic's generic success text.
        $message = $this->renderMessage($settings['confirmation_message'] ?? '', $context);
        if ($message !== '') {
            $handle = $form->handle();
            // The submission was resolved through its form's handle, which is always a string, and concatenation casts a scalar as `(string)` does.
            // @pest-mutate-ignore: RemoveStringCast, EmptyStringToNotEmpty
            session()->flash('form.'.(\is_scalar($handle) ? (string) $handle : '').'.success', $message);
        }

        return $response;
    }

    /**
     * Render the confirmation message: a Bard document or a string, to HTML with its merge tags resolved.
     *
     * The Bard value is augmented to HTML and Antlers runs over it against the submission context, as
     * for a plain string, so a message holds the same trust either way. The HTML is not sanitized:
     * only users who may configure forms can write it. An empty value returns '' so the caller keeps
     * Statamic's own success text.
     *
     * @param  array<array-key, mixed>  $context
     */
    private function renderMessage(mixed $value, array $context): string
    {
        if (EmailMessageBody::isEmpty($value)) {
            return '';
        }

        return trim(EmailMessageBody::html($value, $context));
    }

    /**
     * Antlers-render a config string against the submission context ('' when not a string).
     *
     * @param  array<array-key, mixed>  $context
     */
    private function render(mixed $value, array $context): string
    {
        if (! \is_string($value)) {
            return '';
        }

        return trim((string) Antlers::parse($value, $context));
    }

    /**
     * The absolute URL of the chosen confirmation entry, or '' when unresolved.
     */
    private function entryUrl(mixed $pageId): string
    {
        // Each `''` below means no entry URL; the redirect guard refuses any text that is not an internal URL, so a non-empty fallback is refused exactly as `''` is.
        if (! \is_scalar($pageId)) {
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            return '';
        }

        // The Stache id query matches a numeric id and its string form alike, so the cast only satisfies the declared type.
        // @pest-mutate-ignore: RemoveStringCast
        $entry = Entry::find((string) $pageId);
        if (! $entry instanceof EntryInstance) {
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            return '';
        }

        $url = $entry->url();

        // @pest-mutate-ignore: EmptyStringToNotEmpty
        return \is_string($url) ? $url : '';
    }

    /**
     * Append the optional, Antlers-rendered confirmation query string to a URL.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<array-key, mixed>  $context
     */
    private function withQueryString(string $url, array $settings, array $context): string
    {
        if ($url === '') {
            // No URL stays no URL; the redirect guard refuses any text that is not an internal URL, so a non-empty fallback is refused exactly as `''` is.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            return '';
        }

        $query = ltrim($this->render($settings['confirmation_query_string'] ?? '', $context), '?&');
        if ($query === '') {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').$query;
    }

    /**
     * Redirect to the confirmation URL: as JSON for an ajax submit, otherwise a real redirect.
     */
    private function redirectTo(Response $response, string $url): Response
    {
        if ($response instanceof JsonResponse) {
            /** @var array<string, mixed> $data */
            $data = $response->getData(true);
            $data['redirect'] = $url;
            $response->setData($data);

            return $response;
        }

        return redirect($url);
    }
}
