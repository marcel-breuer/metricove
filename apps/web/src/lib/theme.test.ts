import { describe, expect, it } from 'bun:test';
import {
	applyThemePreference,
	parseThemePreference,
	resolveThemePreference
} from './theme';

describe('theme preferences', () => {
	it('uses the browser choice when the stored value is missing or invalid', () => {
		expect(parseThemePreference(null)).toBe('system');
		expect(parseThemePreference('sepia')).toBe('system');
		expect(resolveThemePreference('system', true)).toBe('dark');
		expect(resolveThemePreference('system', false)).toBe('light');
	});

	it('keeps explicit light and dark choices independent from the browser setting', () => {
		expect(resolveThemePreference('light', true)).toBe('light');
		expect(resolveThemePreference('dark', false)).toBe('dark');
	});

	it('applies both the preference and resolved theme to the document root', () => {
		const root = { dataset: {}, style: { colorScheme: '' } };

		expect(applyThemePreference(root, 'system', true)).toBe('dark');
		expect(root).toEqual({
			dataset: { theme: 'dark', themePreference: 'system' },
			style: { colorScheme: 'dark' }
		});
	});
});
