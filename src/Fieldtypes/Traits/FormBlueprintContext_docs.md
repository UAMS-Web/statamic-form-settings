# FormBlueprintContext Trait

## Overview

The `FormBlueprintContext` trait provides functionality to detect whether a fieldtype is being configured within a form blueprint context. This allows fieldtypes to conditionally show or hide configuration options based on the blueprint type.

## Purpose

This trait is designed to help fieldtypes that need different configuration options depending on whether they're being used in:
- **Form blueprints** (e.g., for email notifications, file attachments)
- **Collection blueprints** (e.g., for content management)
- **Other blueprint types** (e.g., taxonomies, globals)

## Usage

### Including the Trait

```php
use UAMSWeb\FormSettings\Fieldtypes\Traits\FormBlueprintContext;

class MyCustomFieldtype extends BaseFieldtype
{
    use FormBlueprintContext;
    
    // Your fieldtype implementation
}
```

### Using the Method

```php
protected function configFieldItems(): array
{
    $parentConfig = parent::configFieldItems();
    
    $fieldsToAdd = [];
    if ($this->isFormBlueprintContext()) {
        // Add form-specific configuration fields
        $fieldsToAdd = [
            'Appearance & Behavior' => [
                'form_specific_option' => [
                    'display' => 'Form-Specific Option',
                    'type' => 'toggle',
                    'default' => false,
                ],
            ],
        ];
    }
    
    return $this->addFieldsToExistingSections($fieldsToAdd, $parentConfig);
}
```

## Method Reference

### `isFormBlueprintContext(): bool`

Determines if the fieldtype is being configured within a form blueprint context.

**Returns**: `true` if in a form blueprint context, `false` otherwise.

**Detection Logic**:
1. **Request Path Detection**: Checks if the current request path starts with `cp/forms/`
2. **Referer Detection**: For field editing contexts (`cp/fields/edit` or `cp/fields/update`), checks the referer header to determine the originating context
3. **Blueprint Namespace Fallback**: Falls back to checking the field's parent blueprint namespace (must start with `forms.`)

**Error Handling**: Returns `false` if any error occurs during detection, ensuring the field doesn't appear in unexpected contexts.

## Examples

### Form Blueprint Context
- Request path: `cp/forms/contact_form/blueprint`
- Referer: `http://example.com/cp/forms/contact_form/blueprint`
- Blueprint namespace: `forms.contact_form`
- **Result**: `true`

### Collection Blueprint Context
- Request path: `cp/collections/pages/blueprints/basic_page/edit`
- Referer: `http://example.com/cp/collections/pages/blueprints/basic_page/edit`
- Blueprint namespace: `collections.pages`
- **Result**: `false`

## Benefits

- **Reusable**: Can be used across multiple fieldtypes that need form-specific configuration
- **Robust**: Multiple detection methods ensure reliable context identification
- **Safe**: Graceful error handling prevents unexpected behavior
- **Maintainable**: Centralized logic for form blueprint detection

## Dependencies

- Laravel Framework (for `request()` helper)
- Statamic CMS (for blueprint and field objects)

## Notes

- This trait is specifically designed for Statamic fieldtypes
- The detection logic prioritizes request path detection for performance
- The trait assumes the fieldtype has access to a `$this->field` property
- Error handling defaults to `false` to prevent configuration fields from appearing in unexpected contexts
