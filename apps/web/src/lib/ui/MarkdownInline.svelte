<script lang="ts">
	import { renderMarkdownInline } from '#lib/markdown';
	let { value, class: className = '' }: { value: string; class?: string } = $props();
</script>
<span class="markdown-content {className}">{@html renderMarkdownInline(value)}</span>
