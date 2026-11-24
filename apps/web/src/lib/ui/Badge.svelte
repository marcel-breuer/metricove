<script lang="ts">
	import type { Snippet } from 'svelte';

	import { Badge } from '#lib/components/ui/badge/index.js';

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

<Badge variant={tone === 'neutral' ? 'outline' : tone} class={className}>{@render children()}</Badge>
