<script lang="ts">
	import type { Snippet } from 'svelte';
	import { t } from '#lib/i18n';
	import * as Empty from '#lib/components/ui/empty/index.js';
	import { cn } from '#lib/utils.js';

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

<Empty.Root class={cn('flex-initial rounded-xl border border-line-strong px-5 py-6 text-wrap', className)}>
	<Empty.Header class="w-full max-w-none gap-1.5">
		<Empty.Title class="text-[0.9375rem] font-semibold tracking-normal text-ink">{$t(title)}</Empty.Title>
		{#if description}
			<Empty.Description class="max-w-xl text-sm leading-6 text-ink-muted">{$t(description)}</Empty.Description>
		{/if}
	</Empty.Header>
	{#if actions}
		<Empty.Content class="max-w-none flex-row flex-wrap justify-center gap-3 text-wrap">{@render actions()}</Empty.Content>
	{/if}
</Empty.Root>
