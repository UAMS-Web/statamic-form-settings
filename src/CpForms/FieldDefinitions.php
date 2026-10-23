<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\CpForms;

/**
 * The base configuration of the rich-text and URL fields the form settings
 * screen builds.
 *
 * The site these classes came from referenced these definitions from its own
 * `uamsweb_common` fieldset, which a host of this add-on need not have, so the
 * add-on carries its own copies. Each caller merges its own display text,
 * conditions and validation over the base, as a fieldset reference with a
 * `config` override would.
 */
final class FieldDefinitions
{
    /**
     * A Bard field for a confirmation message.
     *
     * @return array<string, mixed> The field configuration.
     */
    public static function article(): array
    {
        return [
            'type' => 'bard',
            'display' => __('Article'),
            'replicator_preview' => false,
            'buttons' => [
                'h2',
                'h3',
                'h4',
                'h5',
                'h6',
                'bts_attributes',
                'bold',
                'italic',
                'unorderedlist',
                'orderedlist',
                'anchor',
                'strikethrough',
                'superscript',
                'subscript',
                'horizontalrule',
                'code',
                'codeblock',
                'removeformat',
            ],
            'allow_source' => false,
            'reading_time' => true,
            'word_count' => true,
            'remove_empty_nodes' => true,
            'always_save' => false,
            'localizable' => false,
        ];
    }

    /**
     * A Bard field with bold and italic only.
     *
     * @return array<string, mixed> The field configuration.
     */
    public static function bardSimple(): array
    {
        return [
            'type' => 'bard',
            'display' => __('Simple Rich Text'),
            'replicator_preview' => false,
            'buttons' => [
                'bold',
                'italic',
                'removeformat',
            ],
            'allow_source' => false,
            'reading_time' => true,
            'word_count' => true,
            'remove_empty_nodes' => true,
            'always_save' => false,
            'localizable' => false,
        ];
    }

    /**
     * A Bard field for a notification email's message, with Antlers enabled.
     *
     * @return array<string, mixed> The field configuration.
     */
    public static function bardEmailMessage(): array
    {
        return [
            'type' => 'bard',
            'display' => __('Email Message'),
            'replicator_preview' => false,
            'buttons' => [
                'h1',
                'h2',
                'h3',
                'h4',
                'h5',
                'h6',
                'bold',
                'italic',
                'strikethrough',
                'superscript',
                'subscript',
                'unorderedlist',
                'orderedlist',
                'quote',
                'anchor',
                'alignleft',
                'aligncenter',
                'alignright',
                'removeformat',
            ],
            'allow_source' => false,
            'reading_time' => true,
            'word_count' => true,
            'remove_empty_nodes' => true,
            'always_save' => false,
            'localizable' => false,
            'antlers' => true,
            'target_blank' => true,
        ];
    }

    /**
     * A URL field.
     *
     * The site's URL fieldtype is its own, so this is core's text field with a
     * URL input. The site's fieldtype also added the `url` validation rule
     * itself, so a caller adds `url` to the `validate` list it sets.
     *
     * @return array<string, mixed> The field configuration.
     */
    public static function url(): array
    {
        return [
            'type' => 'text',
            'input_type' => 'url',
            'display' => __('Website URL'),
            'placeholder' => __('e.g., https://uams.edu/'),
            'localizable' => false,
        ];
    }
}
