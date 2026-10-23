<!--
 * FormConditionalLogicFieldtype: fork of Statamic 6 Builder
 * (vendor/statamic/cms/resources/js/components/field-conditions/Builder.vue).
 *
 * Adapted for form conditional logic (confirmations). Aligned with v6 Builder:
 * Field, Select, Button, Switch, Always Save block, and value shape including
 * always_save.
 *
 * Customizations:
 *   - Used as a fieldtype: state is the field value; we load from props.value
 *     and persist via instance.update() instead of emitting updated /
 *     updated-always-save. Conditions instructions come from field config.
 *   - Wording and context are form-confirmation specific (e.g. "Trigger when"
 *     vs "Show when"). We use Composition API (setup) and app-bundle imports.
 *   - __ is provided globally in the CP; source imports it from
 *     '../../bootstrap/globals'.
 -->
<template>
	<div class="w-full">
		<Field
			:label="__('Conditions')"
			:instructions="conditionsInstructions"
		>
			<div class="mb-6 flex items-center gap-x-4">
				<Select
					v-model="when"
					:options="whenOptions"
					class="w-auto min-w-40"
				/>
				<Select
					v-if="hasConditions"
					v-model="type"
					:options="typeOptions"
					class="w-auto min-w-80"
				/>
				<Input
					v-if="hasConditions && isCustom"
					v-model="customMethod"
					class="flex-1"
				/>
			</div>
		</Field>

		<div class="mb-6">
			<condition
				v-if="hasConditions && isStandard"
				v-for="(condition, index) in conditions"
				:key="condition._id"
				:index="index"
				:config="config"
				:condition="condition"
				:conditions="conditions"
				:suggestable-fields="suggestableFields"
				@updated="updated(index, $event)"
				@removed="remove(index)"
			/>

			<div
				v-if="hasConditions && isStandard"
				class="border-t pt-6 dark:border-dark-900"
			>
				<Button
					:text="__('Add Condition')"
					@click="add"
				/>
			</div>
		</div>

		<Field
			:label="__('Always Save')"
			:instructions="__('messages.field_conditions_always_save_instructions')"
		>
			<Switch v-model="alwaysSave" />
		</Field>
	</div>
</template>

<script>
import { nanoid as uniqid } from 'nanoid';
import { computed, getCurrentInstance, onMounted, ref, watch } from 'vue'; // Fork: Composition API; source uses Options API (no Vue imports)
// Kept in Options API: depends on FieldtypeMixin (this.update(), value/config/meta, injects).
import { FieldtypeMixin as Fieldtype, HasInputOptionsMixin } from '@statamic/cms'; // Fork: Fieldtype + HasInputOptions from app bundle; source uses only HasInputOptions from '../fieldtypes/HasInputOptions.js'
import { Button, Field, Input, Select, Switch } from '@statamic/cms/ui'; // Fork: resolve from app bundle; source uses '@/components/ui' and '@ui'
import Condition from '../field-conditions/Condition.vue'; // Fork: app copy; source uses './Condition.vue' from field-conditions package
import Converter from '../field-conditions/Converter.js'; // Fork: app copy; Statamic 6.23+ no longer ships CP source in vendor
import { KEYS } from '../field-conditions/Constants.js'; // Fork: app copy; Statamic 6.23+ no longer ships CP source in vendor

export default {
	// Fork: we are a fieldtype; source has only HasInputOptions and props config + suggestableFields
	mixins: [HasInputOptionsMixin, Fieldtype],

	components: {
		Field,
		Input,
		Button,
		Select,
		Switch,
		Condition
	},

	// Fork: Composition API (setup); source uses data(), computed, watch, created, methods
	setup(props) {
		const instance = getCurrentInstance().proxy;

		const when = ref('always');
		const type = ref('all');
		const customMethod = ref(null);
		const conditions = ref([]);
		const alwaysSave = ref(false);

		// Fork: form-confirmation wording; source uses "Always show" / "Show when" / "Hide when"
		const whenOptions = computed(() =>
			instance.normalizeInputOptions({
				always: __('Always trigger'),
				if: __('Trigger when'),
				unless: __('Do not trigger when')
			})
		);

		const typeOptions = computed(() =>
			instance.normalizeInputOptions({
				all: __('All of the following conditions pass'),
				any: __('Any of the following conditions pass'),
				custom: __('Custom method passes')
			})
		);

		const hasConditions = computed(() => when.value !== 'always');

		const isCustom = computed(() => type.value === 'custom');

		const isStandard = computed(() => hasConditions.value && !isCustom.value);

		// Fork: from field meta (preload); source receives suggestableFields as a required prop
		const suggestableFields = computed(
			() => props.meta?.suggestable_fields || []
		);

		// Fork: instructions from field config; source uses __('messages.field_conditions_instructions') in template
		const conditionsInstructions = computed(() => {
			const config = props.meta?.config ?? props.config ?? {};
			return config.instructions ?? __('Configure when this confirmation should be triggered based on form field values.');
		});

		function prepareEditableOperator(operator) {
			switch (operator) {
				case 'is':
				case '==':
					return '';
				case 'isnt':
				case '!=':
					return 'not';
			}
			return operator;
		}

		function prepareEditableConditions(conditionsArray) {
			return new Converter()
				.fromBlueprint(conditionsArray)
				.map((condition) => {
					condition._id = uniqid();
					condition.operator = prepareEditableOperator(
						condition.operator
					);
					return condition;
				});
		}

		// Fork: same logic as source; we use .filter with explicit keep condition
		function prepareSaveableConditions(conditionsArray) {
			const filtered = conditionsArray.filter(
				(condition) => condition.field && condition.value
			);
			return new Converter().toBlueprint(filtered);
		}

		const saveableConditions = computed(() => {
			const result = {};
			const key =
				type.value === 'any' ? `${when.value}_any` : when.value;
			const saveable = prepareSaveableConditions(conditions.value);

			if (
				isStandard.value &&
				Object.keys(saveable).length > 0
			) {
				result[key] = saveable;
			} else if (isCustom.value && customMethod.value) {
				result[key] = customMethod.value;
			}

			return result;
		});

		// Fork: single stored value (conditions + always_save); source emits 'updated' and 'updated-always-save' separately
		const fullValue = computed(() => ({
			...saveableConditions.value,
			always_save: alwaysSave.value
		}));

		watch(
			fullValue,
			(newVal) => {
				instance.update(newVal);
			},
			{ deep: true }
		);

		// Fork: initial state from field value; source reads from this.config (blueprint field config)
		function getInitialConditions() {
			const value = props.value;
			if (!value || typeof value !== 'object') {
				return;
			}

			let key = null;
			let conditionsData = null;

			for (const k of KEYS) {
				if (value[k]) {
					key = k;
					conditionsData = value[k];
					break;
				}
			}

			if (!key || !conditionsData) {
				return;
			}

			when.value =
				key.startsWith('unless') || key.startsWith('hide_when')
					? 'unless'
					: 'if';

			type.value = key.endsWith('_any') ? 'any' : 'all';

			if (typeof conditionsData === 'string') {
				type.value = 'custom';
				customMethod.value = conditionsData;
				return;
			}

			if (Array.isArray(conditionsData)) {
				conditions.value =
					prepareEditableConditions(conditionsData);
			} else if (
				typeof conditionsData === 'object' &&
				conditionsData !== null
			) {
				const converted = new Converter().fromBlueprint(
					conditionsData
				);
				conditions.value = converted.map((condition) => {
					condition._id = uniqid();
					condition.operator = prepareEditableOperator(
						condition.operator
					);
					return condition;
				});
			}
		}

		// Fork: read always_save from field value; source uses data_get(this.config, 'always_save', false)
		function getInitialAlwaysSaveState() {
			const value = props.value;
			alwaysSave.value = (value && typeof value === 'object' && value.always_save === true) || false;
		}

		function initializeComponent() {
			try {
				getInitialConditions();
				getInitialAlwaysSaveState();
				if (conditions.value.length === 0) {
					add();
				}
			} catch (error) {
				console.error('Error in initializeComponent:', error);
				when.value = 'always';
				type.value = 'all';
				conditions.value = [];
				alwaysSave.value = false;
				add();
			}
		}

		function add() {
			conditions.value.push({
				_id: uniqid(),
				field: null,
				operator: 'equals',
				value: null
			});
		}

		function remove(index) {
			conditions.value.splice(index, 1);
		}

		function updated(index, condition) {
			conditions.value.splice(index, 1, condition);
		}

		// Fork: no created() hook; we run init in setup and use onMounted for add-if-empty
		try {
			if (!props.value) {
				// Rely on mixin/parent for default; still run init
			}
			if (!props.config) {
				// Rely on mixin/parent for default
			}
			initializeComponent();
		} catch (error) {
			console.error(
				'Error initializing FormConditionalLogic component:',
				error
			);
			when.value = 'always';
			type.value = 'all';
			conditions.value = [];
			alwaysSave.value = false;
		}

		onMounted(() => {
			if (conditions.value.length === 0) {
				add();
			}
		});

		return {
			when,
			type,
			customMethod,
			conditions,
			alwaysSave,
			whenOptions,
			typeOptions,
			hasConditions,
			isStandard,
			isCustom,
			conditionsInstructions,
			suggestableFields,
			add,
			remove,
			updated
		};
	}
};
</script>
