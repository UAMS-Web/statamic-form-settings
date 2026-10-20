<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Support;

use Statamic\Facades\Antlers;
use Statamic\Fieldtypes\Bard;

/**
 * Renders an email row's Bard `message` as the body of a form notification (UAMS-Web/uams-statamic#3149).
 *
 * The row's `message` is the editor-written notification body (UAMS-Web/uams-statamic#3094): text with merge tags such
 * as `{{ submission:<handle> }}` and `{{ all_fields }}`. This class turns the stored Bard document
 * into HTML, parses its merge tags against a context the caller supplies, and derives the plain
 * text part from the result.
 *
 * The caller is responsible for the context's safety: every submitted value in it must already
 * be escaped display HTML, since Antlers outputs variables verbatim.
 */
final class EmailMessageBody
{
    /**
     * Decide whether a stored message holds no text, so the send falls back to the field list.
     *
     * @param  mixed  $message  The row's stored `message`: a Bard document, a string, or nothing.
     */
    public static function isEmpty(mixed $message): bool
    {
        return trim(strip_tags(self::toHtml($message))) === '';
    }

    /**
     * Render a stored message to HTML and parse its merge tags against the context.
     *
     * The message is parsed as untrusted user data, so Antlers applies its user-content limits
     * to editor-written text while the merge tags, `{{ all_fields }}` included, still resolve.
     *
     * @param  mixed  $message  The row's stored `message`.
     * @param  array<array-key, mixed>  $context  The Antlers context, with every submitted value
     *                                            already escaped.
     * @return string The rendered HTML body.
     */
    public static function html(mixed $message, array $context): string
    {
        return (string) Antlers::parse(self::toHtml($message), $context);
    }

    /**
     * Derive the plain-text part from a rendered HTML body.
     *
     * Block ends and line breaks become newlines, a table row becomes one `Label: value` line,
     * and the remaining markup is dropped.
     *
     * @param  string  $html  The rendered HTML body.
     * @return string The body as plain text.
     */
    public static function text(string $html): string
    {
        // Join a table row's cells with a colon so each row reads as one line
        $text = self::replace('/<\/t[dh]>\s*<t[dh][^>]*>/i', ': ', $html);

        // End a line at every line break and at the close of every block element
        $text = self::replace(['/<br\s*\/?>/i', '/<\/(p|div|h[1-6]|li|tr|blockquote|table|thead|tbody|ul|ol)>/i'], "\n", $text);

        // Drop the markup and decode the entities the HTML part needed
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Trim each line, then collapse runs of blank lines to one
        $lines = array_map(trim(...), explode("\n", $text));
        $text = self::replace("/\n{3,}/", "\n\n", implode("\n", $lines));

        return trim($text);
    }

    /**
     * Convert a stored message to HTML with its merge tags intact.
     *
     * Bard stores tag text verbatim, but rendering the document to HTML encodes the quotes and
     * other characters inside a tag's parameters, so the entities inside each `{{ }}` span are
     * decoded back before Antlers sees them.
     *
     * @param  mixed  $message  The row's stored `message`.
     */
    private static function toHtml(mixed $message): string
    {
        // Render a Bard document through the Bard fieldtype; a string passes through unchanged
        $html = new Bard()->augment($message);

        if (! is_string($html)) {
            return '';
        }

        // Restore the characters Bard encoded inside each merge tag
        // preg_replace_callback() returns null only on a regex error, and this pattern is fixed
        // @pest-mutate-ignore: RemoveStringCast
        $html = (string) preg_replace_callback(
            '/\{\{.*?\}\}/s',
            static fn (array $match): string => html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $html
        );

        // Lift an `all_fields` tag out of a paragraph that holds nothing else, since the table
        // it renders cannot sit inside a paragraph
        return self::replace('/<p>\s*(\{\{\s*all_fields\b.*?\}\})\s*<\/p>/s', '$1', $html);
    }

    /**
     * Run preg_replace() with fixed patterns, returning a string.
     *
     * @param  string|list<string>  $pattern  The fixed pattern or patterns.
     * @param  string  $replacement  The replacement for every pattern.
     * @param  string  $subject  The text to replace in.
     */
    private static function replace(string|array $pattern, string $replacement, string $subject): string
    {
        // preg_replace() returns null only on a regex error, and every caller passes fixed,
        // valid patterns, so the cast never changes the result
        // @pest-mutate-ignore: RemoveStringCast
        return (string) preg_replace($pattern, $replacement, $subject);
    }
}
