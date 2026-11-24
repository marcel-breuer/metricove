<script lang="ts">
	import { AlertDialogCancel } from '#lib/components/ui/alert-dialog/index.js';
	import Modal from './Modal.svelte';
	import { t } from '#lib/i18n';
	import Button from './Button.svelte';

	let {
		open = $bindable(false),
		title,
		message,
		confirmLabel = 'Delete',
		busy = false,
		onConfirm
	}: {
		open?: boolean;
		title: string;
		message: string;
		confirmLabel?: string;
		busy?: boolean;
		onConfirm: () => void | Promise<void>;
	} = $props();
</script>

<Modal bind:open {title} confirmation={message}>
	{#snippet footer()}
		<AlertDialogCancel variant="secondary" type="button" disabled={busy}>{$t('Cancel')}</AlertDialogCancel>
		<Button variant="danger" type="button" disabled={busy} onclick={() => void onConfirm()}>{busy ? $t('Working…') : $t(confirmLabel)}</Button>
	{/snippet}
</Modal>
