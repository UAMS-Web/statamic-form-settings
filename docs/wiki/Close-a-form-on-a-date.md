Written against v0.1.0.

A form should accept submissions only between two dates, and the page should show a message outside that window instead of the form.

## Steps

1. Open the form's settings screen and go to **Restrictions**.
2. Switch on **Schedule the Form**, then set **Start Date and Time** and **End Date and Time** under **Form Schedule**.
3. Write **Message to Display When the Form is Pending** (shown before the start) and **Message to Display When the Form Has Expired** (shown after the end). Save the form.
4. In the form's template, wrap the form in the `form_restriction` tag:

```antlers
{{ form_restriction handle="contact" }}
    {{ if open }}
        {{ form:contact }} ... {{ /form:contact }}
    {{ else }}
        <p>{{ message }}</p>
    {{ /if }}
{{ /form_restriction }}
```

Before the start the tag reports `pending`, after the end `expired`, and inside the window `open`. A submission outside the window is refused whether or not the template uses the tag.

## Reference

- [Restriction states](Restriction-states.md): the values `open`, `state` and `message` take.
- [Configuration](Configuration.md): the `restrictions` switch that turns this behavior on and off.
