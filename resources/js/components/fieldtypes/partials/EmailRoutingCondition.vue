<!--
 * Condition-like row for email routing rules (field + operator + value + email).
 * Not a fork of Condition.vue; extends the same pattern with email column. Kept in
 * sync with field-conditions Condition.vue for UI and blur/ref handling.
 -->
<template>
	<div class="flex flex-wrap items-center border-t space-x-4 py-4 dark:border-dark-900">
		<Description v-if="index === 0" class="w-full mb-4" :text="__('messages.field_conditions_field_instructions')" />

		<div class="mb-2 w-full md:mb-0 md:w-1/3">
			<Combobox
				ref="fieldSelect"
				:model-value="condition.field"
				:options="fieldOptions"
				:placeholder="__('Field')"
				:taggable="true"
				:adaptive-width="true"
				@update:model-value="fieldSelected"
				@update:open="(open) => { if (!open) fieldSelectBlur(); }"
			>
				<template #no-options><div class="hidden" /></template>
				<template #option="option">
					<div class="flex items-center">
						<span v-text="option.label" />
						<span v-text="option.value" class="font-mono text-2xs text-gray-500 dark:text-dark-150" :class="{ 'ml-2': option.label }" />
					</div>
				</template>
				<template #selected-option="{ option }">
					<div class="truncate" v-text="__(field?.config.display) || option?.value" />
				</template>
			</Combobox>
		</div>

		<div class="w-auto min-w-32">
			<Select
				class="w-full"
				:model-value="condition.operator"
				:options="operatorOptions"
				@update:model-value="operatorSelected"
			/>
		</div>

		<Switch
			v-if="showValueToggle"
			:model-value="condition.value === 'true'"
			@update:model-value="(v) => valueUpdated(v ? 'true' : 'false')"
		/>

		<Combobox
			v-else-if="showValueDropdown"
			ref="valueSelect"
			:model-value="condition.value"
			class="mb-2 w-full md:mb-0 md:w-52"
			:options="valueOptions"
			:placeholder="__('Option')"
			:taggable="false"
			:adaptive-width="true"
			@update:model-value="valueUpdated"
			@update:open="(open) => { if (!open) valueSelectBlur(); }"
		>
			<template #no-options><div class="hidden" /></template>
		</Combobox>

		<Input
			v-else
			class="flex-1"
			:model-value="condition.value"
			@update:model-value="valueUpdated"
		/>

		<Button variant="ghost" size="sm" icon="trash" @click="remove" />

		<Input
			:model-value="condition.email"
			type="email"
			class="w-full mt-4"
			:placeholder="__('Email address(es)')"
			@update:model-value="emailUpdated"
		/>
	</div>
</template>

<script>
import { HasInputOptionsMixin } from '@statamic/cms';
import { Button, Combobox, Description, Input, Select, Switch } from '@statamic/cms/ui';

export default {
	components: { Button, Combobox, Description, Input, Select, Switch },
	mixins: [HasInputOptionsMixin],

	props: {
		config: {
			type: Object,
			required: true
		},
		condition: {
			type: Object,
			required: true
		},
		conditions: {
			type: Array,
			required: true
		},
		index: {
			type: Number,
			required: true
		},
		suggestableFields: {
			type: Array,
			required: true
		},
	},

	computed: {
		field() {
			return this.suggestableFields.find(field => field.handle === this.condition.field);
		},

		showValueToggle() {
			return this.field
				&& ['toggle', 'revealer'].includes(this.field.config.type)
				&& ['equals', 'not', '===', '!=='].includes(this.condition.operator);
		},

		showValueDropdown() {
			return this.field
				&& ['button_group', 'checkboxes', 'radio', 'select'].includes(this.field.config.type)
				&& ['equals', 'not', '===', '!=='].includes(this.condition.operator);
		},

		valueOptions() {
			if (! this.showValueDropdown) return;

			return this.normalizeInputOptions(this.field.config.options);
		},

		fieldOptions() {
			const used = this.conditions.map((c) => c.field);
			return this.suggestableFields
				.filter(
					(field) =>
						field.handle !== this.config.handle &&
						this.condition.field !== field.handle &&
						!used.includes(field.handle)
				)
				.map((field) => {
					let display = field.config.display;
					if (!display) {
						display = field.handle.replace(/_/g, ' ').replace(/(?:^|\s)\S/g, (a) => a.toUpperCase());
					}
					return { value: field.handle, label: display };
				});
		},

		operatorOptions() {
			return this.normalizeInputOptions({
				'equals': __('equals'),
				'not': __('not'),
				'contains': __('contains'),
				'contains_any': __('contains any'),
				'===': '===',
				'!==': '!==',
				'>': '>',
				'>=': '>=',
				'<': '<',
				'<=': '<=',
				'custom': __('custom'),
			});
		},
	},

	methods: {
		fieldSelected(field) {
			this.$emit('updated', {
				...this.condition,
				field: field,
			});
		},

		// Parity with field-conditions Condition.vue: input ref then exposed searchQuery
		fieldSelectBlur() {
			const q = this.$refs.fieldSelect?.$refs?.search?.value ?? this.$refs.fieldSelect?.searchQuery?.value ?? '';
			const trimmed = typeof q === 'string' ? q.trim() : '';
			if (trimmed) this.fieldSelected(trimmed);
		},

		operatorSelected(operator) {
			this.$emit('updated', {
				...this.condition,
				operator: operator,
			});
		},

		valueUpdated(value) {
			this.$emit('updated', {
				...this.condition,
				value: value.toString(),
			});
		},

		valueSelectBlur() {
			const q = this.$refs.valueSelect?.$refs?.search?.value ?? this.$refs.valueSelect?.searchQuery?.value ?? '';
			const trimmed = typeof q === 'string' ? q.trim() : '';
			if (trimmed) this.valueUpdated(trimmed);
		},

		emailUpdated(email) {
			this.$emit('updated', {
				...this.condition,
				email: email,
			});
		},

		remove() {
			this.$emit('removed');
		}
	}
}
</script>
