<!--
 * Custom Condition.vue
 *
 * Local copy of Statamic 6.x Condition.vue. We do not import the vendor file because
 * it uses internal paths (@/components/ui, @ui, ../fieldtypes/HasInputOptions.js).
 * Resolving those would require Vite aliases that pull in the full vendor UI tree
 * and its dependencies (e.g. cva). This copy keeps 6.x behavior and only swaps
 * imports to the package so our build resolves.
 *
 * FORKED FROM: Statamic 6.x resources/js/components/field-conditions/Condition.vue
 * Script logic last synced: 2026-10-06 from statamic/cms v6.35.0 (the `includes_any`
 * operator, statamic/cms#13135, and more than one condition on a field, statamic/cms#14593).
 * The template's class and prop differences predate that sync and are not upstream's.
 *
 * Customizations:
 *   - Import HasInputOptionsMixin from @statamic/cms (6.x uses HasInputOptions from
 *     ../fieldtypes/HasInputOptions.js).
 *   - Import Description, Combobox, Input, Button, Select, Switch from
 *     @statamic/cms/ui (6.x uses @/components/ui and @ui).
 *   - Use mixins: [HasInputOptionsMixin] (package exports HasInputOptionsMixin).
 *   - In fieldSelectBlur, call fieldSelected(value) (6.x erroneously calls
 *     fieldUpdated(value), which does not exist).
 *
 * Composition API: Intentionally left in Options API. Converting would diverge
 * from upstream Statamic structure and complicate future comparison or merge
 * with vendor updates. Options API and $refs are valid in Vue 3.
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
                @update:modelValue="fieldSelected"
                search:blur="fieldSelectBlur"
            >
                <template #no-options><div class="hidden" /></template>
                <template #option="option">
                    <div class="flex items-center">
                        <span v-text="option.label" />
                        <span
                            v-text="option.value"
                            class="font-mono text-2xs text-gray-500 dark:text-dark-150"
                            :class="{ 'ml-2': option.label }"
                        />
                    </div>
                </template>
                <template #selected-option="{ option }">
                    <div class="truncate" v-text="__(field?.config.display) || option.value" />
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
            @update:model-value="valueUpdated"
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
            search:blur="valueSelectBlur"
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
    </div>
</template>

<script>
import { HasInputOptionsMixin } from '@statamic/cms'; // CUSTOM: 6.x uses `import HasInputOptions from '../fieldtypes/HasInputOptions.js'`; we use the package so our Vite build resolves.
import { Description, Combobox, Input, Button, Select, Switch } from '@statamic/cms/ui'; // CUSTOM: 6.x uses `@/components/ui` and `@ui`; we use the package so our Vite build resolves.

export default {
    mixins: [HasInputOptionsMixin], // CUSTOM: 6.x uses HasInputOptions; the package exports HasInputOptionsMixin.

    components: { Description, Combobox, Input, Button, Select, Switch },

    props: {
        config: {
            type: Object,
            required: true,
        },
        condition: {
            type: Object,
            required: true,
        },
        conditions: {
            type: Array,
            required: true,
        },
        index: {
            type: Number,
            required: true,
        },
        suggestableFields: {
            type: Array,
            required: true,
        },
    },

    computed: {
        field() {
            return this.suggestableFields.find((field) => field.handle === this.condition.field);
        },

        showValueToggle() {
            return (
                this.field &&
                ['toggle', 'revealer'].includes(this.field.config.type) &&
                ['equals', 'not', '===', '!=='].includes(this.condition.operator)
            );
        },

        showValueDropdown() {
            return (
                this.field &&
                ['button_group', 'checkboxes', 'radio', 'select'].includes(this.field.config.type) &&
                ['equals', 'not', '===', '!=='].includes(this.condition.operator)
            );
        },

        valueOptions() {
            if (!this.showValueDropdown) return;

            return this.normalizeInputOptions(this.field.config.options);
        },

        fieldOptions() {
            return this.suggestableFields
                .filter((field) => {
                    return !(
                        field.handle === this.config.handle || // Exclude the field you're adding a condition to.
                        this.condition.field === field.handle // Exclude the field being used in the current condition.
                    );
                })
                .map((field) => {
                    let display = field.config.display;

                    if (!display) {
                        display = field.handle.replace(/_/g, ' ').replace(/(?:^|\s)\S/g, function (a) {
                            return a.toUpperCase();
                        });
                    }

                    return { value: field.handle, label: display };
                });
        },

        operatorOptions() {
            return this.normalizeInputOptions({
                equals: __('Equals'),
                not: __('Not'),
                contains: __('Contains'),
                contains_any: __('Contains Any'),
                includes_any: __('Includes Any'),
                '===': '===',
                '!==': '!==',
                '>': '>',
                '>=': '>=',
                '<': '<',
                '<=': '<=',
                custom: __('Custom'),
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

        fieldSelectBlur() {
            const value = this.$refs.fieldSelect.$refs.search.value;
            if (value) this.fieldSelected(value); // CUSTOM: 6.x calls this.fieldUpdated(value), which does not exist; we call fieldSelected so the handler runs.
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
            const value = this.$refs.valueSelect.$refs.search.value;
            if (value) this.valueUpdated(value);
        },

        remove() {
            this.$emit('removed');
        },
    },
};
</script>
