<script lang="ts">
	import type { Snippet } from 'svelte';
	import { Card } from '#lib/components/ui/card/index.js';

	const paddings = { none: 'p-0', medium: 'p-5 max-sm:p-4', large: 'p-6 max-sm:p-5' };

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

<Card as="section" variant="glass" class="{paddings[padding]} {className}">
	{@render children()}
</Card>
