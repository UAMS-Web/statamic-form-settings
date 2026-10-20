<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Statamic\Fields\Field;
use Statamic\Fields\Fieldtype;
use UAMSWeb\FormSettings\Fieldtypes\Traits\FormBlueprintContext;

/**
 * Behavior of the {@see FormBlueprintContext} trait: deciding whether a fieldtype is
 * being configured inside a form blueprint, from the request and then from the field's
 * parent (UAMS-Web/uams-statamic#3089).
 *
 * @command  vendor/bin/pest tests/Integration/Fieldtypes/Traits/FormBlueprintContextTest.php
 */

/**
 * A parent whose blueprint has the given namespace.
 */
function formBlueprintContextParent(mixed $namespace): object
{
    $blueprint = new class($namespace)
    {
        public function __construct(private mixed $value) {}

        public function namespace(): mixed
        {
            return $this->value;
        }
    };

    return new class($blueprint)
    {
        public function __construct(private mixed $value) {}

        public function blueprint(): mixed
        {
            return $this->value;
        }
    };
}

/**
 * Whether the trait reports a form blueprint context for this request and field parent.
 *
 * @param  array<string, string>  $headers
 */
function formBlueprintContextDetected(string $path, array $headers = [], mixed $parent = null, bool $withField = true): bool
{
    $request = Request::create('/'.$path);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    app()->instance('request', $request);

    $fieldtype = new class extends Fieldtype
    {
        use FormBlueprintContext;

        public function detect(): bool
        {
            return $this->isFormBlueprintContext();
        }
    };

    if ($withField) {
        $field = new Field('probe', ['type' => 'text']);
        $field->setParent($parent);
        $fieldtype->setField($field);
    }

    return $fieldtype->detect();
}

it('reads the forms and collections sections of the Control Panel from the path alone', function (): void {
    $formsParent = formBlueprintContextParent('forms.contact');

    expect(formBlueprintContextDetected('cp/forms/contact/blueprint'))->toBeTrue()
        ->and(formBlueprintContextDetected('cp/collections/pages', parent: $formsParent))->toBeFalse();
});

it('reads the field editor from the page it was opened on', function (string $path): void {
    $formsParent = formBlueprintContextParent('forms.contact');

    expect(formBlueprintContextDetected($path, ['referer' => 'https://x.test/cp/forms/contact']))->toBeTrue()
        ->and(formBlueprintContextDetected($path, ['referer' => 'https://x.test/cp/collections/pages'], $formsParent))->toBeFalse()
        ->and(formBlueprintContextDetected($path, ['referer' => 'https://x.test/cp/globals/seo'], $formsParent))->toBeTrue()
        ->and(formBlueprintContextDetected($path, [], $formsParent))->toBeTrue()
        ->and(formBlueprintContextDetected($path, ['referer' => 'https://x.test/cp/globals/seo']))->toBeFalse();
})->with(['cp/fields/edit', 'cp/fields/update', 'cp/fields/edit/probe', 'cp/fields/update/probe']);

it('ignores a forms referer outside the field editor', function (): void {
    expect(formBlueprintContextDetected('cp/globals/seo', ['referer' => 'https://x.test/cp/forms/contact']))->toBeFalse();
});

it("falls back to the namespace of the field parent's blueprint", function (mixed $parent, bool $expected): void {
    expect(formBlueprintContextDetected('cp/globals/seo', parent: $parent))->toBe($expected);
})->with([
    'forms namespace' => [formBlueprintContextParent('forms.contact'), true],
    'collections namespace' => [formBlueprintContextParent('collections.pages'), false],
    'forms only inside the namespace' => [formBlueprintContextParent('x.forms.contact'), false],
    'namespace not a string' => [formBlueprintContextParent(['forms.contact']), false],
    'blueprint without a namespace' => [new class
    {
        public function blueprint(): stdClass
        {
            return new stdClass;
        }
    }, false],
    'no blueprint' => [new class
    {
        public function blueprint(): mixed
        {
            return null;
        }
    }, false],
    'parent without a blueprint' => [new stdClass, false],
    'parent not an object' => ['forms.contact', false],
    'no parent' => [null, false],
]);

it('is not a form context without a field', function (): void {
    expect(formBlueprintContextDetected('cp/globals/seo', withField: false))->toBeFalse();
});

it('is not a form context when reading the parent throws', function (): void {
    $parent = new class
    {
        public function blueprint(): never
        {
            throw new RuntimeException('no blueprint');
        }
    };

    expect(formBlueprintContextDetected('cp/globals/seo', parent: $parent))->toBeFalse();
});
