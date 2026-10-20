<script lang="ts">
	import type { Snippet } from 'svelte';

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

<header class="ui-page-heading {className}">
	<div class="min-w-0">
		{#if eyebrow}<p class="ui-eyebrow">{eyebrow}</p>{/if}
		<h1>{title}</h1>
		{#if description}<p class="ui-page-heading-description">{description}</p>{/if}
	</div>
	{#if actions}<div class="ui-page-heading-actions">{@render actions()}</div>{/if}
</header>
