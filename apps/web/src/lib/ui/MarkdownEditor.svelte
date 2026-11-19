<script lang="ts">
	import { tick } from 'svelte';
	import type { HTMLTextareaAttributes } from 'svelte/elements';
	import { t } from '#lib/i18n';
	let { value = $bindable(''), class: className = '', oninput, ...attrs }: Omit<HTMLTextareaAttributes, 'value'> & { value?: string } = $props();
	let textarea: HTMLTextAreaElement;
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
		textarea.dispatchEvent(new Event('input', { bubbles: true }));
		textarea.focus();
		textarea.setSelectionRange(start + before.length, end + before.length);
	}
</script>
<div class="min-w-0">
	<div role="group" aria-label={$t('Markdown formatting')} class="flex flex-wrap gap-1 pt-2">
		{#each tools as tool}
			<button type="button" aria-label={$t(tool.label)} title={$t(tool.label)} disabled={attrs.disabled || attrs.readonly} onclick={() => format(tool.before, tool.after, tool.line)} class="min-h-9 min-w-9 rounded-lg border border-line bg-surface px-2 text-sm font-semibold text-ink-secondary hover:border-accent-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink disabled:opacity-50">{tool.text}</button>
		{/each}
	</div>
	<textarea {...attrs} bind:this={textarea} bind:value {oninput} class={className}></textarea>
</div>
