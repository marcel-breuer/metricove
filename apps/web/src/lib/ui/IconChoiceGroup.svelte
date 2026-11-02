<script lang="ts">
	import { t } from '#lib/i18n';

	type ChoiceIcon = 'monitor' | 'sun' | 'moon' | 'language';
	type IconChoice = {
		value: string;
		label: string;
		icon: ChoiceIcon;
		marker?: string;
	};

	let {
		id,
		label,
		value,
		options,
		onchange,
		class: className = ''
	}: {
		id: string;
		label: string;
		value: string;
		options: readonly IconChoice[];
		onchange: (value: string) => void;
		class?: string;
	} = $props();
</script>

<fieldset class="icon-choice-group {className}">
	<legend class="icon-choice-legend">{$t(label)}</legend>
	<div class="icon-choice-grid" class:icon-choice-grid--two={options.length === 2}>
		{#each options as option (option.value)}
			<label class="icon-choice">
				<input
					id={`${id}-${option.value}`}
					class="icon-choice-input sr-only"
					type="radio"
					name={id}
					value={option.value}
					checked={value === option.value}
					onchange={() => onchange(option.value)}
				/>
				<span class="icon-choice-card">
					<span class="icon-choice-icon" aria-hidden="true">
						{#if option.icon === 'language'}
							<svg viewBox="0 0 24 24" fill="none">
								<circle cx="12" cy="12" r="9" />
								<path d="M3 12h18M12 3a14.5 14.5 0 0 1 0 18M12 3a14.5 14.5 0 0 0 0 18" />
							</svg>
							<span class="icon-choice-language-marker">{option.marker}</span>
						{:else}
						<svg viewBox="0 0 24 24" fill="none">
							{#if option.icon === 'monitor'}
								<rect x="3.5" y="4" width="17" height="12" rx="1.75" />
								<path d="M8.5 20h7m-3.5-4v4" />
							{:else if option.icon === 'sun'}
								<circle cx="12" cy="12" r="3.5" />
								<path d="M12 2.75v2m0 14.5v2m9.25-9.25h-2m-14.5 0h-2m16.28-6.53-1.41 1.41M6.38 17.62l-1.41 1.41m13.06 0-1.41-1.41M6.38 6.38 4.97 4.97" />
							{:else}
								<path d="M20.4 15.2A8.5 8.5 0 0 1 8.8 3.6 8.5 8.5 0 1 0 20.4 15.2Z" />
							{/if}
						</svg>
						{/if}
					</span>
					<span class="icon-choice-label">{$t(option.label)}</span>
				</span>
			</label>
		{/each}
	</div>
</fieldset>

<style>
	.icon-choice-group {
		display: grid;
		min-width: 0;
		gap: 0.5rem;
		margin: 0;
		padding: 0;
		border: 0;
	}

	.icon-choice-legend {
		color: var(--text-secondary);
		font-size: 0.875rem;
		font-weight: 550;
	}

	.icon-choice-grid {
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		gap: 0.5rem;
	}

	.icon-choice-grid--two {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.icon-choice {
		min-width: 0;
		cursor: pointer;
	}

	.icon-choice-card {
		display: flex;
		min-height: 4.5rem;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 0.375rem;
		border: 1px solid var(--control-border);
		border-radius: 0.75rem;
		background: var(--control-background);
		padding: 0.5rem 0.25rem;
		color: var(--text-secondary);
		text-align: center;
		transition: border-color 150ms ease, background-color 150ms ease, color 150ms ease;
	}

	.icon-choice-input:checked + .icon-choice-card {
		border-color: var(--accent-text);
		background: color-mix(in srgb, var(--accent-text) 12%, var(--control-background));
		color: var(--text-primary);
	}

	.icon-choice-input:focus-visible + .icon-choice-card {
		outline: 2px solid var(--accent-text);
		outline-offset: 2px;
	}

	.icon-choice-card:hover {
		border-color: var(--accent-text);
	}

	.icon-choice-icon {
		display: flex;
		height: 1.375rem;
		align-items: center;
		justify-content: center;
		gap: 0.25rem;
		color: var(--accent-text);
	}

	.icon-choice-icon svg {
		height: 1.375rem;
		width: 1.375rem;
		stroke: currentColor;
		stroke-linecap: round;
		stroke-linejoin: round;
		stroke-width: 1.6;
	}

	.icon-choice-language-marker {
		border-radius: 0.375rem;
		background: color-mix(in srgb, var(--accent-text) 15%, transparent);
		padding: 0.125rem 0.3rem;
		font-size: 0.6875rem;
		font-weight: 750;
		letter-spacing: 0.04em;
		line-height: 1rem;
	}

	.icon-choice-label {
		max-width: 100%;
		color: var(--text-primary);
		font-size: 0.75rem;
		font-weight: 550;
		line-height: 1.15;
		overflow-wrap: anywhere;
	}
</style>
