<script lang="ts">
	import type { Snippet } from 'svelte';

	const tones = {
		neutral: 'border-line text-ink-muted',
		accent: 'border-accent-ink/35 bg-accent-ink/8 text-accent-ink',
		success: 'border-positive/30 text-positive',
		warning: 'border-warning/30 bg-warning/8 text-warning',
		danger: 'border-danger/30 text-danger'
	};

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

<span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs leading-5 font-medium whitespace-nowrap {tones[tone]} {className}">{@render children()}</span>
