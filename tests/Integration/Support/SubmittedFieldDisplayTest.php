<?php

declare(strict_types=1);

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Statamic\Assets\Asset;
use Statamic\Fields\LabeledValue;
use Statamic\Fields\Value;
use UAMSWeb\FormSettings\Support\SubmittedFieldDisplay;

/**
 * Behavior of SubmittedFieldDisplay (src/Support/SubmittedFieldDisplay.php): one submitted
 * field rendered as the escaped HTML a notification email shows for it (UAMS-Web/uams-statamic-archive#3149).
 *
 * The form-level tests in tests/Integration/Forms/EmailMessageBodyTest.php reach these branches through a real send;
 * these cases pin each branch and the emptiness rule directly.
 *
 * @command  vendor/bin/pest tests/Integration/Support/SubmittedFieldDisplayTest.php
 */

/**
 * Build a mocked asset with the given URL and path.
 *
 * @param  string|null  $url  The asset's absolute URL, or null when its container has none.
 * @param  string  $path  The asset's path in its container.
 */
function submittedFieldDisplayAsset(?string $url, string $path): Asset
{
    $asset = Mockery::mock(Asset::class);
    $asset->allows('absoluteUrl')->andReturn($url);
    $asset->allows('path')->andReturn($path);
    $asset->allows('toArray')->andReturn(['path' => $path]);

    return $asset;
}

/**
 * A query-builder stand-in: an object with `get()` that is not ArrayAccess.
 *
 * @param  mixed  $results  What `get()` returns.
 */
function submittedFieldDisplayBuilder(mixed $results): object
{
    return new readonly class($results)
    {
        public function __construct(private mixed $results) {}

        public function get(): mixed
        {
            return $this->results;
        }
    };
}

it('treats each empty value as empty', function (mixed $value): void {
    expect(SubmittedFieldDisplay::isEmpty($value))->toBeTrue()
        ->and(SubmittedFieldDisplay::html(['fieldtype' => 'text', 'value' => $value]))->toBe('');
})->with([
    'null' => [null],
    'empty string' => [''],
    'empty array' => [[]],
    'false' => [false],
    'labelled value with no value' => [new LabeledValue(null, null)],
    'empty collection' => [new Collection],
    'builder with no results' => [submittedFieldDisplayBuilder(new Collection)],
    'builder with an empty array' => [submittedFieldDisplayBuilder([])],
]);

it('treats each filled value as filled', function (mixed $value): void {
    expect(SubmittedFieldDisplay::isEmpty($value))->toBeFalse();
})->with([
    'zero string' => ['0'],
    'zero' => [0],
    'true' => [true],
    'labelled value' => [new LabeledValue('red', 'Red')],
    'collection' => [new Collection(['a'])],
    'builder with results' => [submittedFieldDisplayBuilder(new Collection(['a']))],
]);

it('renders a zero string rather than dropping it', function (): void {
    expect(SubmittedFieldDisplay::html(['fieldtype' => 'text', 'value' => '0']))->toBe('0');
});

it('unwraps an augmented value before rendering it', function (): void {
    $field = ['fieldtype' => 'text', 'value' => new Value('Ada <b>', 'name')];

    expect(SubmittedFieldDisplay::html($field))->toBe('Ada &lt;b&gt;');
});

it('treats an augmented empty value as empty', function (): void {
    expect(SubmittedFieldDisplay::html(['fieldtype' => 'text', 'value' => new Value('', 'name')]))->toBe('');
});

it('escapes text, encodes braces and keeps line breaks', function (): void {
    $field = ['fieldtype' => 'textarea', 'value' => "{{ form:title }}\nLine \"two\""];

    expect(SubmittedFieldDisplay::html($field))
        ->toBe("&#123;&#123; form:title &#125;&#125;<br>\nLine &quot;two&quot;");
});

it('renders an integer as its digits', function (): void {
    expect(SubmittedFieldDisplay::html(['fieldtype' => 'integer', 'value' => 42]))->toBe('42');
});

it('reads the fieldtype from the type key when fieldtype is absent', function (): void {
    $field = ['type' => 'checkboxes', 'value' => [new LabeledValue('a', 'Option <A>'), 'b']];

    expect(SubmittedFieldDisplay::html($field))->toBe('Option &lt;A&gt;, b');
});

it('prefers the fieldtype key over the type key', function (): void {
    $field = ['fieldtype' => 'radio', 'type' => 'assets', 'value' => new LabeledValue('a', 'Option A')];

    expect(SubmittedFieldDisplay::html($field))->toBe('Option A');
});

it('renders a single select as its label', function (): void {
    $field = ['fieldtype' => 'select', 'config' => [], 'value' => new LabeledValue('red', 'Deep Red')];

    expect(SubmittedFieldDisplay::html($field))->toBe('Deep Red');
});

it('renders a single option with no label as its key', function (): void {
    $field = ['fieldtype' => 'radio', 'value' => new LabeledValue('red', null)];

    expect(SubmittedFieldDisplay::html($field))->toBe('red');
});

it('renders a multiple select as its labels', function (): void {
    $field = [
        'fieldtype' => 'select',
        'config' => ['multiple' => true],
        'value' => [new LabeledValue('red', 'Red & Co'), new LabeledValue('blue', 'Blue')],
    ];

    expect(SubmittedFieldDisplay::html($field))->toBe('Red &amp; Co, Blue');
});

it('renders checkboxes and a dictionary as their labels', function (string $fieldtype): void {
    $field = ['fieldtype' => $fieldtype, 'value' => [new LabeledValue('a', 'Alpha'), 'beta']];

    expect(SubmittedFieldDisplay::html($field))->toBe('Alpha, beta');
})->with(['checkboxes', 'dictionary']);

it('renders a single scalar under a labels fieldtype', function (): void {
    expect(SubmittedFieldDisplay::html(['fieldtype' => 'checkboxes', 'value' => 'only']))->toBe('only');
});

it('renders other arrays as escaped JSON', function (): void {
    $field = ['fieldtype' => 'grid', 'value' => [['name' => '<Ada>', 'n' => 1]]];

    expect(SubmittedFieldDisplay::html($field))
        ->toBe('[&#123;&quot;name&quot;:&quot;&lt;Ada&gt;&quot;,&quot;n&quot;:1&#125;]');
});

it('renders an arrayable iterable as JSON of its array', function (): void {
    $field = ['fieldtype' => 'grid', 'value' => new Collection(['a' => 1])];

    expect(SubmittedFieldDisplay::html($field))->toBe('&#123;&quot;a&quot;:1&#125;');
});

it('encodes braces inside JSON output', function (): void {
    $field = ['fieldtype' => 'grid', 'value' => new ArrayIterator(['x' => '{{ y }}'])];

    expect(SubmittedFieldDisplay::html($field))->not->toContain('{')
        ->and(SubmittedFieldDisplay::html($field))->toContain('&#123;');
});

it('renders a single asset as its URL', function (): void {
    $field = ['fieldtype' => 'assets', 'value' => submittedFieldDisplayAsset('https://x.test/a.pdf?b=1&c=2', 'a.pdf')];

    expect(SubmittedFieldDisplay::html($field))->toBe('https://x.test/a.pdf?b=1&amp;c=2');
});

it('renders an asset with no URL as its path', function (): void {
    $field = ['fieldtype' => 'assets', 'value' => submittedFieldDisplayAsset(null, 'uploads/a.pdf')];

    expect(SubmittedFieldDisplay::html($field))->toBe('uploads/a.pdf');
});

it('renders a list of assets as comma-separated URLs and skips anything else', function (): void {
    $field = ['fieldtype' => 'assets', 'value' => [
        submittedFieldDisplayAsset('https://x.test/a.pdf', 'a.pdf'),
        'not an asset',
        submittedFieldDisplayAsset('', 'b.pdf'),
    ]];

    expect(SubmittedFieldDisplay::html($field))->toBe('https://x.test/a.pdf, b.pdf');
});

it('resolves an assets query builder to its results', function (): void {
    $field = ['fieldtype' => 'assets', 'value' => submittedFieldDisplayBuilder(new Collection([
        submittedFieldDisplayAsset('https://x.test/a.pdf', 'a.pdf'),
        submittedFieldDisplayAsset('https://x.test/b.pdf', 'b.pdf'),
    ]))];

    expect(SubmittedFieldDisplay::html($field))->toBe('https://x.test/a.pdf, https://x.test/b.pdf');
});

it('renders nothing for an assets value that is neither an asset nor a list', function (): void {
    expect(SubmittedFieldDisplay::html(['fieldtype' => 'assets', 'value' => 'a.pdf']))->toBe('');
});

it('renders replicator rows as labelled blocks', function (): void {
    $field = [
        'fieldtype' => 'replicator',
        'value' => [['type' => 'guest']],
        'email_rows' => [
            ['set_label' => 'Guest <1>', 'pairs' => [
                ['label' => 'Name', 'text' => "Ada\nLovelace"],
                ['label' => 'Age', 'text' => 36],
            ]],
            ['set_label' => 'Guest 2', 'pairs' => [['label' => 'Name', 'text' => 'Grace']]],
        ],
    ];

    expect(SubmittedFieldDisplay::html($field))->toBe(
        '<b>Guest &lt;1&gt;</b><br>Name: Ada<br>'."\n".'Lovelace<br>Age: 36<br>'
        .'<b>Guest 2</b><br>Name: Grace<br>'
    );
});

it('skips malformed replicator rows and pairs and defaults missing labels', function (): void {
    $field = [
        'fieldtype' => 'replicator',
        'value' => [['type' => 'guest']],
        'email_rows' => [
            'not a row',
            ['pairs' => ['not a pair', ['text' => 'Ada'], ['label' => 'Age']]],
            ['set_label' => 'Empty', 'pairs' => 'not a list'],
        ],
    ];

    expect(SubmittedFieldDisplay::html($field))->toBe('<b></b><br>: Ada<br>Age: <br><b>Empty</b><br>');
});

it('renders nothing for a replicator with no email rows', function (): void {
    expect(SubmittedFieldDisplay::html(['fieldtype' => 'replicator', 'value' => [['type' => 'a']], 'email_rows' => 'x']))->toBe('');
});

it('renders a stringable value as its string', function (): void {
    $value = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'From <string>';
        }
    };

    expect(SubmittedFieldDisplay::html(['fieldtype' => 'text', 'value' => $value]))->toBe('From &lt;string&gt;');
});

it('encodes a collection of arrayable items as their arrays', function (): void {
    $item = new class implements Arrayable
    {
        /**
         * @return array<string, string>
         */
        public function toArray(): array
        {
            return ['name' => 'Ada'];
        }
    };

    $field = ['fieldtype' => 'grid', 'value' => new Collection([$item])];

    expect(SubmittedFieldDisplay::html($field))->toBe('[&#123;&quot;name&quot;:&quot;Ada&quot;&#125;]');
});

it('renders a non-scalar label as an empty string', function (): void {
    expect(SubmittedFieldDisplay::html(['fieldtype' => 'radio', 'value' => new stdClass]))->toBe('');
});
