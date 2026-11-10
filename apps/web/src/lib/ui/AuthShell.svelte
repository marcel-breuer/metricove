<script lang="ts">
	import type { Snippet } from 'svelte';
	import ThemePicker from './ThemePicker.svelte';
	import { t } from '#lib/i18n';

	let {
		eyebrow,
		title,
		description,
		note,
		wide = false,
		children
	}: {
		eyebrow: string;
		title: string;
		description: string;
		note?: string;
		wide?: boolean;
		children: Snippet;
	} = $props();
</script>

<main class="grid min-h-svh place-items-center atmospheric-page px-[clamp(0.75rem,3vw,2.5rem)] py-[clamp(1rem,4vh,2.5rem)] max-[760px]:items-start max-[760px]:p-3">
	<div class="min-h-[min(46rem,calc(100svh-3rem))] w-full max-w-[70rem] overflow-hidden rounded-[24px] border glass-chrome max-[760px]:min-h-0 max-[760px]:max-w-[32rem] max-[760px]:rounded-[20px]">
		<header class="flex items-center justify-between gap-6 border-b border-line px-[clamp(1.5rem,4vw,3rem)] py-5 max-[760px]:flex-wrap max-[760px]:gap-x-4 max-[760px]:gap-y-3.5 max-[760px]:px-5 max-[760px]:py-4 max-[360px]:px-4">
			<a href="/" class="inline-flex items-center gap-3 text-base font-semibold text-ink no-underline focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent-ink" aria-label={$t('Metricove home')}>
				<span class="grid size-10 place-items-center rounded-xl bg-[#0f766e] text-[#f0fdfa]" aria-hidden="true">
					<svg viewBox="0 0 32 32" fill="none" class="size-7">
						<path d="M6 23.5V9l10 8 10-8v14.5" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
						<path d="M11 23.5v-6.7m10 6.7v-6.7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
					</svg>
				</span>
				<span>Metricove</span>
			</a>
			<ThemePicker class="w-60 max-[760px]:w-full max-[760px]:max-w-64" />
		</header>

		<div class="grid min-h-[40rem] grid-cols-[minmax(0,0.94fr)_minmax(0,1.06fr)] max-[900px]:grid-cols-[minmax(0,0.88fr)_minmax(0,1.12fr)] max-[760px]:min-h-0 max-[760px]:grid-cols-1">
			<aside class="relative flex min-w-0 flex-col justify-between overflow-hidden bg-[#123d4b] p-[clamp(2.5rem,5vw,4.25rem)] text-[#f0fdfa] max-[900px]:px-8 max-[900px]:py-10 max-[760px]:hidden" aria-label={$t('About Metricove')}>
				<div class="relative z-10">
					<p class="text-xs font-medium text-[#99f6e4]">{$t('Your household, in focus')}</p>
					<h2 class="mt-[1.15rem] max-w-[23rem] text-[clamp(2rem,3.8vw,3rem)] leading-[1.08] font-medium tracking-[-0.035em]">{$t('One private place for your household.')}</h2>
					<p class="mt-5 max-w-[21rem] text-base leading-[1.7] text-[rgb(226_255_251/75%)]">{$t('A simpler way to keep the things that matter close at hand.')}</p>
				</div>

				<div class="relative z-0 -mx-5 mt-5 grid min-h-56 place-items-center max-[900px]:-mx-2" aria-hidden="true">
					<div class="w-full max-w-[25rem] rounded-2xl border border-[rgb(204_251_241/17%)] bg-[rgb(3_24_34/20%)] p-2">
						<div class="mb-1 flex min-h-9 items-center gap-3 px-3 text-xs font-medium text-[rgb(226_255_251/72%)]">
							<span class="h-4 w-1 rounded-[2px] bg-[#5eead4]" aria-hidden="true"></span>
							<span>{$t('Your modules')}</span>
						</div>
						{@render artRow(true, 'Finance', financeIcon)}
						{@render artRow(false, 'Utilities', utilitiesIcon)}
						{@render artRow(false, 'Vehicles', vehiclesIcon)}
					</div>
				</div>

				<p class="relative z-10 mt-6 flex items-center gap-2.5 text-[0.6875rem] text-[rgb(204_251_241/62%)]"><span class="size-[0.45rem] shrink-0 rounded-full bg-[#5eead4]"></span>{$t('One household · your space')}</p>
			</aside>

			<section class="flex min-w-0 items-center justify-center bg-transparent p-[clamp(2.5rem,6vw,5rem)] max-[900px]:px-10 max-[760px]:px-6 max-[760px]:pt-10 max-[760px]:pb-8 max-[360px]:px-5 {wide ? 'py-[clamp(1.75rem,4vh,3rem)] max-[760px]:pt-5 max-[760px]:pb-6' : ''}" aria-labelledby="auth-title">
				<div class="w-full {wide ? 'max-w-[29rem]' : 'max-w-[25rem]'}">
					<p class="text-xs font-medium text-accent-ink">{$t(eyebrow)}</p>
					<h1 id="auth-title" class="mt-3 text-[clamp(2.5rem,5vw,3.5rem)] leading-[1.05] font-medium tracking-[-0.035em] text-ink max-[760px]:text-[2.75rem]">{$t(title)}</h1>
					<p class="mt-4 text-[0.9375rem] leading-[1.7] text-ink-muted">{$t(description)}</p>
					{@render children()}
					{#if note}<p class="mt-8 flex items-center gap-[0.7rem] text-xs leading-normal text-ink-subtle max-[760px]:mt-6"><span class="size-[0.4rem] shrink-0 rounded-full bg-accent-ink"></span>{$t(note)}</p>{/if}
				</div>
			</section>
		</div>
	</div>
</main>

{#snippet financeIcon()}<path d="M4 7.5h16M6.5 4h11A2.5 2.5 0 0 1 20 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5v-11A2.5 2.5 0 0 1 6.5 4Z" stroke="currentColor" stroke-width="1.6"/><path d="M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>{/snippet}
{#snippet utilitiesIcon()}<path d="M12 3v18M5 7h14M7 7l-4 7h8L7 7Zm10 0-4 7h8l-4-7Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>{/snippet}
{#snippet vehiclesIcon()}<path d="M3 13.5 5.2 8a2 2 0 0 1 1.9-1.3h9.8A2 2 0 0 1 18.8 8l2.2 5.5v4h-2v-2H5v2H3v-4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="7" cy="13" r="1" fill="currentColor"/><circle cx="17" cy="13" r="1" fill="currentColor"/>{/snippet}

{#snippet artRow(active: boolean, label: string, icon: Snippet)}
	<div class="flex min-h-10 items-center gap-3 border-t border-[rgb(204_251_241/10%)] px-3 text-[0.8125rem] {active ? 'rounded-lg bg-[rgb(204_251_241/8%)] text-[#f0fdfa]' : 'text-[rgb(240_253_250/78%)]'}">
		<span class="grid size-5 shrink-0 place-items-center" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" class="size-[1.125rem]">{@render icon()}</svg></span>
		<span>{$t(label)}</span><span class="ml-auto size-1.5 rotate-45 border-t border-r border-current opacity-55" aria-hidden="true"></span>
	</div>
{/snippet}
