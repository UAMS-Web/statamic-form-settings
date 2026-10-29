<!--
 * LikertScale fieldtype (UAMS-Web/uams-statamic-archive#194). Renders a single-row Likert scale, or a matrix
 * (one rateable row per configured row label) when `enable_multiple_rows` is set.
 * Not a fork of a core component: Statamic ships no Likert fieldtype; it borrowed
 * the `radio` component before, which cannot represent a matrix. Uses RadioGroup /
 * Radio from @statamic/cms/ui, like the app's RadioFieldtype fork.
 *
 * All display logic (scale type, point count, order, reverse mapping, the
 * zero-point option and its label) is resolved server-side in
 * UAMSWeb\FormSettings\Fieldtypes\LikertScale::preload(); this component only renders `meta.options`,
 * through the helpers in likert-scale.js.
 *
 * Value shapes:
 *   - single row: a scale point (number) or the string 'not_applicable'
 *   - matrix: an object keyed by row label → scale point / 'not_applicable'
 -->
<template>
	<div class="likert-scale-fieldtype">
		<!-- Matrix mode: one rateable row per configured label -->
		<div v-if="isMatrix">
			<div
				v-for="(row, rowIndex) in rows"
				:key="rowIndex"
				class="likert-scale-row"
			>
				<label class="likert-scale-row-label">{{ row }}</label>
				<RadioGroup
					:inline="inline"
					:model-value="rowValue(row)"
					@update:model-value="(val) => updateRow(row, val)"
				>
					<Radio
						v-for="(option, index) in options"
						:key="index"
						:label="option.label"
						:read-only="isReadOnly"
						:value="option.value"
					/>
				</RadioGroup>
			</div>
		</div>

		<!-- Single-row mode -->
		<RadioGroup
			v-else
			:inline="inline"
			:model-value="value"
			@update:model-value="update"
			ref="radio"
		>
			<Radio
				v-for="(option, index) in options"
				:key="index"
				:label="option.label"
				:read-only="isReadOnly"
				:value="option.value"
			/>
		</RadioGroup>
	</div>
</template>

<script>
// Kept in Options API: depends on FieldtypeMixin (this.update(), value/meta).
import { FieldtypeMixin as Fieldtype } from '@statamic/cms';
import { RadioGroup, Radio } from '@statamic/cms/ui';
import {
	isLikertMatrix,
	likertInline,
	likertOptions,
	likertRows,
	likertRowValue,
	likertWithRow,
} from './likert-scale.js';

export default {
	name: 'LikertScaleFieldtype',

	mixins: [Fieldtype],

	components: { RadioGroup, Radio },

	computed: {
		// The ordered {value, label} options resolved by preload().
		options() {
			return likertOptions(this.meta);
		},

		// Horizontal layout flag.
		inline() {
			return likertInline(this.meta);
		},

		// Whether to render the matrix (rows configured + enabled).
		isMatrix() {
			return isLikertMatrix(this.meta);
		},

		// The matrix row labels.
		rows() {
			return likertRows(this.meta);
		},
	},

	methods: {
		// The selected value for a matrix row (value is a row-label → point map).
		rowValue(row) {
			return likertRowValue(this.value, row);
		},

		// Update one matrix row, preserving the other rows' selections.
		updateRow(row, val) {
			this.update(likertWithRow(this.value, row, val));
		},

		focus() {
			this.$refs.radio?.focus?.();
		},
	},
};
</script>

<style scoped>
.likert-scale-row {
	margin-bottom: 0.75rem;
}

.likert-scale-row-label {
	display: block;
	font-weight: 600;
	margin-bottom: 0.25rem;
}
</style>
