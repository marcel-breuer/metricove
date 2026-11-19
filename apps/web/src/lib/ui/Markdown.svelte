<script lang="ts">
	import { renderMarkdown } from '#lib/markdown';
	let { value, class: className = '' }: { value: string; class?: string } = $props();
</script>
<div class="markdown-content {className}">{@html renderMarkdown(value)}</div>
