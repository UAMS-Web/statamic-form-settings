<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\CpForms\FieldDefinitions;
use UAMSWeb\FormSettings\CpForms\FormConfigurationBlueprintCustomizer;

uses(MockeryPHPUnitIntegration::class);

covers(FormConfigurationBlueprintCustomizer::class);

/**
 * Feature tests for FormConfigurationBlueprintCustomizer (UAMS email-grid and
 * tab customization of Statamic CP form-configuration blueprints).
 *
 * Mutation: 100% on UAMSWeb\FormSettings\CpForms\FormConfigurationBlueprintCustomizer (3 mutants, --covered-only).
 *
 * @command  vendor/bin/pest tests/Integration/CpForms/FormConfigurationBlueprintCustomizerTest.php
 */

/**
 * Build minimal blueprint contents in core's Statamic 6 shape: one tab holding
 * a list of sections, a name section carrying `title` and an email section
 * carrying a single-column email grid.
 *
 * @return array<string, mixed>
 */
function formConfigurationBlueprintCustomizerMinimalEmailTabContents(): array
{
    return [
        'tabs' => [
            'main' => [
                'sections' => [
                    [
                        'display' => 'Name',
                        'fields' => [
                            [
                                'handle' => 'title',
                                'field' => ['type' => 'text'],
                            ],
                        ],
                    ],
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
 * Find a top-level field's config in customized contents by its handle.
 *
 * @param  array<string, mixed>  $contents  Customized blueprint contents.
 * @param  string  $handle  Handle of the field to find.
 * @return array<string, mixed>|null The field config, or null when absent.
 */
function formConfigurationBlueprintCustomizerField(array $contents, string $handle): ?array
{
    /** @var list<array{fields: list<array{handle: string, field: array<string, mixed>}>}> $sections */
    $sections = data_get($contents, 'tabs.main.sections', []);

    foreach ($sections as $section) {
        foreach ($section['fields'] as $field) {
            if ($field['handle'] === $handle) {
                return $field['field'];
            }
        }
    }

    return null;
}

/**
 * Build a Form double whose handle() returns the given handle.
 *
 * @return Form&MockInterface
 */
function formConfigurationBlueprintCustomizerMockForm(string $handle): Form
{
    /** @var Form&MockInterface $form */
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('handle')
        ->andReturn($handle);

    return $form;
}

it('injects the name field as the first email grid column', function (): void {
    $form = formConfigurationBlueprintCustomizerMockForm('contact');

    $customizer = app(FormConfigurationBlueprintCustomizer::class);
    $out = $customizer->customizeBlueprintContents(
        formConfigurationBlueprintCustomizerMinimalEmailTabContents(),
        $form
    );

    $gridFields = data_get(formConfigurationBlueprintCustomizerField($out, 'email'), 'fields');

    expect(data_get($gridFields, '0.handle'))->toBe('name');
    expect(data_get($gridFields, '1.field.type'))->toBe('toggle');
    expect(data_get($gridFields, '1.handle'))->toBe('enabled');
});

it('keeps the customization stable across locales', function (): void {
    $form = formConfigurationBlueprintCustomizerMockForm('test-form');

    $customizer = app(FormConfigurationBlueprintCustomizer::class);

    foreach (['en', 'es', 'fr', 'de'] as $locale) {
        App::setLocale($locale);
        $out = $customizer->customizeBlueprintContents(
            formConfigurationBlueprintCustomizerMinimalEmailTabContents(),
            $form
        );

        // The injected name column must lead the grid regardless of locale.
        expect(data_get(formConfigurationBlueprintCustomizerField($out, 'email'), 'fields.0.handle'))->toBe('name');
    }
});

it('injects a Quiz Settings tab with a grading mode selector (UAMS-Web/uams-statamic#1146)', function (): void {
    $form = formConfigurationBlueprintCustomizerMockForm('quiz-form');

    $out = app(FormConfigurationBlueprintCustomizer::class)->customizeBlueprintContents(
        formConfigurationBlueprintCustomizerMinimalEmailTabContents(),
        $form
    );

    /** @var array{type: string, options: array<int, array<string, mixed>>, default: string} $grading */
    $grading = formConfigurationBlueprintCustomizerField($out, 'quiz_grading');

    expect($grading['type'])->toBe('radio')
        ->and(array_column($grading['options'], 'key'))->toBe(['none', 'passfail', 'letter'])
        ->and($grading['default'])->toBe('none');
});

it('gates the pass percentage on the pass/fail grading mode (UAMS-Web/uams-statamic#1146)', function (): void {
    $form = formConfigurationBlueprintCustomizerMockForm('quiz-form');

    $out = app(FormConfigurationBlueprintCustomizer::class)->customizeBlueprintContents(
        formConfigurationBlueprintCustomizerMinimalEmailTabContents(),
        $form
    );

    /** @var array<string, mixed> $passPercent */
    $passPercent = formConfigurationBlueprintCustomizerField($out, 'quiz_pass_percent');

    expect($passPercent['type'])->toBe('integer')
        ->and($passPercent['if'])->toBe(['quiz_grading' => 'equals passfail'])
        ->and($passPercent['validate'])->toContain('exclude_unless:quiz_grading,passfail', 'between:0,100');
});

it('exposes the letter-grade grid with text+value rows QuizScorer reads (UAMS-Web/uams-statamic#1146)', function (): void {
    $form = formConfigurationBlueprintCustomizerMockForm('quiz-form');

    $out = app(FormConfigurationBlueprintCustomizer::class)->customizeBlueprintContents(
        formConfigurationBlueprintCustomizerMinimalEmailTabContents(),
        $form
    );

    /** @var array<string, mixed> $grades */
    $grades = formConfigurationBlueprintCustomizerField($out, 'quiz_grades');

    // QuizScorer::grade() reads rows shaped ['text' => …, 'value' => …], so the
    // grid subfield handles must be exactly those (and exactly two, no extra
    // column the scorer would ignore), gated on the letter mode.
    expect($grades['type'])->toBe('grid')
        ->and($grades['if'])->toBe(['quiz_grading' => 'equals letter'])
        ->and(data_get($grades, 'fields'))->toHaveCount(2)
        ->and(data_get($grades, 'fields.0.handle'))->toBe('text')
        ->and(data_get($grades, 'fields.1.handle'))->toBe('value');
});

it('leaves out the Quiz Settings section when quiz scoring is off', function (): void {
    config(['statamic-form-settings.quiz_scoring' => false]);

    $out = app(FormConfigurationBlueprintCustomizer::class)->customizeBlueprintContents(
        formConfigurationBlueprintCustomizerMinimalEmailTabContents(),
        formConfigurationBlueprintCustomizerMockForm('quiz-form')
    );

    expect(json_encode($out))->not->toContain('quiz_grading')->not->toContain('quiz_pass_percent');
});

it('adds no host-specific Bard button or site URL to the article and URL fields by default', function (): void {
    $buttons = fn (): array => array_values(array_filter((array) FieldDefinitions::article()['buttons'], is_string(...)));

    expect($buttons())->not->toContain('bts_attributes')
        ->and(json_encode(FieldDefinitions::url()))->not->toContain('uams.edu');

    config(['statamic-form-settings.article_bard_extra_buttons' => ['bts_attributes']]);

    expect($buttons())->toContain('bts_attributes')
        ->and(array_search('bts_attributes', $buttons(), true))->toBe(array_search('h6', $buttons(), true) + 1);
});
