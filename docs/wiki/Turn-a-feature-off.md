Written against v0.1.0.

One of the add-on's four features should stop, without removing the add-on and without losing what editors already saved.

## Steps

1. Publish the config file if the application does not have it yet:

```bash
php artisan vendor:publish --tag=statamic-form-settings-config
```

2. In `config/statamic-form-settings.php`, set the feature's switch to `false`, or set its environment variable, for example `FORM_SETTINGS_RESTRICTIONS=false` in `.env`.
3. Clear the configuration cache if the application caches it: `php artisan config:clear`.

The other features keep working. Stored form settings are kept either way, so setting the switch back to `true` restores the feature as it was.

## Reference

- [Configuration](Configuration.md): each switch, its environment variable and what turning it off does.
