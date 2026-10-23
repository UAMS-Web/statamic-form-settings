Written against v0.1.0.

One entry per symptom, as you meet it: the behavior, its cause, and the fix.

- [The form_restriction tag says every form is open](#the-form_restriction-tag-says-every-form-is-open)
- [Notifications ignore the Active toggle and routing](#notifications-ignore-the-active-toggle-and-routing)
- [The form settings screen has no added sections](#the-form-settings-screen-has-no-added-sections)
- [Fixed](#fixed)

## The form_restriction tag says every form is open

A form has a schedule or an entry limit set, and the page still shows the form with `open` true.

**Cause:** the `restrictions` switch is off, so nothing is refused and the tag reports every form open. It is also what the tag reports when the `handle` names no form.

**Fix:** set `features.restrictions` to `true` (or `FORM_SETTINGS_RESTRICTIONS=true`), clear the configuration cache, and check that `handle` is the form's handle. See [Configuration](Configuration.md).

## Notifications ignore the Active toggle and routing

Recipient routing and conditions are saved, and notifications go out as if they were not set.

**Cause:** the add-on points `statamic.forms.send_email_job` at its own job only while that option still holds Statamic's default. A host that has set the option to a job of its own keeps it, and the add-on's routing does not run. The `notifications` switch being off has the same effect.

**Fix:** remove the host's `statamic.forms.send_email_job` override so the add-on can replace Statamic's default, and confirm `features.notifications` is `true`. The add-on's job is final, so a host job cannot extend it.

## The form settings screen has no added sections

The Confirmations and Restrictions sections do not appear on a form's settings screen.

**Cause:** the `control_panel` switch is off, which returns Statamic's own screen, or the add-on is not installed in the application that serves the Control Panel.

**Fix:** set `features.control_panel` to `true`, then check that `composer show uams-web/statamic-form-settings` lists the package and clear the configuration cache.

## Fixed

Problems fixed in a release stay here, with the release that fixed them, instead of being deleted. Nothing has been fixed since v0.1.0.
