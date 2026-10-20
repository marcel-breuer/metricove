<script lang="ts">
	import '../app.css';
	import { onMount, setContext } from 'svelte';
	import favicon from '#lib/assets/favicon.svg';
	import {
		applyThemePreference,
		parseThemePreference,
		THEME_CONTEXT,
		THEME_STORAGE_KEY,
		type ThemeContext,
		type ThemePreference
	} from '#lib/theme';
	import type { LayoutProps } from './$types';

	let { children }: LayoutProps = $props();
	let themePreference = $state<ThemePreference>('system');

	const themeContext: ThemeContext = {
		get preference() {
			return themePreference;
		},
		setPreference
	};
	setContext(THEME_CONTEXT, themeContext);

	function applyCurrentTheme(): void {
		applyThemePreference(
			document.documentElement,
			themePreference,
			window.matchMedia('(prefers-color-scheme: dark)').matches
		);
	}

	function setPreference(preference: ThemePreference): void {
		themePreference = parseThemePreference(preference);
		try {
			window.localStorage.setItem(THEME_STORAGE_KEY, themePreference);
		} catch {
			// The selected theme still applies for this page when storage is unavailable.
		}
		applyCurrentTheme();
	}

	onMount(() => {
		try {
			themePreference = parseThemePreference(window.localStorage.getItem(THEME_STORAGE_KEY));
		} catch {
			themePreference = 'system';
		}

		const systemPreference = window.matchMedia('(prefers-color-scheme: dark)');
		const updateSystemTheme = () => applyCurrentTheme();
		updateSystemTheme();
		systemPreference.addEventListener('change', updateSystemTheme);

		return () => systemPreference.removeEventListener('change', updateSystemTheme);
	});
</script>

<svelte:head>
	<link rel="icon" href={favicon} />
</svelte:head>

{@render children()}
