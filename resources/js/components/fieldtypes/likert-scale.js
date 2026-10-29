/**
 * Display logic for the LikertScale fieldtype's Control Panel component (UAMS-Web/uams-statamic-archive#194).
 *
 * `LikertScaleFieldtype.vue` only renders what these return. They are kept in a
 * plain module, outside the component, so Vitest can exercise them without the
 * `@statamic/cms` imports a `.vue` file needs (the same split as
 * `consent-agreement.js`). Every option, label and order is resolved server-side
 * in `UAMSWeb\FormSettings\Fieldtypes\LikertScale::preload()`; nothing here re-derives them.
 *
 * Value shapes:
 *   - single row: a scale point (number) or the string 'not_applicable'
 *   - matrix: an object keyed by row label, each a scale point or 'not_applicable'
 */

/**
 * The ordered `{value, label}` options resolved by preload().
 *
 * @param {object|null|undefined} meta The fieldtype's preload meta.
 * @returns {Array<{value: *, label: string}>}
 */
export function likertOptions(meta) {
    return meta?.options ?? [];
}

/**
 * Whether the scale renders horizontally (the default when meta omits it).
 *
 * @param {object|null|undefined} meta The fieldtype's preload meta.
 * @returns {boolean}
 */
export function likertInline(meta) {
    return meta?.inline ?? true;
}

/**
 * The matrix row labels.
 *
 * @param {object|null|undefined} meta The fieldtype's preload meta.
 * @returns {string[]}
 */
export function likertRows(meta) {
    return meta?.multiple_rows ?? [];
}

/**
 * Whether to render the matrix: multiple rows enabled and at least one row label.
 *
 * @param {object|null|undefined} meta The fieldtype's preload meta.
 * @returns {boolean}
 */
export function isLikertMatrix(meta) {
    return !!meta?.enable_multiple_rows && likertRows(meta).length > 0;
}

/**
 * The selected value for one matrix row.
 *
 * @param {*} value The field value: a row-label to point map in matrix mode.
 * @param {string} row The row label.
 * @returns {*} The row's point, or undefined when the value is not a map.
 */
export function likertRowValue(value, row) {
    return value && typeof value === 'object' ? value[row] : undefined;
}

/**
 * The field value after one matrix row changes, keeping the other rows' selections.
 *
 * @param {*} value The current field value.
 * @param {string} row The row label being changed.
 * @param {*} point The newly selected point.
 * @returns {object} A new row-label to point map; the current value is not mutated.
 */
export function likertWithRow(value, row, point) {
    const current = value && typeof value === 'object' ? value : {};

    return { ...current, [row]: point };
}
