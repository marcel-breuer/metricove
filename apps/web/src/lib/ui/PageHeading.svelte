<script lang="ts">
	import type { Snippet } from 'svelte';
	import PageHeader from './PageHeader.svelte';
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

<PageHeader class={className} {actions}>
	{#if eyebrow}<p class="text-xs font-semibold text-accent-ink">{$t(eyebrow)}</p>{/if}
	<h1 class="page-title">{$t(title)}</h1>
	{#if description}<p class="mt-2 max-w-2xl text-[0.9375rem] leading-6 text-ink-secondary">{$t(description)}</p>{/if}
</PageHeader>
