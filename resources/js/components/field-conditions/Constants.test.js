import { describe, expect, it } from 'vitest';
import { ALIASES, KEYS, OPERATORS } from './Constants.js';

/**
 * Pins the local copy of Statamic's field-conditions constants (synced from
 * statamic/cms 6.35.0) that the form conditional-logic builder reads (UAMS-Web/uams-statamic-archive#3089).
 * A change here is a deliberate resync, not drift.
 */
describe('field-conditions Constants (synced with statamic/cms 6.35.0)', () => {
	it('lists the condition keys a field config can carry', () => {
		expect(KEYS).toEqual([
			'if', 'if_any', 'show_when', 'show_when_any',
			'unless', 'unless_any', 'hide_when', 'hide_when_any',
		]);
	});

	it('lists the operators the builder offers', () => {
		expect(OPERATORS).toEqual([
			'equals', 'not', 'contains', 'contains_any', 'includes_any',
			'===', '!==', '>', '>=', '<', '<=', 'custom',
		]);
	});

	it('maps each alias to the operator it stands for', () => {
		expect(ALIASES).toEqual({
			is: 'equals',
			'==': 'equals',
			isnt: 'not',
			'!=': 'not',
			includes: 'contains',
		});
	});
});
