<script lang="ts">
	import type { Snippet } from 'svelte';

	let {
		tone = 'neutral',
		class: className = '',
		children
	}: {
		tone?: 'neutral' | 'accent' | 'success' | 'warning' | 'danger';
		class?: string;
		children: Snippet;
	} = $props();
</script>

<span class="ui-badge ui-badge--{tone} {className}">{@render children()}</span>
