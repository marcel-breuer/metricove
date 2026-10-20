<script lang="ts">
	import { getContext } from 'svelte';
	import { THEME_CONTEXT, type ThemeContext, type ThemePreference } from '#lib/theme';
	import SelectField from './SelectField.svelte';

	let { class: className = '' }: { class?: string } = $props();
	const theme = getContext<ThemeContext>(THEME_CONTEXT);
	const options = [
		{ value: 'system', label: 'Use device setting' },
		{ value: 'light', label: 'Light' },
		{ value: 'dark', label: 'Dark' }
	];
</script>

<SelectField
	id="theme-preference"
	label="Appearance"
	value={theme.preference}
	class={className}
	{options}
	onchange={(value) => theme.setPreference(value as ThemePreference)}
/>
