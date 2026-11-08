<script lang="ts">
	import type { Snippet } from 'svelte';
	import { t } from '#lib/i18n';

	let {
		title,
		description,
		class: className = '',
		actions
	}: {
		title: string;
		description?: string;
		class?: string;
		actions?: Snippet;
	} = $props();
</script>

<div class="rounded-xl border border-dashed border-line-strong px-5 py-6 text-center {className}">
	<p class="text-[0.9375rem] font-semibold text-ink">{$t(title)}</p>
	{#if description}<p class="mx-auto mt-1.5 max-w-xl text-sm leading-6 text-ink-muted">{$t(description)}</p>{/if}
	{#if actions}<div class="mt-4 flex flex-wrap justify-center gap-3">{@render actions()}</div>{/if}
</div>
