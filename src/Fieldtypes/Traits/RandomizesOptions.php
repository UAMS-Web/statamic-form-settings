<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Fieldtypes\Traits;

/*
* Trait to randomize the order of options for select-like fieldtypes.
*
* When included in a fieldtype, this trait provides a method to shuffle the
* options array if the 'randomize_order' config option is enabled. This allows
* for consistent, DRY randomization logic across Checkboxes, Radio, and Select
* fieldtypes.
*
* Note: The getOptions() override in this trait assumes that the consuming class
* extends a parent class (such as Statamic's Checkboxes, Radio, or Select fieldtypes)
* that already provides a getOptions() method. The trait's getOptions() will call
* parent::getOptions() to retrieve the base options, then randomize if configured.
*
* Only use this trait in custom fieldtypes that:
*   - Extend a Statamic fieldtype (or other parent) that implements getOptions().
*   - Do not require further customization of getOptions() beyond randomization.
*
* If you need additional custom logic in getOptions(), override it in your fieldtype
* and call $this->maybeRandomizeOptions() as needed.
*/
trait RandomizesOptions
{
    /**
     * Get the options for this fieldtype, randomizing if configured.
     *
     * This method overrides the parent getOptions() method, retrieves the options
     * via the PresetOptions trait's getOptionsWithPreset(), and randomizes them if
     * the config option is enabled.
     *
     * @return array<array-key, mixed> The (possibly randomized) options array
     */
    protected function getOptions(): array
    {
        // Retrieve the options with any configured presets applied.
        $options = $this->getOptionsWithPreset();

        // Randomize the options if the config option is enabled.
        return $this->maybeRandomizeOptions($options);
    }

    /**
     * Randomize the order of options if the config flag is enabled.
     *
     * If the 'randomize_order' config option is true, this method shuffles the
     * options array before returning it. Otherwise, it returns the options as-is.
     *
     * @param  array<array-key, mixed>  $options  The options array to potentially randomize
     * @return array<array-key, mixed> The (possibly randomized) options array
     */
    protected function maybeRandomizeOptions(
        array $options
    ): array {
        // ---
        // Only randomize if the config option is enabled.
        // ---

        // Check if randomize_order config option is not enabled
        if (! ($this->config('randomize_order') ?? false)) {
            // Return original options
            return $options;
        }

        // Shuffle the options array in place (this already reindexes to
        // sequential numeric keys).
        shuffle($options);

        return $options;
    }

    /**
     * Returns the config array for the 'randomize_order' field.
     *
     * This static method provides a reusable config field definition for
     * the 'Randomize Order of Options' toggle, allowing fieldtypes to DRYly
     * include this config option in their configFieldItems().
     *
     * @param  ?string  $display  Optional custom display text for the field
     * @param  ?string  $instructions  Optional custom instructions text for the field
     * @return array<string, array{display: string, instructions: string, type: string, default: bool}> The config array for the randomize_order toggle field
     */
    public static function randomizeOrderConfigField(
        ?string $display = null,
        ?string $instructions = null
    ): array {
        return [
            'randomize_order' => [
                'display' => $display ?? __('Randomize Order of Options'),
                'instructions' => $instructions ?? __('Randomize the order in which the options are displayed to the user.'),
                'type' => 'toggle',
                'default' => false,
            ],
        ];
    }
}
