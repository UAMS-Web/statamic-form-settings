import { describe, expect, it } from 'vitest';
import {
    isLikertMatrix,
    likertInline,
    likertOptions,
    likertRows,
    likertRowValue,
    likertWithRow,
} from './likert-scale.js';

// The LikertScale fieldtype's display logic (UAMS-Web/uams-statamic-archive#194, UAMS-Web/uams-statamic-archive#3091): each export is pinned
// by at least one test that fails if its body is emptied.

const options = [
    { value: 1, label: 'Disagree' },
    { value: 2, label: 'Agree' },
];

describe('likertOptions', () => {
    it('returns the preloaded options', () => {
        expect(likertOptions({ options })).toBe(options);
    });

    it('returns an empty list when meta or its options are missing', () => {
        expect(likertOptions(undefined)).toEqual([]);
        expect(likertOptions({})).toEqual([]);
    });
});

describe('likertInline', () => {
    it('defaults to horizontal when meta omits it', () => {
        expect(likertInline(undefined)).toBe(true);
        expect(likertInline({})).toBe(true);
    });

    it('honors an explicit vertical layout', () => {
        expect(likertInline({ inline: false })).toBe(false);
    });
});

describe('likertRows', () => {
    it('returns the configured row labels', () => {
        expect(likertRows({ multiple_rows: ['Speed', 'Quality'] })).toEqual(['Speed', 'Quality']);
    });

    it('returns an empty list when meta or its rows are missing', () => {
        expect(likertRows(null)).toEqual([]);
        expect(likertRows({})).toEqual([]);
    });
});

describe('isLikertMatrix', () => {
    it('renders the matrix when multiple rows are enabled and labeled', () => {
        expect(isLikertMatrix({ enable_multiple_rows: true, multiple_rows: ['Speed'] })).toBe(true);
    });

    it('renders a single row when multiple rows are enabled but none are labeled', () => {
        expect(isLikertMatrix({ enable_multiple_rows: true, multiple_rows: [] })).toBe(false);
        expect(isLikertMatrix({ enable_multiple_rows: true })).toBe(false);
    });

    it('renders a single row when multiple rows are disabled, even with labels', () => {
        expect(isLikertMatrix({ enable_multiple_rows: false, multiple_rows: ['Speed'] })).toBe(false);
        expect(isLikertMatrix(undefined)).toBe(false);
    });
});

describe('likertRowValue', () => {
    it("reads one row's point from the matrix value", () => {
        expect(likertRowValue({ Speed: 2, Quality: 'not_applicable' }, 'Speed')).toBe(2);
        expect(likertRowValue({ Speed: 2, Quality: 'not_applicable' }, 'Quality')).toBe('not_applicable');
    });

    it('returns undefined for an unrated row or a value that is not a map', () => {
        expect(likertRowValue({ Speed: 2 }, 'Quality')).toBeUndefined();
        expect(likertRowValue(3, 'Speed')).toBeUndefined();
        expect(likertRowValue(null, 'Speed')).toBeUndefined();
    });
});

describe('likertWithRow', () => {
    it("sets one row while keeping the other rows' selections", () => {
        expect(likertWithRow({ Speed: 2, Quality: 1 }, 'Quality', 2)).toEqual({ Speed: 2, Quality: 2 });
    });

    it('does not mutate the current value', () => {
        const current = { Speed: 2 };

        likertWithRow(current, 'Quality', 1);

        expect(current).toEqual({ Speed: 2 });
    });

    it('starts a new map when the current value is not one', () => {
        expect(likertWithRow(null, 'Speed', 1)).toEqual({ Speed: 1 });
        expect(likertWithRow(4, 'Speed', 1)).toEqual({ Speed: 1 });
    });
});
