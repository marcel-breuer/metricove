<script lang="ts">
	import { getContext } from 'svelte';
	import { THEME_CONTEXT, type ThemeContext, type ThemePreference } from '#lib/theme';
	import IconChoiceGroup from './IconChoiceGroup.svelte';
	import { t } from '#lib/i18n';

	let { class: className = '' }: { class?: string } = $props();
	const theme = getContext<ThemeContext>(THEME_CONTEXT);
	const options = [
		{ value: 'system', label: 'Use device setting', icon: 'monitor' },
		{ value: 'light', label: 'Light', icon: 'sun' },
		{ value: 'dark', label: 'Dark', icon: 'moon' }
	] as const;
</script>

<IconChoiceGroup
	id="theme-preference"
	label={$t('Appearance')}
	value={theme.preference}
	class={className}
	options={options}
	onchange={(value) => theme.setPreference(value as ThemePreference)}
/>
