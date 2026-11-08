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

<details class="ui-disclosure {className}" bind:open>
	<summary>
		<span>{$t(summary)}{#if hint}<span class="ml-2 font-normal text-[var(--text-muted)]">{hint}</span>{/if}</span>
		<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="ui-disclosure-chevron size-4"><path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" /></svg>
	</summary>
	<div class="ui-disclosure-body">{@render children()}</div>
</details>
