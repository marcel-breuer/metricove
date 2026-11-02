<script lang="ts">
	import { getContext } from 'svelte';
	import { THEME_CONTEXT, type ThemeContext, type ThemePreference } from '#lib/theme';
	import { t } from '#lib/i18n';

	let { class: className = '' }: { class?: string } = $props();
	const theme = getContext<ThemeContext>(THEME_CONTEXT);
	const options: { value: ThemePreference; label: string; accessibleLabel: string }[] = [
		{ value: 'system', label: 'System', accessibleLabel: 'Use device setting' },
		{ value: 'light', label: 'Light', accessibleLabel: 'Light' },
		{ value: 'dark', label: 'Dark', accessibleLabel: 'Dark' }
	];
</script>

<fieldset class="theme-picker {className}">
	<legend>{$t('Appearance')}</legend>
	<div class="theme-picker-options">
		{#each options as option (option.value)}
			<button
				type="button"
				class="theme-picker-option"
				class:theme-picker-option--selected={theme.preference === option.value}
				aria-label={$t(option.accessibleLabel)}
				aria-pressed={theme.preference === option.value}
				onclick={() => theme.setPreference(option.value)}
			>
				{#if option.value === 'system'}
					<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
						<rect x="3.5" y="4.5" width="17" height="12" rx="2" stroke="currentColor" stroke-width="1.7" />
						<path d="M8 20h8m-4-3.5V20" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
					</svg>
				{:else if option.value === 'light'}
					<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
						<circle cx="12" cy="12" r="3.5" stroke="currentColor" stroke-width="1.7" />
						<path d="M12 2.5v2m0 15v2m9.5-9.5h-2m-15 0h-2m16.22-6.72-1.42 1.42M6.7 17.3l-1.42 1.42m13.44 0-1.42-1.42M6.7 6.7 5.28 5.28" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
					</svg>
				{:else}
					<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
						<path d="M19.8 14.2A8.2 8.2 0 0 1 9.8 4.2 8.25 8.25 0 1 0 19.8 14.2Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
					</svg>
				{/if}
				<span>{$t(option.label)}</span>
			</button>
		{/each}
	</div>
</fieldset>

<style>
	.theme-picker {
		min-width: 0;
		margin: 0;
		border: 0;
		padding: 0;
	}

	.theme-picker legend {
		margin-bottom: 0.35rem;
		padding: 0;
		color: var(--text-secondary);
		font-size: 0.75rem;
		font-weight: 650;
	}

	.theme-picker-options {
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		gap: 0.375rem;
	}

	.theme-picker-option {
		display: flex;
		min-width: 0;
		min-height: 3.25rem;
		align-items: center;
		justify-content: center;
		gap: 0.45rem;
		border: 1px solid var(--border-default);
		border-radius: 0.75rem;
		background: var(--surface-page);
		padding: 0.5rem 0.625rem;
		color: var(--text-secondary);
		font: inherit;
		font-size: 0.75rem;
		font-weight: 600;
		line-height: 1.1;
		white-space: nowrap;
		cursor: pointer;
	}

	.theme-picker-option:hover {
		border-color: var(--accent-text);
		color: var(--text-primary);
	}

	.theme-picker-option:focus-visible {
		outline: 2px solid var(--accent-text);
		outline-offset: 2px;
	}

	.theme-picker-option--selected {
		border-color: var(--accent-text);
		background: color-mix(in srgb, var(--accent-text) 12%, var(--surface-raised));
		color: var(--text-primary);
	}

	.theme-picker-option svg {
		width: 1.125rem;
		height: 1.125rem;
		flex: 0 0 auto;
	}

	@media (max-width: 360px) {
		.theme-picker-option {
			gap: 0.3rem;
			padding-inline: 0.35rem;
			font-size: 0.6875rem;
		}
	}
</style>
