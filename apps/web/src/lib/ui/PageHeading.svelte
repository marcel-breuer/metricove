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

<header class="ui-page-heading {className}">
	<div class="min-w-0">
		{#if eyebrow}<p class="ui-eyebrow">{$t(eyebrow)}</p>{/if}
		<h1>{$t(title)}</h1>
		{#if description}<p class="ui-page-heading-description">{$t(description)}</p>{/if}
	</div>
	{#if actions}<div class="ui-page-heading-actions">{@render actions()}</div>{/if}
</header>
