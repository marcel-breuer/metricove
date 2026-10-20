<script lang="ts">
	import type { Snippet } from 'svelte';

	let {
		padding = 'medium',
		class: className = '',
		children
	}: {
		padding?: 'none' | 'medium' | 'large';
		class?: string;
		children: Snippet;
	} = $props();
</script>

<section class="ui-panel ui-panel--{padding} {className}">
	{@render children()}
</section>
