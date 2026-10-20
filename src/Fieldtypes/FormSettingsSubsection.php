<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Fieldtypes;

use Statamic\Fields\Fieldtype;

/**
 * A heading that separates the blocks of the form settings screen.
 *
 * Statamic 6 deprecated its Section fieldtype and removes it in Statamic 7, so
 * the add-on carries this one for its own screen rather than relying on a
 * fieldtype the host may not register. It is not offered in any blueprint's
 * fieldtype selector.
 */
class FormSettingsSubsection extends Fieldtype
{
    /**
     * The categories this fieldtype belongs to in the Statamic fieldtype selector.
     *
     * @var array<string> Categories this fieldtype belongs to in the Statamic fieldtype selector.
     */
    protected $categories = [
        'special',
    ];

    /**
     * Indicates whether the fieldtype is selectable in collection blueprints.
     *
     * @var bool Indicates whether the fieldtype is selectable in collection blueprints
     */
    protected $selectable = false;

    /**
     * Indicates whether the fieldtype is selectable in form blueprints.
     *
     * @var bool Indicates whether the fieldtype is selectable in form blueprints
     */
    protected $selectableInForms = false;

    /**
     * Indicates whether the fieldtype's value can be localized.
     *
     * @var bool Indicates whether the fieldtype's value can be localized
     */
    protected $localizable = false;

    /**
     * Indicates whether the fieldtype's value can be validated.
     *
     * @var bool Indicates whether the fieldtype's value can be validated
     */
    protected $validatable = false;

    /**
     * Indicates whether the fieldtype can have a default value.
     *
     * @var bool Indicates whether the fieldtype can have a default value
     */
    protected $defaultable = false;

    /**
     * The icon for this fieldtype.
     *
     * @return string The icon name
     */
    #[\Override]
    public function icon(): string
    {
        return 'fieldtype-section';
    }
}
