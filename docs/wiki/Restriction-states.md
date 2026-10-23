Written against v0.1.0.

The `form_restriction` tag reports whether a form is open, so a page can show a closed message instead of the form. A submission is refused on the same rules, in the order below, and the first one a form violates decides the state.

| Order | State | When | Message shown |
| --- | --- | --- | --- |
| 1 | `login_required` | **Require the User to Be Logged In** is on and the visitor is not logged in. | **Message to Display When the User is Not Logged In** |
| 2 | `schedule_unreadable` | **Schedule the Form** is on and the start or end date cannot be read as a date, which can happen with an imported form. The form is closed rather than left open. | The default text, "This form is not currently accepting submissions." |
| 3 | `pending` | **Schedule the Form** is on and the start date has not arrived. | **Message to Display When the Form is Pending** |
| 4 | `expired` | **Schedule the Form** is on and the end date has passed. | **Message to Display When the Form Has Expired** |
| 5 | `limit_reached` | **Limit the Number of Entries** is on and the form has as many entries as allowed for the chosen type of limit. | **Message to Display When the Entry Limit is Reached** |
| | `open` | None of the above. | An empty string |

## Variables

The tag takes a form `handle` and exposes `open` (true or false), `state` (one of the values above) and `message` (the configured text for that state, empty when open).

## Example

```antlers
{{ form_restriction handle="contact" }}
    {{ if open }}
        {{ form:contact }} ... {{ /form:contact }}
    {{ else }}
        <p class="form-closed">{{ message }}</p>
    {{ /if }}
{{ /form_restriction }}
```

With the `restrictions` switch off (see [Configuration](Configuration.md)), nothing is refused and the tag reports every form open.
