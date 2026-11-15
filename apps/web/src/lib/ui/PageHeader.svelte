<script lang="ts">
	import type { Snippet } from 'svelte';
	import ActionGroup from './ActionGroup.svelte';

	let {
		children,
		actions,
		class: className = ''
	}: { children: Snippet; actions?: Snippet; class?: string } = $props();
</script>

<header class="flex min-w-0 flex-wrap items-start justify-between gap-4 {className}">
	<div class="min-w-0 flex-[1_1_24rem] [overflow-wrap:anywhere]">{@render children()}</div>
	{#if actions}<ActionGroup class="max-w-full">{@render actions()}</ActionGroup>{/if}
</header>
