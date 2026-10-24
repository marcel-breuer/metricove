import { describe, expect, it } from 'bun:test';
import { parseMealPlanDate } from './meal-planning';

describe('parseMealPlanDate', () => {
	it('returns null for an empty or invalid date', () => {
		expect(parseMealPlanDate('')).toBeNull();
		expect(parseMealPlanDate('not-a-date')).toBeNull();
		expect(parseMealPlanDate('2026-02-30')).toBeNull();
	});

	it('preserves a valid local calendar date', () => {
		const date = parseMealPlanDate('2026-10-24');

		expect(date?.getFullYear()).toBe(2026);
		expect(date?.getMonth()).toBe(9);
		expect(date?.getDate()).toBe(24);
	});
});
