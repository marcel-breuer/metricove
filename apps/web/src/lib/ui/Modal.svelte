<script lang="ts">
	import type { Snippet } from 'svelte';
	import { t } from '#lib/i18n';

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
		 * `default` is a compact centered dialog (bottom sheet on phones).
		 * `fullscreen` fills the viewport minus a small inset on desktop and the whole
		 * viewport on phones, with a fixed header, scrolling body and sticky footer.
		 * Use it for create/edit forms so long forms need less scrolling.
		 */
		size?: 'default' | 'fullscreen';
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
</script>

<dialog
	bind:this={dialog}
	aria-labelledby={titleId}
	aria-describedby={description ? descriptionId : undefined}
	class="ui-modal ui-modal--{size} {className}"
	onclose={restoreFocus}
	onclick={handleBackdropClick}
>
	<div class="ui-modal-body">
		<header class="ui-modal-header">
			<div class="min-w-0">
				<h2 id={titleId} class="ui-modal-title">{title}</h2>
				{#if description}<p id={descriptionId} class="ui-modal-description">{description}</p>{/if}
			</div>
			<button type="button" aria-label={$t('Close dialog')} onclick={close} class="ui-icon-button">
				<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" /></svg>
			</button>
		</header>
		<div class="ui-modal-content">
			{@render children()}
		</div>
		{#if footer}<footer class="ui-modal-footer">{@render footer()}</footer>{/if}
	</div>
</dialog>
