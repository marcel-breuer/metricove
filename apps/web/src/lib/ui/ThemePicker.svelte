<script lang="ts">
	import { getContext } from 'svelte';
	import { THEME_CONTEXT, type ThemeContext, type ThemePreference } from '#lib/theme';
	import { t } from '#lib/i18n';
	import * as ToggleGroup from '#lib/components/ui/toggle-group/index.js';

	let { class: className = '' }: { class?: string } = $props();
	const theme = getContext<ThemeContext>(THEME_CONTEXT);
	const options: { value: ThemePreference; label: string; accessibleLabel: string }[] = [
		{ value: 'system', label: 'System', accessibleLabel: 'Use device setting' },
		{ value: 'light', label: 'Light', accessibleLabel: 'Light' },
		{ value: 'dark', label: 'Dark', accessibleLabel: 'Dark' }
	];

	function setSelection(values: string[]) {
		// Keep the current preference if its pressed button is activated again.
		const selection = options.find((option) => option.value === values.at(-1));
		if (selection) theme.setPreference(selection.value);
	}
</script>

<fieldset class="m-0 min-w-0 border-0 p-0 {className}">
	<legend class="mb-[0.35rem] p-0 text-xs font-[650] text-ink-secondary">{$t('Appearance')}</legend>
	<ToggleGroup.Root
		type="multiple"
		bind:value={() => [theme.preference], setSelection}
		rovingFocus={false}
		spacing={1.5}
		variant="theme-choice"
		class="grid w-full grid-cols-3 gap-1.5"
	>
		{#each options as option (option.value)}
			<ToggleGroup.Item
				value={option.value}
				class="h-auto min-h-13 min-w-0 gap-[0.45rem] px-2.5 py-2 max-[360px]:gap-[0.3rem] max-[360px]:px-[0.35rem] max-[360px]:text-[0.6875rem]"
				aria-label={$t(option.accessibleLabel)}
			>
				{#if option.value === 'system'}
					<svg class="size-[1.125rem]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
						<rect x="3.5" y="4.5" width="17" height="12" rx="2" stroke="currentColor" stroke-width="1.7" />
						<path d="M8 20h8m-4-3.5V20" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
					</svg>
				{:else if option.value === 'light'}
					<svg class="size-[1.125rem]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
						<circle cx="12" cy="12" r="3.5" stroke="currentColor" stroke-width="1.7" />
						<path d="M12 2.5v2m0 15v2m9.5-9.5h-2m-15 0h-2m16.22-6.72-1.42 1.42M6.7 17.3l-1.42 1.42m13.44 0-1.42-1.42M6.7 6.7 5.28 5.28" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
					</svg>
				{:else}
					<svg class="size-[1.125rem]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
						<path d="M19.8 14.2A8.2 8.2 0 0 1 9.8 4.2 8.25 8.25 0 1 0 19.8 14.2Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
					</svg>
				{/if}
				<span>{$t(option.label)}</span>
			</ToggleGroup.Item>
		{/each}
	</ToggleGroup.Root>
</fieldset>
