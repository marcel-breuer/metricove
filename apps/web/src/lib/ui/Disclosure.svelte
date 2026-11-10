<script lang="ts">
	import type { Snippet } from 'svelte';
	import { onMount } from 'svelte';
	import { t } from '#lib/i18n';

	let {
		summary,
		hint,
		openFrom = '(min-width: 640px)',
		class: className = '',
		children
	}: {
		summary: string;
		/** Short status shown next to the summary, e.g. the number of active filters. */
		hint?: string;
		/** Media query that opens the disclosure by default; collapsed on smaller screens. */
		openFrom?: string;
		class?: string;
		children: Snippet;
	} = $props();

	let open = $state(true);

	onMount(() => {
		open = window.matchMedia(openFrom).matches;
	});
</script>

<details class="group rounded-[16px] border glass-control {className}" bind:open>
	<summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-4 py-2 text-sm font-semibold text-ink focus-visible:rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink [&::-webkit-details-marker]:hidden">
		<span>{$t(summary)}{#if hint}<span class="ml-2 font-normal text-ink-muted">{hint}</span>{/if}</span>
		<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4 text-ink-muted transition-transform group-open:rotate-180"><path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" /></svg>
	</summary>
	<div class="px-4 pb-4">{@render children()}</div>
</details>
