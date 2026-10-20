<!--
 * Standalone fieldtype to select one form email field. Not a fork of Condition.vue;
 * follows the same Combobox + blur-on-close pattern as field-conditions for parity.
 -->
<template>
	<div class="w-full">
		<Combobox
			ref="fieldSelect"
			:model-value="value"
			:options="emailFieldOptions"
			:placeholder="__('Select an email field')"
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
				<div class="truncate" v-text="option?.label ?? option?.value ?? value" />
			</template>
		</Combobox>
	</div>
</template>

<script>
// Kept in Options API: depends on FieldtypeMixin (this.update(), value/config/meta, injects).
import { FieldtypeMixin as Fieldtype } from '@statamic/cms';
import { Combobox } from '@statamic/cms/ui';

export default {
	components: { Combobox },
	mixins: [Fieldtype],

	computed: {
		emailFieldOptions() {
			const fields = this.meta.email_fields || [];
			return fields.map(field => ({
				value: field.value,
				label: field.label,
			}));
		},
	},

	methods: {
		fieldSelected(field) {
			this.update(field);
		},

		// Parity with field-conditions Condition.vue: read search from input ref or exposed searchQuery
		fieldSelectBlur() {
			const q = this.$refs.fieldSelect?.$refs?.search?.value ?? this.$refs.fieldSelect?.searchQuery?.value ?? '';
			const trimmed = typeof q === 'string' ? q.trim() : '';
			if (trimmed) this.fieldSelected(trimmed);
		},
	},
};
</script>
