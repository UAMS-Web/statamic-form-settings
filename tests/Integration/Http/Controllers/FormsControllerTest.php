<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Assert;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Fields;
use Statamic\Forms\Form;
use Statamic\Http\Controllers\CP\CpController;
use UAMSWeb\FormSettings\CpForms\FormConfigurationBlueprintCustomizer;
use UAMSWeb\FormSettings\CpForms\FormConfigurationValuesFilter;
use UAMSWeb\FormSettings\Http\Controllers\FormsController;

/**
 * FormsController form-configuration CP coverage (locale mismatch + save filter).
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/FormsControllerTest.php
 */
uses(MockeryPHPUnitIntegration::class);

covers(FormsController::class);

/**
 * Minimal Statamic parent blueprint contents for isolated form-config tests.
 *
 * @return array<string, mixed>
 */
function formsControllerTestMinimalParentBlueprintContents(): array
{
    // Mirror core's Statamic 6 shape: one tab holding a list of sections
    return [
        'tabs' => [
            'main' => [
                'sections' => [
                    [
                        'display' => 'Email',
                        'fields' => [
                            [
                                'handle' => 'email',
                                'field' => [
                                    'type' => 'grid',
                                    'fields' => [
                                        [
                                            'handle' => 'to',
                                            'field' => [
                                                'type' => 'text',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Build a FormsController that stubs Statamic's parent blueprint for isolated tests.
 *
 * @param  Request  $request  Incoming request passed to the base controller.
 * @param  Blueprint  $parentBlueprint  Stubbed Statamic parent blueprint.
 * @return FormsController Controller whose resolveStatamicFormConfigurationBlueprint() returns the stub.
 */
function formsControllerTestControllerWithStatamicBlueprintStub(
    Request $request,
    Blueprint $parentBlueprint
): FormsController {
    $customizer = app(FormConfigurationBlueprintCustomizer::class);
    $valuesFilter = app(FormConfigurationValuesFilter::class);

    return new class($request, $customizer, $valuesFilter, $parentBlueprint) extends FormsController
    {
        public function __construct(
            Request $request,
            FormConfigurationBlueprintCustomizer $blueprintCustomizer,
            FormConfigurationValuesFilter $valuesFilter,
            private Blueprint $statamicParentBlueprint,
        ) {
            parent::__construct($request, $blueprintCustomizer, $valuesFilter);
        }

        protected function resolveStatamicFormConfigurationBlueprint(
            Form $form
        ): Blueprint {
            return $this->statamicParentBlueprint;
        }
    };
}

it('stores the request on the Statamic CP parent constructor', function (): void {
    $request = Request::create('/cp/forms/test');
    $controller = new FormsController(
        $request,
        app(FormConfigurationBlueprintCustomizer::class),
        app(FormConfigurationValuesFilter::class),
    );

    $property = new ReflectionClass(CpController::class)
        ->getProperty('request');

    expect($property->getValue($controller))->toBe($request);
});

it('edit form blueprint with locale mismatch', function (): void {
    App::setLocale('en');

    $mockRequest = Mockery::mock(Request::class);

    $mockForm = Mockery::mock(Form::class);
    $mockForm->shouldReceive('handle')
        ->andReturn('test-form');

    $mockParentBlueprint = Mockery::mock(Blueprint::class);
    $mockParentBlueprint->shouldReceive('contents')
        ->andReturn(formsControllerTestMinimalParentBlueprintContents());

    $controller = formsControllerTestControllerWithStatamicBlueprintStub(
        $mockRequest,
        $mockParentBlueprint
    );

    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('editFormBlueprint');
    $result = $method->invoke($controller, $mockForm);

    expect($result)->toBeInstanceOf(Blueprint::class);

    /** @var Blueprint $result */
    /** @var array<string, mixed> $contents */
    $contents = $result->contents();

    // Read the email grid's columns from the section the fixture placed it in
    /** @var array<int, array<string, mixed>> $gridFields */
    $gridFields = data_get($contents, 'tabs.main.sections.0.fields.0.field.fields');
    expect($gridFields[0]['handle'])->toBe('name');
});

it('update with locale mismatch', function (): void {
    App::setLocale('en');

    $mockRequest = Mockery::mock(Request::class);
    $mockRequest->shouldReceive('all')
        ->andReturn([
            'title' => 'Test Form',
            'honeypot' => 'honeypot_field',
            'store' => true,
            'email' => [],
        ]);

    $mockForm = Mockery::mock(Form::class);
    $mockForm->shouldReceive('handle')
        ->andReturn('test-form');
    $mockForm->shouldReceive('title')
        ->once()
        ->with('Test Form')
        ->andReturnSelf();
    $mockForm->shouldReceive('honeypot')
        ->once()
        ->with('honeypot_field')
        ->andReturnSelf();
    $mockForm->shouldReceive('store')
        ->once()
        ->with(true)
        ->andReturnSelf();
    $mockForm->shouldReceive('email')
        ->once()
        ->with([])
        ->andReturnSelf();
    $mockForm->shouldReceive('merge')
        ->once()
        ->andReturnSelf();
    $mockForm->shouldReceive('save')
        ->once()
        ->andReturn(true);

    $mockBlueprint = Mockery::mock(Blueprint::class);
    $mockFields = Mockery::mock(Fields::class);
    $mockValues = Mockery::mock();
    $valuesArray = [
        'title' => 'Test Form',
        'honeypot' => 'honeypot_field',
        'store' => true,
        'email' => [],
    ];
    $mockValues->shouldReceive('all')
        ->andReturn($valuesArray);
    $mockProcessedFields = Mockery::mock();
    $mockProcessedFields->shouldReceive('values')
        ->andReturn($mockValues);
    $mockFields->shouldReceive('addValues')
        ->andReturnSelf();
    $mockFields->shouldReceive('validate')
        ->once()
        ->andReturn([]);
    $mockFields->shouldReceive('process')
        ->andReturn($mockProcessedFields);
    $mockFields->shouldReceive('get')
        ->andReturn(null);
    $mockBlueprint->shouldReceive('fields')
        ->andReturn($mockFields);

    $customizer = app(FormConfigurationBlueprintCustomizer::class);
    $valuesFilter = app(FormConfigurationValuesFilter::class);

    $controller = Mockery::mock(FormsController::class, [
        $mockRequest,
        $customizer,
        $valuesFilter,
    ])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $controller->shouldReceive('authorize')
        ->once()
        ->with('edit', $mockForm)
        ->andReturn(true);
    $controller->shouldReceive('editFormBlueprint')
        ->once()
        ->with($mockForm)
        ->andReturn($mockBlueprint);
    $controller->shouldReceive('success')
        ->once()
        ->with('Saved')
        ->andReturn(null);

    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('update');
    $method->invoke($controller, $mockForm, $mockRequest);
});

it('custom field injection with different locales', function (): void {
    $mockRequest = Mockery::mock(Request::class);

    $mockForm = Mockery::mock(Form::class);
    $mockForm->shouldReceive('handle')
        ->andReturn('test-form');

    $mockParentBlueprint = Mockery::mock(Blueprint::class);
    $mockParentBlueprint->shouldReceive('contents')
        ->andReturn(formsControllerTestMinimalParentBlueprintContents());

    foreach (['en', 'es', 'fr', 'de'] as $locale) {
        App::setLocale($locale);

        $controller = formsControllerTestControllerWithStatamicBlueprintStub(
            $mockRequest,
            $mockParentBlueprint
        );

        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('editFormBlueprint');
        $result = $method->invoke($controller, $mockForm);

        expect($result)->toBeInstanceOf(Blueprint::class, "Blueprint should be returned for locale: {$locale}");

        /** @var Blueprint $result */
        /** @var array<string, mixed> $contents */
        $contents = $result->contents();

        // Read the email grid's columns from the section the fixture placed it in
        /** @var array<int, array<string, mixed>> $gridFields */
        $gridFields = data_get($contents, 'tabs.main.sections.0.fields.0.field.fields');
        expect($gridFields[0]['handle'])->toBe('name', "name column for locale {$locale}");
    }
});

it('does not persist request keys absent from the form blueprint', function (): void {
    App::setLocale('en');

    // A real (un-mocked) blueprint so the controller's Fields::addValues -> process ->
    // values -> except -> merge pipeline runs for real and drops unknown request keys.
    $blueprint = BlueprintFacade::makeFromFields([
        'title' => ['type' => 'text'],
        'honeypot' => ['type' => 'text'],
        'store' => ['type' => 'toggle'],
        'email' => ['type' => 'text'],
        'custom_setting' => ['type' => 'text'],
    ]);

    $request = Mockery::mock(Request::class);
    $request->shouldReceive('all')
        ->andReturn([
            'title' => 'My Form',
            'honeypot' => 'hp',
            'store' => true,
            'email' => 'team@example.com',
            'custom_setting' => 'keep-me',
            // Not defined in the blueprint: an attacker-supplied extra key.
            'injected_evil' => 'HACKED',
        ]);

    // Capture exactly what reaches the persisted form definition via merge().
    $merged = null;

    $form = Mockery::mock(Form::class);
    $form->shouldReceive('title')->once()->with('My Form')->andReturnSelf();
    $form->shouldReceive('honeypot')->once()->with('hp')->andReturnSelf();
    $form->shouldReceive('store')->once()->with(true)->andReturnSelf();
    $form->shouldReceive('email')->once()->with('team@example.com')->andReturnSelf();
    $form->shouldReceive('merge')
        ->once()
        ->with(Mockery::on(function ($data) use (&$merged): bool {
            $merged = $data instanceof Collection ? $data->all() : $data;

            return true;
        }))
        ->andReturnSelf();
    $form->shouldReceive('save')
        ->once()
        ->andReturn(true);

    $customizer = app(FormConfigurationBlueprintCustomizer::class);
    $valuesFilter = app(FormConfigurationValuesFilter::class);

    $controller = Mockery::mock(FormsController::class, [
        $request,
        $customizer,
        $valuesFilter,
    ])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $controller->shouldReceive('authorize')
        ->once()
        ->with('edit', $form)
        ->andReturn(true);
    $controller->shouldReceive('editFormBlueprint')
        ->once()
        ->with($form)
        ->andReturn($blueprint);
    $controller->shouldReceive('success')
        ->once()
        ->with('Saved')
        ->andReturn(null);

    $method = new ReflectionClass($controller)->getMethod('update');
    $method->invoke($controller, $form, $request);

    // The unexpected key never reaches the form definition.
    expect($merged)->toBeArray()
        ->and($merged)->not->toHaveKey('injected_evil')
        // The four sensitive keys are persisted via dedicated setters, not merge().
        ->and($merged)->not->toHaveKey('title')
        ->and($merged)->not->toHaveKey('honeypot')
        ->and($merged)->not->toHaveKey('store')
        ->and($merged)->not->toHaveKey('email')
        // A legitimate blueprint-known setting still persists.
        ->and($merged)->toHaveKey('custom_setting', 'keep-me');
});

/**
 * Handle of the form that receives the appended validation-probe config field.
 *
 * Form::appendConfigFields() registers on a process-wide repository, so the field
 * is scoped to this handle and no other form's blueprint gains it.
 */
const FORMS_CONTROLLER_TEST_VALIDATED_HANDLE = 'forms_controller_test_validated';

/**
 * Save form settings through the controller with Statamic's real parent blueprint,
 * including a config field appended through Form::appendConfigFields().
 *
 * The UAMS customization is bypassed, so only Statamic's own fields and the appended
 * field are in the blueprint.
 *
 * @param  string  $limit  Value submitted for the appended `validated_limit` field.
 * @param  bool  $expectSave  Whether the form is expected to be written.
 * @return array<mixed>|null Values passed to the form's merge(), or null when none.
 */
function formsControllerTestSaveWithAppendedRule(string $limit, bool $expectSave): ?array
{
    // Append a config field whose validate rule only accepts an integer from 1 to 10
    Statamic\Facades\Form::appendConfigFields(FORMS_CONTROLLER_TEST_VALIDATED_HANDLE, 'Validation Probe', [
        'validated_limit' => [
            'type' => 'text',
            'validate' => ['required', 'integer', 'between:1,10'],
        ],
    ]);

    $request = Mockery::mock(Request::class);
    $request->shouldReceive('all')->andReturn([
        'title' => 'Validated Form',
        'honeypot' => null,
        'store' => true,
        'email' => [],
        'validated_limit' => $limit,
    ]);

    // Capture what reaches the persisted form definition, and refuse any write when none is expected
    $merged = null;
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('handle')->andReturn(FORMS_CONTROLLER_TEST_VALIDATED_HANDLE);
    if ($expectSave) {
        $form->shouldReceive('title', 'honeypot', 'store', 'email')->andReturnSelf();
        $form->shouldReceive('merge')
            ->once()
            ->with(Mockery::on(function ($data) use (&$merged): bool {
                $merged = $data instanceof Collection ? $data->all() : $data;

                return true;
            }))
            ->andReturnSelf();
        $form->shouldReceive('save')->once()->andReturn(true);
    } else {
        $form->shouldNotReceive('title', 'honeypot', 'store', 'email', 'merge', 'save');
    }

    // Serve Statamic's own blueprint, with the appended field, in place of the UAMS customization
    $controller = new class($request, app(FormConfigurationBlueprintCustomizer::class), app(FormConfigurationValuesFilter::class)) extends FormsController
    {
        public int $successes = 0;

        protected function editFormBlueprint($form): Blueprint
        {
            return $this->resolveStatamicFormConfigurationBlueprint($form);
        }

        /**
         * Allow the edit without a signed-in Control Panel user.
         *
         * @param  mixed  $ability  Ability being checked.
         * @param  mixed  $args  Arguments for the ability.
         * @param  string|null  $message  Message for a refusal, unused here.
         * @return Response Always an allowed response.
         */
        public function authorize($ability, $args = [], $message = null): Response
        {
            return Response::allow();
        }

        /**
         * Count the success flash instead of writing it to the session.
         *
         * @param  string  $message  Flash message.
         * @param  string|null  $text  Optional detail text.
         */
        protected function success($message, $text = null): void
        {
            $this->successes++;
        }
    };

    $controller->update($form, $request);

    // The success flash only follows a write
    expect($controller->successes)->toBe($expectSave ? 1 : 0);

    if ($merged === null) {
        return null;
    }

    // Narrow the captured merge payload to the array it must be
    Assert::assertIsArray($merged);

    return $merged;
}

it('refuses a value failing an appended config field rule and writes nothing', function (string $limit): void {
    App::setLocale('en');

    expect(fn (): ?array => formsControllerTestSaveWithAppendedRule($limit, false))
        ->toThrow(ValidationException::class);

    try {
        formsControllerTestSaveWithAppendedRule($limit, false);
    } catch (ValidationException $validationException) {
        // The error names the appended field, not some other rule in the blueprint
        expect(array_keys($validationException->errors()))->toBe(['validated_limit']);
    }
})->with([
    'not an integer' => ['many'],
    'out of range' => ['42'],
]);

it('saves a value passing an appended config field rule', function (): void {
    App::setLocale('en');

    $merged = formsControllerTestSaveWithAppendedRule('7', true);

    expect($merged)->toBeArray()->toHaveKey('validated_limit', '7');
});

it("serves this controller wherever the container builds Statamic's forms controller", function (): void {
    // FormEmailFieldsServiceProvider binds the replacement; without it the CP routes resolve core's controller.
    // The binding runs at boot, before PCOV records coverage, so Pest reports its one mutant uncovered;
    // removing the bind by hand turns this test red (UAMS-Web/uams-statamic-archive#3089).
    $binding = mustBeArray(app()->getBindings()[Statamic\Http\Controllers\CP\Forms\FormsController::class] ?? [], 'binding')['concrete'] ?? null;

    $binding = mustBe(Closure::class, $binding, 'binding');

    $reflection = new ReflectionFunction($binding);

    expect($reflection->getStaticVariables()['concrete'] ?? null)->toBe(FormsController::class);
});
