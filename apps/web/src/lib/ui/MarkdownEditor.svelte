<script lang="ts">
	import { tick } from 'svelte';
	import type { HTMLTextareaAttributes } from 'svelte/elements';
	import { t } from '#lib/i18n';
	import { Button } from '#lib/components/ui/button/index.js';
	import { Textarea } from '#lib/components/ui/textarea/index.js';
	let { value = $bindable(''), class: className = '', oninput, ...attrs }: Omit<HTMLTextareaAttributes, 'value'> & { value?: string } = $props();
	let textarea = $state<HTMLTextAreaElement | null>(null);
	const tools = [
		{ label: 'Bold', text: 'B', before: '**', after: '**' },
		{ label: 'Italic', text: 'I', before: '*', after: '*' },
		{ label: 'Heading', text: 'H', before: '## ', after: '', line: true },
		{ label: 'Bulleted list', text: '•', before: '- ', after: '', line: true },
		{ label: 'Link', text: '↗', before: '[', after: '](https://)' }
	];
	async function format(before: string, after: string, line = false) {
		if (!textarea || textarea.disabled || textarea.readOnly) return;
		const end = textarea.selectionEnd;
		const start = line ? value.lastIndexOf('\n', textarea.selectionStart - 1) + 1 : textarea.selectionStart;
		const selected = value.slice(start, end);
		const next = value.slice(0, start) + before + selected + after + value.slice(end);
		if (textarea.maxLength >= 0 && next.length > textarea.maxLength) return;
		value = next;
		await tick();
		if (!textarea) return;
		textarea.dispatchEvent(new Event('input', { bubbles: true }));
		textarea.focus();
		textarea.setSelectionRange(start + before.length, end + before.length);
	}
</script>
<div class="min-w-0">
	<div role="group" aria-label={$t('Markdown formatting')} class="flex flex-wrap gap-1 pt-2">
		{#each tools as tool}
			<Button variant="ghost" size="icon" type="button" aria-label={$t(tool.label)} title={$t(tool.label)} disabled={attrs.disabled || attrs.readonly} onclick={() => format(tool.before, tool.after, tool.line)} class="rounded-lg border-line bg-surface text-sm text-ink-secondary hover:not-disabled:border-accent-ink hover:not-disabled:bg-surface hover:not-disabled:text-ink-secondary">{tool.text}</Button>
		{/each}
	</div>
	<Textarea {...attrs} variant="glass" bind:ref={textarea} bind:value {oninput} class={className} />
</div>
