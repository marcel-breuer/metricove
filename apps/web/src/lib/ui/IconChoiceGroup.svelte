<script lang="ts">
	import { t } from '#lib/i18n';
	import * as RadioGroup from '#lib/components/ui/radio-group/index.js';

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

<fieldset class="m-0 grid min-w-0 gap-2 border-0 p-0 {className}">
	<legend id={`${id}-legend`} class="text-sm font-[550] text-ink-secondary">{$t(label)}</legend>
	<RadioGroup.Root
		name={id}
		orientation="horizontal"
		bind:value={() => value, onchange}
		aria-labelledby={`${id}-legend`}
		class="gap-2 {options.length === 2 ? 'grid-cols-2' : 'grid-cols-3'}"
	>
		{#each options as option (option.value)}
			<RadioGroup.Item id={`${id}-${option.value}`} value={option.value} variant="tile">
				<span class="flex h-[1.375rem] items-center justify-center gap-1 text-accent-ink [&_svg]:size-[1.375rem] [&_svg]:stroke-current [&_svg]:[stroke-linecap:round] [&_svg]:[stroke-linejoin:round] [&_svg]:[stroke-width:1.6]" aria-hidden="true">
					{#if option.icon === 'language'}
						<svg viewBox="0 0 24 24" fill="none">
							<circle cx="12" cy="12" r="9" />
							<path d="M3 12h18M12 3a14.5 14.5 0 0 1 0 18M12 3a14.5 14.5 0 0 0 0 18" />
						</svg>
						<span class="rounded-md bg-accent-ink/15 px-[0.3rem] py-0.5 text-[0.6875rem] leading-4 font-[750] tracking-[0.04em]">{option.marker}</span>
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
				<span class="max-w-full text-xs leading-[1.15] font-[550] [overflow-wrap:anywhere] text-ink">{$t(option.label)}</span>
			</RadioGroup.Item>
		{/each}
	</RadioGroup.Root>
</fieldset>
