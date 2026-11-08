<script lang="ts">
	import type { Snippet } from 'svelte';
	import { t } from '#lib/i18n';

	let {
		eyebrow,
		title,
		description,
		class: className = '',
		actions
	}: {
		eyebrow?: string;
		title: string;
		description?: string;
		class?: string;
		actions?: Snippet;
	} = $props();
</script>

<header class="flex flex-wrap items-end justify-between gap-4 max-sm:items-start {className}">
	<div class="min-w-0">
		{#if eyebrow}<p class="text-[0.8125rem] font-semibold tracking-[0.01em] text-accent-ink">{$t(eyebrow)}</p>{/if}
		<h1 class="mt-2 text-[clamp(1.75rem,3vw,2.25rem)] leading-[1.16] font-[650] tracking-[-0.03em] text-ink">{$t(title)}</h1>
		{#if description}<p class="mt-3.5 max-w-3xl text-base leading-[1.75] text-ink-secondary max-sm:text-[0.9375rem] max-sm:leading-[1.625rem]">{$t(description)}</p>{/if}
	</div>
	{#if actions}<div class="flex items-center gap-3 max-sm:w-full max-sm:flex-wrap max-sm:*:flex-auto">{@render actions()}</div>{/if}
</header>
