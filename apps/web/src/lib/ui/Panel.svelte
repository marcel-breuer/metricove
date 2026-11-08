<script lang="ts">
	import type { Snippet } from 'svelte';
	import { panel } from './styles';

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

<section class="{panel} {paddings[padding]} {className}">
	{@render children()}
</section>
