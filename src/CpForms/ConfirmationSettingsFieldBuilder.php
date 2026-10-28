<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\CpForms;

/**
 * Builds the reusable confirmation-settings field-configuration array.
 *
 * The identical field set is required in two places inside
 * FormConfigurationTabsInjector: the default confirmation group and each
 * additional-confirmation replicator set. This class centralizes that definition
 * so both call sites stay in sync.
 *
 * Both places nest the fields in a `group`, and a conditional rule's companion field must be
 * named by its full path in the submitted data (UAMS-Web/uams-statamic-archive#3162). Statamic substitutes `{this}.` with the
 * validation prefix the group's children receive, which is the group's own parent prefix: empty
 * for the default group, and `confirmation_additional.<index>.` inside the replicator. The group
 * handle is not part of it, so each rule names the group after the token, and
 * `{this}.<group>.confirmation_type` resolves to the real path at both call sites.
 */
final class ConfirmationSettingsFieldBuilder
{
    /**
     * Return the confirmation settings fields configuration.
     *
     * @param  string  $group  Handle of the `group` field the returned fields are nested in, used
     *                         to name each conditional rule's companion field by its full path.
     * @return array<string, mixed> Keyed field-configuration array ready for injection into a group or replicator
     *                              set
     */
    public function build(string $group): array
    {
        // Name the companion fields by their path through the group, from wherever it sits
        $type = '{this}.'.$group.'.confirmation_type';
        $delay = '{this}.'.$group.'.confirmation_redirect_delay_query';

        return [
            'confirmation_type' => [
                'handle' => 'confirmation_type',
                'field' => [
                    'type' => 'radio',
                    'display' => __('Confirmation Type'),
                    'options' => [
                        [
                            'key' => 'message',
                            'value' => __('Show Message'),
                        ],
                        [
                            'key' => 'redirect',
                            'value' => __('Send to External URL'),
                        ],
                        [
                            'key' => 'entry',
                            'value' => __('Send to Page Entry'),
                        ],
                        [
                            'key' => 'replace',
                            'value' => __('Replace Form With Page Entry Content'),
                        ],
                    ],
                    'default' => 'message',
                    'validate' => [
                        'required',
                    ],
                ],
            ],
            'confirmation_message' => [
                'handle' => 'confirmation_message',
                'field' => [
                    ...FieldDefinitions::article(),
                    'display' => __('Confirmation Message'),
                    'if' => [
                        'confirmation_type' => 'equals message',
                    ],
                    'validate' => [
                        'exclude_unless:'.$type.',message',
                        'required_if:'.$type.',message',
                    ],
                ],
            ],
            'confirmation_redirect_url' => [
                'handle' => 'confirmation_redirect_url',
                'field' => [
                    ...FieldDefinitions::url(),
                    'display' => __('External Confirmation URL'),
                    'validate' => [
                        'exclude_unless:'.$type.',redirect',
                        'required_if:'.$type.',redirect',
                        'url',
                    ],
                    'if' => [
                        'confirmation_type' => 'equals redirect',
                    ],
                ],
            ],
            'confirmation_page' => [
                'handle' => 'confirmation_page',
                'field' => [
                    'type' => 'entries',
                    'display' => __('Confirmation Page Entry'),
                    'max_items' => 1,
                    'collections' => self::pageCollections(),
                    'if' => [
                        'confirmation_type' => 'equals entry',
                    ],
                    'validate' => [
                        'exclude_unless:'.$type.',entry',
                        'required_if:'.$type.',entry',
                    ],
                ],
            ],
            'confirmation_query_string' => [
                'handle' => 'confirmation_query_string',
                'field' => [
                    'type' => 'text',
                    'display' => 'Query String',
                    'instructions' => 'Add a query string to the destination URL.',
                    'placeholder' => 'e.g., foo={{ submission:bar }}&baz={{ submission:qux }}',
                    'prepend' => '?',
                    'if' => [
                        'confirmation_type' => 'contains_any redirect,entry',
                    ],
                    'validate' => [
                        'exclude_unless:'.$type.',redirect,entry',
                    ],
                ],
            ],
            'confirmation_redirect_target' => [
                'handle' => 'confirmation_redirect_target',
                'field' => [
                    'type' => 'toggle',
                    'display' => __('Open in New Tab'),
                    'if' => [
                        'confirmation_type' => 'contains_any redirect,entry',
                    ],
                ],
            ],
            'confirmation_redirect_delay_query' => [
                'handle' => 'confirmation_redirect_delay_query',
                'field' => [
                    'type' => 'toggle',
                    'display' => __('Delay Redirect and Show Message'),
                    'if' => [
                        'confirmation_type' => 'contains_any redirect,entry',
                    ],
                ],
            ],
            'confirmation_redirect_delay_time' => [
                'handle' => 'confirmation_redirect_delay_time',
                'field' => [
                    'type' => 'integer',
                    'display' => __('Confirmation Redirect Delay Time'),
                    'append' => 'seconds',
                    'default' => 8,
                    'if' => [
                        'confirmation_type' => 'contains_any redirect,entry',
                        'confirmation_redirect_delay_query' => 'equals true',
                    ],
                    'validate' => [
                        'exclude_unless:'.$delay.',true',
                        'required_if:'.$delay.',true',
                    ],
                ],
            ],
            'confirmation_redirect_message' => [
                'handle' => 'confirmation_redirect_message',
                'field' => [
                    ...FieldDefinitions::bardSimple(),
                    'display' => __('Confirmation Redirect Message'),
                    'placeholder' => __('Please wait while you are redirected.'),
                    'default' => __('Please wait while you are redirected.'),
                    'if' => [
                        'confirmation_type' => 'contains_any redirect,entry',
                        'confirmation_redirect_delay_query' => 'equals true',
                    ],
                    'validate' => [
                        'exclude_unless:'.$delay.',true',
                        'required_if:'.$delay.',true',
                    ],
                ],
            ],
            'confirmation_replace_entry' => [
                'handle' => 'confirmation_replace_entry',
                'field' => [
                    'type' => 'entries',
                    'display' => __('Confirmation Page Content'),
                    'instructions' => __('The selected page content will be displayed, but the full page will not be loaded.')."\n\n".__('Traditional URL tracking in Google Analytics and similar tools will not register a page load event. If precise tracking is essential, consider using the "Redirect to URL" option.'),
                    'max_items' => 1,
                    'collections' => self::pageCollections(),
                    'if' => [
                        'confirmation_type' => 'equals replace',
                    ],
                    'validate' => [
                        'exclude_unless:'.$type.',replace',
                        'required_if:'.$type.',replace',
                    ],
                ],
            ],
        ];
    }

    /**
     * The collections the confirmation page pickers offer.
     *
     * @return list<string> Collection handles, `pages` unless configured.
     */
    private static function pageCollections(): array
    {
        // The fallback below returns `pages` for an empty list, so this default restates it and
        // removing it changes no result.
        // @pest-mutate-ignore: RemoveArrayItem
        $collections = config('statamic-form-settings.confirmation_page_collections', ['pages']);
        $handles = is_array($collections) ? array_values(array_filter($collections, is_string(...))) : [];

        return $handles === [] ? ['pages'] : $handles;
    }
}
