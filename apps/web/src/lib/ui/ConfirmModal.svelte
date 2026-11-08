<script lang="ts">
	import Modal from './Modal.svelte';
	import { t } from '#lib/i18n';
	import Button from './Button.svelte';
	import { modalActions } from './styles';

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

<Modal bind:open {title}>
	<p class="text-sm leading-6 text-ink-secondary">{message}</p>
	<div class={modalActions}>
		<Button variant="secondary" type="button" disabled={busy} onclick={() => (open = false)}>{$t('Cancel')}</Button>
		<Button variant="danger" type="button" disabled={busy} onclick={() => void onConfirm()}>{busy ? $t('Working…') : $t(confirmLabel)}</Button>
	</div>
</Modal>
