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

<div class="ui-empty-state {className}">
	<p class="ui-empty-state-title">{$t(title)}</p>
	{#if description}<p class="ui-empty-state-description">{$t(description)}</p>{/if}
	{#if actions}<div class="ui-empty-state-actions">{@render actions()}</div>{/if}
</div>
