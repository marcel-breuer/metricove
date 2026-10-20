export type ThemePreference = 'system' | 'light' | 'dark';
export type ResolvedTheme = 'light' | 'dark';

export const THEME_STORAGE_KEY = 'metricove-theme';

export function parseThemePreference(value: unknown): ThemePreference {
	return value === 'light' || value === 'dark' || value === 'system' ? value : 'system';
}

export function resolveThemePreference(
	preference: ThemePreference,
	prefersDark: boolean
): ResolvedTheme {
	if (preference === 'system') return prefersDark ? 'dark' : 'light';
	return preference;
}

export type ThemeDocument = {
	dataset: { theme?: string; themePreference?: string };
	style: { colorScheme: string };
};

export function applyThemePreference(
	root: ThemeDocument,
	preference: ThemePreference,
	prefersDark: boolean
): ResolvedTheme {
	const theme = resolveThemePreference(preference, prefersDark);
	root.dataset.theme = theme;
	root.dataset.themePreference = preference;
	root.style.colorScheme = theme;
	return theme;
}

export type ThemeContext = {
	readonly preference: ThemePreference;
	setPreference: (preference: ThemePreference) => void;
};

export const THEME_CONTEXT = Symbol('metricove-theme');
