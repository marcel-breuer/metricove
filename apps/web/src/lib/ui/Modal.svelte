<script lang="ts">
	import type { Snippet } from 'svelte';
	import { t } from '#lib/i18n';

	let {
		open = $bindable(false),
		title,
		description,
		children,
		class: className = ''
	}: {
		open?: boolean;
		title: string;
		description?: string;
		children: Snippet;
		class?: string;
	} = $props();

	let dialog: HTMLDialogElement;

	$effect(() => {
		if (!dialog) return;
		if (open && !dialog.open) dialog.showModal();
		if (!open && dialog.open) dialog.close();
	});

	function close() {
		open = false;
	}
</script>

<dialog
	bind:this={dialog}
	aria-label={title}
	class="ui-modal {className}"
	onclose={close}
	onclick={(event) => { if (event.target === dialog) close(); }}
>
	<div class="ui-modal-body">
		<header class="ui-modal-header">
			<div class="min-w-0">
				<h2 class="ui-modal-title">{title}</h2>
				{#if description}<p class="ui-modal-description">{description}</p>{/if}
			</div>
			<button type="button" aria-label={$t('Close dialog')} onclick={close} class="ui-icon-button">
				<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" /></svg>
			</button>
		</header>
		{@render children()}
	</div>
</dialog>
