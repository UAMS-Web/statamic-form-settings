<?php

declare(strict_types=1);

use UAMSWeb\FormSettings\Support\EmailMessageBody;

/**
 * Behavior of EmailMessageBody (src/Support/EmailMessageBody.php): an email row's Bard
 * `message` rendered as a notification body, and the plain-text part derived from it (UAMS-Web/uams-statamic#3149).
 *
 * @command  vendor/bin/pest tests/Integration/Forms/EmailMessageBodyTest.php
 */
/**
 * Build a Bard document of one paragraph per text.
 *
 * @param  string  ...$texts  The text of each paragraph.
 * @return list<array<string, mixed>>
 */
function emailMessageBodyBard(string ...$texts): array
{
    return array_values(array_map(
        static fn (string $text): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        $texts
    ));
}

it('treats a message with no text as empty', function (mixed $message): void {
    expect(EmailMessageBody::isEmpty($message))->toBeTrue();
})->with([
    'null' => [null],
    'empty string' => [''],
    'whitespace' => ["  \n "],
    'empty paragraph' => [[['type' => 'paragraph']]],
    'whitespace paragraph' => [emailMessageBodyBard('   ')],
    'markup only' => ['<p></p><br>'],
]);

it('treats a message with text as filled', function (mixed $message): void {
    expect(EmailMessageBody::isEmpty($message))->toBeFalse();
})->with([
    'string' => ['Hello'],
    'bard' => [emailMessageBodyBard('Hello')],
]);

it('renders a Bard message and parses its tags against the context', function (): void {
    $html = EmailMessageBody::html(emailMessageBodyBard('Hi {{ name }}.'), ['name' => 'Ada']);

    expect($html)->toBe('<p>Hi Ada.</p>');
});

it('decodes entities Bard encoded inside a tag', function (): void {
    $html = EmailMessageBody::html(emailMessageBodyBard('{{ name | replace("a", "o") }} & co'), ['name' => 'Ada']);

    expect($html)->toBe('<p>Ado &amp; co</p>');
});

it('lifts all_fields out of its own paragraph', function (): void {
    $html = EmailMessageBody::html(emailMessageBodyBard('Intro', '{{ all_fields }}'), ['all_fields' => '<table></table>']);

    expect($html)->toBe('<p>Intro</p><table></table>')
        ->not->toContain('<p><table');
});

it('leaves all_fields inside a paragraph that holds other text', function (): void {
    $html = EmailMessageBody::html(emailMessageBodyBard('See {{ all_fields }}'), ['all_fields' => 'T']);

    expect($html)->toBe('<p>See T</p>');
});

it('renders nothing for a missing message', function (): void {
    expect(EmailMessageBody::html(null, []))->toBe('');
});

it('derives text with table rows as label lines', function (): void {
    $html = '<p>Hello &amp; welcome</p><table><tr><th>Name</th>  <td class="v">Ada</td></tr>'
        .'<tr><td>Note</td><td>Line one<br>Line two</td></tr></table>';

    expect(EmailMessageBody::text($html))->toBe("Hello & welcome\nName: Ada\nNote: Line one\nLine two");
});

it('decodes quotes and trims indented lines', function (): void {
    expect(EmailMessageBody::text('<div>  &quot;Hi&quot; &#039;there&#039;  </div><h2> Title </h2>'))
        ->toBe("\"Hi\" 'there'\nTitle");
});

it('collapses runs of blank lines to one', function (): void {
    expect(EmailMessageBody::text('<p>One</p><p></p><p></p><br/><BR /><p>Two</p>'))
        ->toBe("One\n\nTwo");
});

it('keeps a single blank line between paragraphs that have one', function (): void {
    expect(EmailMessageBody::text("<p>One</p>\n<p>Two</p>"))->toBe("One\n\nTwo");
});

it('ends a line at each block element close', function (string $tag): void {
    expect(EmailMessageBody::text("<{$tag}>A</{$tag}>B"))->toBe("A\nB");
})->with(['p', 'div', 'h1', 'h6', 'li', 'blockquote', 'ul', 'ol']);
