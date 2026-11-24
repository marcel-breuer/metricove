<script lang="ts">
	import type { Snippet } from 'svelte';
	import { onMount } from 'svelte';
	import { t } from '#lib/i18n';
	import * as Collapsible from '#lib/components/ui/collapsible/index.js';

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

<Collapsible.Root class="group rounded-[16px] border glass-control {className}" bind:open>
	<Collapsible.Trigger class="flex min-h-11 w-full cursor-pointer items-center justify-between gap-3 px-4 py-2 text-left text-sm font-semibold text-ink focus-visible:rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
		<span>{$t(summary)}{#if hint}<span class="ml-2 font-normal text-ink-muted">{hint}</span>{/if}</span>
		<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4 text-ink-muted transition-transform group-data-[state=open]:rotate-180"><path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" /></svg>
	</Collapsible.Trigger>
	<Collapsible.Content forceMount class="px-4 pb-4 data-[state=closed]:hidden">{@render children()}</Collapsible.Content>
</Collapsible.Root>
