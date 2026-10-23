<?php

declare(strict_types=1);

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\Support\ConditionalLogicEvaluator;
use UAMSWeb\FormSettings\Support\FormConfirmationResolver;

uses(MockeryPHPUnitIntegration::class);

covers(FormConfirmationResolver::class);

/**
 * Narrow mutate coverage for FormConfirmationResolver.
 *
 * Uses the real ConditionalLogicEvaluator (final) with simple if-conditions.
 *
 * @command  vendor/bin/pest tests/Integration/Support/FormConfirmationResolverMutationTest.php
 */

/**
 * @param  array<string, mixed>  $gets
 */
function formConfirmationForm(array $gets): Form
{
    $form = Mockery::mock(Form::class);
    $form->shouldReceive('get')->andReturnUsing(
        fn (string $key): mixed => $gets[$key] ?? null,
    );

    return $form;
}

it('returns null when the form has no confirmation config', function (): void {
    $resolver = new FormConfirmationResolver(new ConditionalLogicEvaluator);

    expect($resolver->resolve(formConfirmationForm([]), []))->toBeNull();
});

it('returns the default confirmation when no additional set matches', function (): void {
    $resolver = new FormConfirmationResolver(new ConditionalLogicEvaluator);
    $default = ['confirmation_type' => 'message', 'confirmation_message' => 'Thanks'];

    expect($resolver->resolve(
        formConfirmationForm([
            'confirmation_additional' => [
                [
                    'conditional_logic' => ['if' => ['topic' => 'equals billing']],
                    'confirmation_settings' => ['confirmation_type' => 'message', 'confirmation_message' => 'Billing'],
                ],
            ],
            'confirmation_settings_default' => $default,
        ]),
        ['topic' => 'general'],
    ))->toBe($default);
});

it('returns the first additional confirmation whose logic passes', function (): void {
    $resolver = new FormConfirmationResolver(new ConditionalLogicEvaluator);
    $billing = ['confirmation_type' => 'message', 'confirmation_message' => 'Billing'];
    $support = ['confirmation_type' => 'message', 'confirmation_message' => 'Support'];

    expect($resolver->resolve(
        formConfirmationForm([
            'confirmation_additional' => [
                'skip-me',
                [
                    'conditional_logic' => ['if' => ['topic' => 'equals billing']],
                    'confirmation_settings' => $billing,
                ],
                [
                    'conditional_logic' => ['if' => ['topic' => 'equals support']],
                    'confirmation_settings' => $support,
                ],
            ],
            'confirmation_settings_default' => ['confirmation_type' => 'message', 'confirmation_message' => 'Default'],
        ]),
        ['topic' => 'support'],
    ))->toBe($support);
});

it('skips a matching additional set whose settings are empty or not an array', function (): void {
    $resolver = new FormConfirmationResolver(new ConditionalLogicEvaluator);
    $default = ['confirmation_type' => 'message', 'confirmation_message' => 'Default'];

    expect($resolver->resolve(
        formConfirmationForm([
            'confirmation_additional' => [
                [
                    'conditional_logic' => null,
                    'confirmation_settings' => [],
                ],
                [
                    'conditional_logic' => null,
                    'confirmation_settings' => 'not-an-array',
                ],
            ],
            'confirmation_settings_default' => $default,
        ]),
        [],
    ))->toBe($default);
});

it('ignores a non-array confirmation_additional bag and an empty default', function (): void {
    $resolver = new FormConfirmationResolver(new ConditionalLogicEvaluator);

    expect($resolver->resolve(
        formConfirmationForm([
            'confirmation_additional' => 'not-a-list',
            'confirmation_settings_default' => [],
        ]),
        [],
    ))->toBeNull()
        ->and($resolver->resolve(
            formConfirmationForm([
                'confirmation_additional' => null,
                'confirmation_settings_default' => 'not-an-array',
            ]),
            [],
        ))->toBeNull();
});
