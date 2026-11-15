<script lang="ts">
	import type { Snippet } from 'svelte';
	import { t } from '#lib/i18n';
	import ActionGroup from './ActionGroup.svelte';
	import { iconButton } from './styles';

	let {
		open = $bindable(false),
		title,
		description,
		size = 'default',
		children,
		footer,
		class: className = ''
	}: {
		open?: boolean;
		title: string;
		description?: string;
		/**
		 * `default` is a compact centered dialog, `wide` is the standard size for routine forms and reading.
		 * `fullscreen` fills the viewport minus a small inset on desktop and the whole
		 * viewport on phones, with a fixed header, scrolling body and sticky footer.
		 * Reserve it for large editing or review workspaces that benefit from extra room.
		 */
		size?: 'default' | 'wide' | 'fullscreen';
		children: Snippet;
		/** Actions pinned below the scrolling body. Submit buttons can target a form with `form="id"`. */
		footer?: Snippet;
		class?: string;
	} = $props();

	const id = $props.id();
	const titleId = `${id}-title`;
	const descriptionId = `${id}-description`;
	let dialog: HTMLDialogElement;
	let returnFocus: HTMLElement | null = null;

	$effect(() => {
		if (!dialog) return;
		if (open && !dialog.open) {
			returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
			dialog.showModal();
		}
		if (!open && dialog.open) dialog.close();
	});

	function close() {
		open = false;
	}

	function restoreFocus() {
		close();
		const target = returnFocus;
		returnFocus = null;
		// Browsers usually restore focus themselves; this covers triggers re-rendered while open.
		if (target?.isConnected && document.activeElement === document.body) target.focus();
	}

	function handleBackdropClick(event: MouseEvent) {
		// Fullscreen forms ignore clicks on the thin backdrop margin so typed input is not lost by accident.
		if (size !== 'fullscreen' && event.target === dialog) close();
	}

	const fullscreen = $derived(size === 'fullscreen');
	const sizes = {
		default: 'w-[min(100%-2rem,48rem)] max-h-[min(90dvh,52rem)] overflow-hidden',
		wide: 'w-[min(100%-2rem,56rem)] max-h-[min(90dvh,52rem)] overflow-hidden',
		fullscreen: 'h-[calc(100dvh-3rem)] max-h-none w-[calc(100%-3rem)] max-w-[96rem] overflow-hidden max-sm:m-0! max-sm:h-dvh max-sm:w-full max-sm:rounded-none max-sm:border-0'
	};
</script>

<dialog
	bind:this={dialog}
	data-size={size}
	aria-labelledby={titleId}
	aria-describedby={description ? descriptionId : undefined}
	class="m-auto! max-w-none rounded-[24px] border glass-chrome p-0 text-ink backdrop:bg-overlay {sizes[size]} {className}"
	onclose={restoreFocus}
	onclick={handleBackdropClick}
>
	<div class="flex min-h-0 flex-col {fullscreen ? 'h-full' : 'max-h-[inherit]'}">
		<header class="flex shrink-0 items-start justify-between gap-4 border-b border-line px-6 py-4 max-sm:px-4">
			<div class="min-w-0 [overflow-wrap:anywhere]">
				<h2 id={titleId} class="text-xl leading-7 font-semibold text-ink">{title}</h2>
				{#if description}<p id={descriptionId} class="mt-1 text-sm leading-6 text-ink-muted">{description}</p>{/if}
			</div>
			<button type="button" aria-label={$t('Close dialog')} onclick={close} class={iconButton}>
				<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" /></svg>
			</button>
		</header>
		<div class="min-h-0 flex-auto overflow-y-auto overscroll-contain p-6 max-sm:p-4">
			{@render children()}
		</div>
		{#if footer}
			<footer class="shrink-0 border-t border-line bg-surface px-6 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))] max-sm:px-4">
				<ActionGroup class="justify-end">{@render footer()}</ActionGroup>
			</footer>
		{/if}
	</div>
</dialog>
