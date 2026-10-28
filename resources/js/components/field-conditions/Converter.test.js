import { describe, expect, it } from 'vitest';
import Converter from './Converter.js';

const converter = new Converter();

describe('field-conditions Converter (synced with statamic/cms 6.35.0)', () => {
	it('reads includes_any as its own operator, not an alias of contains_any', () => {
		expect(converter.fromBlueprint({ tag: 'includes_any vip,staff' })).toEqual([
			{ field: 'tag', operator: 'includes_any', value: 'vip,staff' },
		]);
		expect(converter.fromBlueprint({ tag: 'contains_any vip,staff' })).toEqual([
			{ field: 'tag', operator: 'contains_any', value: 'vip,staff' },
		]);
	});

	it('still folds the remaining aliases into their operator', () => {
		expect(converter.fromBlueprint({ tags: 'includes vip' })).toEqual([
			{ field: 'tags', operator: 'contains', value: 'vip' },
		]);
	});

	it('reads a list of conditions on one field as one condition each', () => {
		expect(converter.fromBlueprint({ age: ['>= 18', '< 65'], status: 'active' })).toEqual([
			{ field: 'age', operator: '>=', value: '18' },
			{ field: 'age', operator: '<', value: '65' },
			{ field: 'status', operator: 'equals', value: 'active' },
		]);
	});

	it('saves more than one condition on a field as a list, and one as a string', () => {
		const saved = converter.toBlueprint([
			{ field: 'age', operator: '>=', value: '18' },
			{ field: 'age', operator: '<', value: '65' },
			{ field: 'status', operator: '', value: 'active' },
		]);

		expect(saved).toEqual({ age: ['>= 18', '< 65'], status: 'active' });
		expect(converter.toBlueprint(converter.fromBlueprint(saved))).toEqual({
			age: ['>= 18', '< 65'],
			status: 'equals active',
		});
	});
});

// ---
// Method-level coverage (UAMS-Web/uams-statamic-archive#3089). Every method is exercised on its own, so emptying
// any one of them fails at least one case below before the builder moves to the
// form settings add-on.
// ---
describe('field-conditions Converter methods', () => {
	it('wraps a single value in a list and leaves a list alone', () => {
		expect(converter.wrap('equals a')).toEqual(['equals a']);
		expect(converter.wrap(['equals a', 'not b'])).toEqual(['equals a', 'not b']);
	});

	it('prefixes a field handle but not a root or parent reference', () => {
		expect(converter.getScopedFieldHandle('name', 'contact.')).toBe('contact.name');
		expect(converter.getScopedFieldHandle('name', null)).toBe('name');
		expect(converter.getScopedFieldHandle('$root.name', 'contact.')).toBe('$root.name');
		expect(converter.getScopedFieldHandle('root.name', 'contact.')).toBe('root.name');
		expect(converter.getScopedFieldHandle('$parent.name', 'contact.')).toBe('$parent.name');
	});

	it('applies the prefix when reading a blueprint', () => {
		expect(converter.fromBlueprint({ name: 'not empty' }, 'contact.')).toEqual([
			{ field: 'contact.name', operator: 'not', value: 'empty' },
		]);
	});

	it('splits one condition into its field, operator and value', () => {
		expect(converter.splitRhs('age', '> 18', 'person.')).toEqual({
			field: 'person.age',
			operator: '>',
			value: '18',
		});
	});

	it('reads each operator, and equals when there is none', () => {
		expect(converter.getOperatorFromRhs('not staff')).toBe('not');
		expect(converter.getOperatorFromRhs('!== staff')).toBe('!==');
		expect(converter.getOperatorFromRhs('<= 5')).toBe('<=');
		expect(converter.getOperatorFromRhs('custom isAdult')).toBe('custom');
		expect(converter.getOperatorFromRhs('staff')).toBe('equals');
	});

	it('folds an alias into the operator it stands for and leaves an operator alone', () => {
		expect(converter.normalizeOperator('isnt')).toBe('not');
		expect(converter.normalizeOperator('!=')).toBe('not');
		expect(converter.normalizeOperator('is')).toBe('equals');
		expect(converter.normalizeOperator('contains_any')).toBe('contains_any');
	});

	it('reads the value after the operator and its spaces', () => {
		expect(converter.getValueFromRhs('not   staff member')).toBe('staff member');
		expect(converter.getValueFromRhs('staff')).toBe('staff');
	});

	it('combines an operator and a value, trimming both', () => {
		expect(converter.combineRhs({ operator: ' >= ', value: ' 18 ' })).toBe('>= 18');
		expect(converter.combineRhs({ operator: '', value: 'active' })).toBe('active');
		expect(converter.combineRhs({ value: 'active' })).toBe('active');
	});

	it('lists every operator followed by every alias', () => {
		expect(converter.getOperatorsAndAliases()).toEqual([
			'equals', 'not', 'contains', 'contains_any', 'includes_any',
			'===', '!==', '>', '>=', '<', '<=', 'custom',
			'is', '==', 'isnt', '!=', 'includes',
		]);
	});

	it('reads null as the word null and an empty string as empty', () => {
		expect(converter.normalizeConditionString(null)).toBe('null');
		expect(converter.normalizeConditionString('')).toBe('empty');
		expect(converter.normalizeConditionString(5)).toBe('5');
		expect(converter.fromBlueprint({ note: null, extra: '' })).toEqual([
			{ field: 'note', operator: 'equals', value: 'null' },
			{ field: 'extra', operator: 'equals', value: 'empty' },
		]);
	});

	it('keeps adding to a field that already holds a list', () => {
		expect(converter.toBlueprint([
			{ field: 'age', operator: '>', value: '1' },
			{ field: 'age', operator: '<', value: '9' },
			{ field: 'age', operator: 'not', value: '5' },
		])).toEqual({ age: ['> 1', '< 9', 'not 5'] });
	});
});
