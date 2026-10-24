<script lang="ts">
	import type { Snippet } from 'svelte';

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
	class="m-auto max-h-[min(90dvh,52rem)] w-[min(100%-2rem,48rem)] max-w-none overflow-y-auto rounded-2xl border border-slate-700 bg-slate-950 p-0 text-slate-100 shadow-2xl backdrop:bg-slate-950/75 backdrop:backdrop-blur-sm {className}"
	onclose={close}
	onclick={(event) => { if (event.target === dialog) close(); }}
>
	<div class="p-5 sm:p-7">
		<header class="mb-5 flex items-start justify-between gap-4">
			<div>
				<h2 class="text-xl font-semibold text-white">{title}</h2>
				{#if description}<p class="mt-1 text-sm leading-6 text-slate-400">{description}</p>{/if}
			</div>
			<button type="button" aria-label="Close dialog" onclick={close} class="grid size-10 shrink-0 place-items-center rounded-lg border border-slate-700 text-xl text-slate-300 transition hover:border-slate-500 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">×</button>
		</header>
		{@render children()}
	</div>
</dialog>
