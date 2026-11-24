<script lang="ts">
	import type { Snippet } from 'svelte';
	import * as AlertDialog from '#lib/components/ui/alert-dialog/index.js';
	import * as Dialog from '#lib/components/ui/dialog/index.js';
	import { t } from '#lib/i18n';
	import { cn } from '#lib/utils.js';
	import ActionGroup from './ActionGroup.svelte';
	import { iconButton } from './styles';

	type Props = {
		open?: boolean;
		title: string;
		description?: string;
		/** Fullscreen fills the viewport on phones and leaves a small inset on desktop. */
		size?: 'default' | 'wide' | 'fullscreen';
		children?: Snippet;
		/** Actions below the scrolling body. Submit buttons can target a form by ID. */
		footer?: Snippet;
		class?: string;
		/** Announce the message as an alert dialog for confirmation flows. */
		confirmation?: string;
	};

	let {
		open = $bindable(false),
		title,
		description,
		size = 'default',
		children,
		footer,
		class: className = '',
		confirmation
	}: Props = $props();

	const sizes = {
		default: 'w-[min(100%-2rem,48rem)] max-h-[min(90dvh,52rem)]',
		wide: 'w-[min(100%-2rem,56rem)] max-h-[min(90dvh,52rem)]',
		fullscreen: 'h-[calc(100dvh-3rem)] w-[calc(100%-3rem)] max-w-[96rem] max-sm:h-dvh max-sm:w-full max-sm:rounded-none! max-sm:border-0'
	};
	const fullscreen = $derived(size === 'fullscreen');
	const classes = $derived(cn('flex min-h-0 flex-col overflow-hidden', sizes[size], className));
</script>

{#snippet frame(header: Snippet, content?: Snippet)}
	<header class="flex shrink-0 items-start justify-between gap-4 border-b border-line px-6 py-4 max-sm:px-4">
		<div class="min-w-0 [overflow-wrap:anywhere]">
			{@render header()}
		</div>
		{#if confirmation === undefined}
			<Dialog.Close aria-label={$t('Close dialog')} class={iconButton}>
				{@render closeIcon()}
			</Dialog.Close>
		{:else}
			<AlertDialog.Cancel type="button" variant="ghost" size="icon" aria-label={$t('Close dialog')} class={iconButton}>
				{@render closeIcon()}
			</AlertDialog.Cancel>
		{/if}
	</header>
	<div class="min-h-0 flex-auto overflow-y-auto overscroll-contain p-6 max-sm:p-4">
		{@render content?.()}
	</div>
	{#if footer}
		<footer class="shrink-0 border-t border-line bg-surface px-6 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))] max-sm:px-4">
			<ActionGroup class="justify-end">{@render footer()}</ActionGroup>
		</footer>
	{/if}
{/snippet}

{#snippet closeIcon()}
	<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" /></svg>
{/snippet}

{#snippet alertHeading()}
	<AlertDialog.Title level={2} class="text-xl leading-7 font-semibold text-ink">{title}</AlertDialog.Title>
{/snippet}

{#snippet alertMessage()}
	<AlertDialog.Description class="text-sm leading-6 text-ink-secondary">{confirmation}</AlertDialog.Description>
{/snippet}

{#snippet dialogHeading()}
	<Dialog.Title level={2} class="text-xl leading-7 font-semibold text-ink">{title}</Dialog.Title>
	{#if description}<Dialog.Description class="mt-1 text-sm leading-6 text-ink-muted">{description}</Dialog.Description>{/if}
{/snippet}

{#if confirmation !== undefined}
	<AlertDialog.Root bind:open>
		<AlertDialog.Content variant="glass" class={classes} interactOutsideBehavior="close">
			{@render frame(alertHeading, alertMessage)}
		</AlertDialog.Content>
	</AlertDialog.Root>
{:else}
	<Dialog.Root bind:open>
		<Dialog.Content
			variant="glass"
			showCloseButton={false}
			data-size={size}
			class={classes}
			onInteractOutside={(event) => { if (fullscreen) event.preventDefault(); }}
		>
			{@render frame(dialogHeading, children)}
		</Dialog.Content>
	</Dialog.Root>
{/if}
