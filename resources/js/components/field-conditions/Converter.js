/**
 * Local copy of Statamic field-conditions Converter.
 *
 * Upstream source (6.x branch):
 * https://github.com/statamic/cms/blob/6.x/resources/js/components/field-conditions/Converter.js
 *
 * Last synced: 2026-10-06 from statamic/cms v6.35.0
 *
 * The Composer package ships prebuilt CP assets only; it does not include
 * `vendor/statamic/cms/resources/js/`, so Vite cannot import from vendor.
 * Update the sync date and version when upgrading Statamic if upstream changes.
 */

import { ALIASES, OPERATORS } from './Constants.js';

export default class {
	fromBlueprint(conditions, prefix = null) {
		return Object.entries(conditions).flatMap(([field, condition]) =>
			this.wrap(condition).map((condition) =>
				this.splitRhs(field, condition, prefix)
			)
		);
	}

	toBlueprint(conditions) {
		let converted = {};

		conditions.forEach((condition) => {
			const field = condition.field;
			const value = this.combineRhs(condition);

			if (field in converted) {
				converted[field] = this.wrap(converted[field]).concat(value);
			} else {
				converted[field] = value;
			}
		});

		return converted;
	}

	wrap(value) {
		return Array.isArray(value) ? value : [value];
	}

	splitRhs(field, condition, prefix = null) {
		return {
			field: this.getScopedFieldHandle(field, prefix),
			operator: this.getOperatorFromRhs(condition),
			value: this.getValueFromRhs(condition),
		};
	}

	getScopedFieldHandle(field, prefix) {
		if (field.startsWith('$root.') || field.startsWith('root.')) {
			return field;
		}

		if (field.startsWith('$parent.')) {
			return field;
		}

		return prefix ? prefix + field : field;
	}

	getOperatorFromRhs(condition) {
		let operator = '==';

		this.getOperatorsAndAliases()
			.filter((value) =>
				new RegExp(`^${value} [^=]`).test(
					this.normalizeConditionString(condition)
				)
			)
			.forEach((value) => (operator = value));

		return this.normalizeOperator(operator);
	}

	normalizeOperator(operator) {
		return ALIASES[operator] ? ALIASES[operator] : operator;
	}

	getValueFromRhs(condition) {
		let rhs = this.normalizeConditionString(condition);

		this.getOperatorsAndAliases()
			.filter((value) => new RegExp(`^${value} [^=]`).test(rhs))
			.forEach((value) =>
				(rhs = rhs.replace(new RegExp(`^${value}[ ]*`), ''))
			);

		return rhs;
	}

	combineRhs(condition) {
		let operator = condition.operator ? condition.operator.trim() : '';
		let value = condition.value.trim();

		return `${operator} ${value}`.trim();
	}

	getOperatorsAndAliases() {
		return OPERATORS.concat(Object.keys(ALIASES));
	}

	normalizeConditionString(value) {
		// You cannot `null.toString()`, so we'll manually cast it here to prevent error.
		if (value === null) {
			return 'null';
		}

		// Note: We don't document the use of an '' empty string in the yaml,
		// but for the people that manually add this to their yaml, we'll
		// treat it as an `empty` check so that it doesn't feel broken.
		if (value === '') {
			return 'empty';
		}

		return value.toString();
	}
}
