# Form Settings

A Statamic add-on that extends the Control Panel's form settings screen and what a form does after a submission: notification emails with recipient routing and conditions, a confirmation message or redirect, restrictions on who can submit and when, and quiz and survey scoring.

## Requirements

PHP 8.4 or later and Statamic 6.35 or later.

## Installation

```bash
composer require uams-web/statamic-form-settings
```

Statamic discovers the add-on through Composer. To change its settings, publish the config file:

```bash
php artisan vendor:publish --tag=statamic-form-settings-config
```

## Features

Each feature has its own switch in `config/statamic-form-settings.php`, and all are on by default. Turning one off disables that feature alone; the others keep working. Stored form settings are kept either way, so turning a feature back on restores it as it was.

| Switch | Environment variable | What it covers |
| --- | --- | --- |
| `control_panel` | `FORM_SETTINGS_CONTROL_PANEL` | The added sections on the form settings screen, the email grid's routing, conditions and sender name, and saving them. |
| `notifications` | `FORM_SETTINGS_NOTIFICATIONS` | The notification job: the Active toggle, conditional notifications, recipient routing, the sender name and the email body view. |
| `confirmations` | `FORM_SETTINGS_CONFIRMATIONS` | The message or redirect a visitor sees after submitting. |
| `restrictions` | `FORM_SETTINGS_RESTRICTIONS` | Entry limits, the form schedule and required login, on submit and in the `form_restriction` tag. |

With `notifications` on, the add-on points `statamic.forms.send_email_job` at its own job. A host that has set that option to a job of its own keeps it.

Two more keys adapt the form settings screen to a host: `confirmation_page_collections` (default `['pages']`) and `article_bard_extra_buttons` (default none). See the wiki's Configuration page.

`quiz_scoring` (`FORM_SETTINGS_QUIZ_SCORING`, default on) switches quiz and survey scoring: the `quiz` and `survey` tags, the Quiz Settings section and the quiz merge-tag help in the notification email instructions. Off, the tags render nothing and the section and help are absent. The `quiz` and `likert_scale` fieldtypes and their public form views stay registered either way, so a stored form that uses them keeps rendering.

While scoring is on, the add-on adds `quiz`, `quiz:*`, `survey` and `survey:*` to `statamic.antlers.allowedContentTags`, after the host's own entries, so the tags resolve in a confirmation message or a notification email, which Antlers parses as user content. A host that wants to manage that list itself sets `quiz_tags_in_user_content` (`FORM_SETTINGS_QUIZ_TAGS_IN_USER_CONTENT`, default on) to false.

## Templates

The `form_restriction` tag reports a form's restriction state, so a page can show a closed message instead of the form:

```antlers
{{ form_restriction handle="contact" }}
    {{ if open }}
        {{ form:contact }} ... {{ /form:contact }}
    {{ else }}
        <p>{{ message }}</p>
    {{ /if }}
{{ /form_restriction }}
```

It exposes `open`, `state` (`open`, `login_required`, `pending`, `expired`, `schedule_unreadable` or `limit_reached`) and `message`.

The `quiz` and `survey` tags show a scored submission in a confirmation message or a notification email. `{{ quiz }}` lists each question with its answers, the correct answer and the explanation; `{{ quiz:score }}`, `{{ quiz:percent }}`, `{{ quiz:grade }}` and `{{ quiz:passfail }}` give the result, by the grading set in the form's Quiz Settings. `{{ survey }}` lists each Likert question with the answer given, and `{{ survey:score }}` the total. `{{ quiz }}`, `{{ quiz:score }}`, `{{ survey }}` and `{{ survey:score }}` take `handle="..."` for a single question.

## Extending

- **Submission context.** A notification sent from a queue worker cannot see the submitter's request. A host that keeps that context binds its own implementation of `UAMSWeb\FormSettings\Contracts\SubmissionContext`: the job calls it to capture the context when the form is submitted, and again to make that context active while the email is sent. The add-on's default captures nothing.
- **More settings sections.** Another package adds a section to the form settings screen by tagging an implementation of `UAMSWeb\FormSettings\Contracts\FormSettingsSection` with `FormSettingsSection::TAG` in its service provider's `register()`.

## License

MIT. See [`LICENSE`](LICENSE).

## Development

Developing against a host application is covered in [the development guide](docs/DEVELOPMENT.md), and the Packagist and Marketplace steps in [the submission checklist](docs/SUBMISSION.md). The gate is `composer ci`.
