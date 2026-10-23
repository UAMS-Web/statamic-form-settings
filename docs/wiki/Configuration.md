Written against v0.1.0.

The add-on has one switch per feature, all in `features` of `config/statamic-form-settings.php`. Each defaults to `true`, so installing the add-on changes nothing until a form is configured. Publish the file with `php artisan vendor:publish --tag=statamic-form-settings-config`.

| Key | Environment variable | Default | Effect when `false` |
| --- | --- | --- | --- |
| `features.control_panel` | `FORM_SETTINGS_CONTROL_PANEL` | `true` | Statamic's own form settings screen returns: the added sections, the email grid's routing, conditions and sender name, and saving them go away. |
| `features.notifications` | `FORM_SETTINGS_NOTIFICATIONS` | `true` | Notifications send the way Statamic's own job sends them: no Active toggle, conditional notifications, recipient routing, sender name or email body view. |
| `features.confirmations` | `FORM_SETTINGS_CONFIRMATIONS` | `true` | Statamic's own response after a submission returns. |
| `features.restrictions` | `FORM_SETTINGS_RESTRICTIONS` | `true` | No submission is refused, and the `form_restriction` tag reports every form open. |

## Example

```php
// config/statamic-form-settings.php
return [
    'features' => [
        'control_panel' => true,
        'notifications' => true,
        'confirmations' => true,
        'restrictions' => false,
    ],
];
```

With `notifications` on, the add-on points `statamic.forms.send_email_job` at its own job. A host that has set that option to a job of its own keeps it.
