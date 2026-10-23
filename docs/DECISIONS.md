# Decision log

The canonical record of **why** this repository is the way it is: the reasoning and the **rejected alternatives** behind decisions that have closed. It is the guard against silently relitigating a settled decision.

**Consult it before acting.** Before proposing a change, reopening a question, or picking an approach, check here first: the path under consideration may already be a decided-and-recorded one, or an explicitly rejected one. If it is, follow the record (or make the case to revisit it); do not quietly re-decide.

**When a seam closes, record the verdict in both places.** Capture the *what*, *why* and rejected alternatives **here** (the cross-ticket index) **and** in the GitHub issue's `## Decision` section (the per-ticket record), keeping the two in sync. This file does not replace the issue tracker, which stays the system of record for a single ticket's status.

See the [`design-decision-forks`](../.claude/rules/design-decision-forks.md) rule for how a fork is surfaced and driven to resolution before its verdict lands here.

## How to add an entry

Oldest first, one entry per closed decision, so **a new entry is appended to the end of the file**. Keep it terse; the issue carries the depth. Each entry is preceded by exactly one blank line, which `scripts/check-decision-log-order.mjs` enforces along with the date order. The file is marked `merge=union` in `.gitattributes`, so two branches that each append an entry merge without a conflict.

```markdown
## YYYY-MM-DD: <short decision title> (<#issue or area>)

**Decision.** <what was decided, in a sentence or two>

**Why.** <the reasoning that settled it>

**Rejected.** <the alternatives considered, and why each lost>

**Companion.** <link to the GitHub issue holding the full reasoning>
```

---

## 2026-10-14: Started from the add-on template (repository setup)

**Decision.** This repository started from `UAMS-Web/statamic-addon-template`. It carries no synced files (`.agents/`, `.claude/`, `.cursor/`) and no `LICENSE`, and `composer.json` has no `license` field.

**Why.** The shared rules and skills reach a repository by onboarding it to the sync in `UAMS-Web/uams-claude-skills`, not by copying a snapshot that goes stale. The license waits on the general decision from UAMS legal in `UAMS-Web/uams-statamic#3039`.

**Rejected.** Snapshotting the synced files into the template: every new add-on would begin with a stale copy that conflicts with its first sync pull request. Copying a license from an existing add-on: legal has not decided which license applies.

**Companion.** `UAMS-Web/uams-statamic#3039`.

## 2026-10-22: The ruleset requires one aggregate check, and the drift check is removed (UAMS-Web/uams-claude-skills#298)

**Decision.** `ci.yml` ends in a `CI` job that `needs:` every other job and fails unless each reports `success`. The organization ruleset for this repository requires `CI`, `Attribution footer` and `Closing keywords` and no per-job name. `check-required-checks.yml`, `scripts/check-required-checks.mjs` and its test are removed.

**Why.** The ruleset listed a name per job, so every rename or added job meant editing the ruleset, and the drift check existed only to report when the two lists disagreed. With one aggregate name the lists cannot disagree: a job that is missing from the `needs:` of `CI` is the only drift left.

**Rejected.** Keeping the drift check beside the aggregate: it would compare a list of three names with job names it no longer has to match. Gating `CI` on a remote-CI variable: this repository is public and runs every job on every pull request.

**Companion.** `UAMS-Web/uams-claude-skills#292`, `UAMS-Web/statamic-multisite-forms#198`.

## 2026-10-23: The add-on goes to Packagist on MIT, ahead of UAMS legal's decision (#7)

**Decision.** The operator decided on 2026-10-15 to publish `uams-web/statamic-form-settings` on Packagist, the same way as `UAMS-Web/statamic-alpine-csp-forms`, so that `UAMS-Web/uams-statamic` can require it by name. The add-on is MIT licensed (`LICENSE`, `"license": "MIT"`), matching `UAMS-Web/statamic-multisite-forms`, ahead of `UAMS-Web/uams-statamic#3039`. The Marketplace is not part of this decision.

**Why.** A public Packagist package needs a declared license, and `uams-statamic` requires the add-on by Composer name rather than by path. Matching the sibling add-on keeps one license across the add-ons that site requires, and the file can change if UAMS legal decides otherwise.

**Rejected.** Waiting for UAMS legal before declaring a license: the package cannot be listed without one, and the site cannot require it by name until it is listed. Requiring the add-on from a path repository: it ties the site to one machine's checkout.

**Companion.** `UAMS-Web/uams-statamic#3072`, `UAMS-Web/uams-statamic#3039`.

## 2026-10-23: Host-specific Control Panel assumptions become configuration (#15)

**Decision.** The confirmation page pickers read their collections from `confirmation_page_collections` (default `['pages']`). The Article Bard toolbar no longer includes `bts_attributes`; `article_bard_extra_buttons` adds it back. The URL placeholder is neutral (`https://example.com/`). The Quiz Settings section and the quiz and survey merge-tag help appear only when `quiz_scoring` is on, which defaults to off.

**Why.** The add-on is published for any Statamic site, and nothing in it scores a quiz, names a UAMS domain or ships the `bts_attributes` button.

**Rejected.** Detecting a scorer by class or container binding: the scorer lives in the host site today, so there is nothing stable to detect. Keeping the UAMS defaults on: the add-on would show every other site controls that do nothing.

**Companion.** `UAMS-Web/uams-statamic#3072` sets `FORM_SETTINGS_QUIZ_SCORING=true` and `article_bard_extra_buttons` to keep its current screen.

## 2026-10-23: An unreadable schedule date closes the form (#14)

**Decision.** A form with scheduling on and a start or end date that cannot be read is closed, with the state `schedule_unreadable` and the default closed message. The catch narrows to Carbon's `InvalidFormatException`. A blank date still means no limit.

**Why.** Treating an unreadable date as no limit left a form open past an end date nobody could see was broken.

**Rejected.** Staying open with a logged warning: the warning reaches only the log, and an unreadable end date still never closes the form.
