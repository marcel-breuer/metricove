<script lang="ts">
	import type { Snippet } from 'svelte';

	let { children, class: className = '' }: { children: Snippet; class?: string } = $props();
</script>

<div class="flex min-w-0 flex-wrap items-center gap-3 {className}">
	{@render children()}
</div>
