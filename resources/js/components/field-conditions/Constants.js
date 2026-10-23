/**
 * Local copy of Statamic field-conditions Constants.
 *
 * Upstream source (6.x branch):
 * https://github.com/statamic/cms/blob/6.x/resources/js/components/field-conditions/Constants.js
 *
 * Last synced: 2026-10-06 from statamic/cms v6.35.0
 *
 * The Composer package ships prebuilt CP assets only; it does not include
 * `vendor/statamic/cms/resources/js/`, so Vite cannot import from vendor.
 * Update the sync date and version when upgrading Statamic if upstream changes.
 */

export const KEYS = [
	'if',
	'if_any',
	'show_when',
	'show_when_any',
	'unless',
	'unless_any',
	'hide_when',
	'hide_when_any',
];

export const OPERATORS = [
	'equals',
	'not',
	'contains',
	'contains_any',
	'includes_any',
	'===',
	'!==',
	'>',
	'>=',
	'<',
	'<=',
	'custom',
];

export const ALIASES = {
	is: 'equals',
	'==': 'equals',
	isnt: 'not',
	'!=': 'not',
	includes: 'contains',
};
