<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use UAMSWeb\FormSettings\CpForms\EmailGridBlueprintCustomizer;

/**
 * Pins the recipient guidance the Control Panel shows on a form's Email tab
 * (UAMS-Web/uams-statamic#2818). Form configurations live in the private `resources/forms`
 * submodule, so the tab tells an editor to enter a real address, and no longer
 * tells them not to, which was UAMS-Web/uams-statamic#2745's rule.
 *
 * @command  vendor/bin/pest tests/Integration/Forms/EmailTabRecipientInstructionsTest.php
 */
/**
 * Run the email-grid customizer over a minimal Email section and return the grid's
 * instructions.
 *
 * @return string The email grid field's instructions after customizing
 */
function emailTabRecipientInstructions(): string
{
    // Mirror core's Statamic 6 shape: one tab holding a list of sections
    $contents = [
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
                                    'instructions' => 'Existing instructions.',
                                    'fields' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    app(EmailGridBlueprintCustomizer::class)->customizeEmailGrid(
        $contents,
        mustBe(Form::class, FormFacade::make('email_tab_instructions_probe'), 'form')
    );

    $instructions = data_get($contents, 'tabs.main.sections.0.fields.0.field.instructions');
    Assert::assertIsString($instructions);

    return $instructions;
}

it('tells an editor to enter the recipient address on the form', function (): void {
    $instructions = emailTabRecipientInstructions();

    expect($instructions)
        ->toStartWith('Existing instructions.')
        ->toContain('**Notification recipients:**')
        ->toContain('Enter the address (or addresses) the notification goes to in To / CC / BCC.')
        ->toContain('kept in a private repository')
        ->toContain('{{ config:forms.recipients.institutional_relations }}')
        ->toContain('docs/forms/notification-recipients.md')
        ->toContain('**Available Merge Tags:**');
});

it('no longer forbids an individual address in To, CC or BCC', function (): void {
    expect(emailTabRecipientInstructions())
        ->not->toContain("Do not put an individual's email address")
        ->not->toContain('editors do not edit `.env` from here');
});
