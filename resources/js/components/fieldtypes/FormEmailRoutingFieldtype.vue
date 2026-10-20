<!--
 * Builder-like fieldtype for email routing rules (list of Condition-like rows with
 * email). Uses EmailRoutingCondition; aligned with field-conditions Builder.vue for
 * layout and Add button.
 -->
<template>
	<div class="w-full">
		<email-routing-condition
			v-if="rules.length > 0"
			v-for="(rule, index) in rules"
			:key="rule._id"
			:index="index"
			:config="config"
			:condition="rule"
			:conditions="rules"
			:suggestable-fields="suggestableFields"
			@updated="updated(index, $event)"
			@removed="remove(index)"
		/>

		<div v-if="rules.length === 0" class="py-8 text-center text-gray-500">
			{{ __('No routing rules configured.') }}
		</div>

		<div class="border-t pt-6 dark:border-dark-900">
			<Button
				:text="__('Add Rule')"
				@click="add"
			/>
		</div>
	</div>
</template>

<script>
// Kept in Options API: depends on FieldtypeMixin (this.update(), value/config/meta, injects).
import { computed, getCurrentInstance, ref, watch } from 'vue';
import { nanoid as uniqid } from 'nanoid';
import { FieldtypeMixin as Fieldtype, HasInputOptionsMixin } from '@statamic/cms';
import { Button } from '@statamic/cms/ui';
import EmailRoutingCondition from './partials/EmailRoutingCondition.vue';

export default {
	mixins: [HasInputOptionsMixin, Fieldtype],

	components: { Button, EmailRoutingCondition },

	setup(props) {
		const instance = getCurrentInstance().proxy;

		const rules = ref([]);

		const suggestableFields = computed(
			() => props.meta?.suggestable_fields || []
		);

		const saveableRules = computed(() =>
			rules.value.map((rule) => ({
				field: rule.field,
				operator: rule.operator,
				value: rule.value,
				email: rule.email
			}))
		);

		watch(
			saveableRules,
			(newVal) => {
				instance.update(newVal);
			},
			{ deep: true }
		);

		function add() {
			rules.value.push({
				_id: uniqid(),
				field: null,
				operator: 'equals',
				value: null,
				email: null
			});
		}

		function remove(index) {
			rules.value.splice(index, 1);
		}

		function updated(index, rule) {
			rules.value.splice(index, 1, rule);
		}

		function getInitialRules() {
			if (Array.isArray(props.value)) {
				rules.value = props.value.map((rule) => ({
					...rule,
					_id: uniqid()
				}));
			}
		}

		add();
		getInitialRules();

		return {
			rules,
			suggestableFields,
			add,
			remove,
			updated
		};
	}
};
</script>
