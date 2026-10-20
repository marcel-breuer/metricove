<script lang="ts">
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { formatNumber } from '#lib/number-format';
	import type { PantryExpiryStatus, PantryItem, PantryMovement, PantryResponse } from '#lib/pantry';

	type ExpiryFilter = 'all' | 'expired' | 'soon' | 'none';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'pantry'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreate = $derived(access?.capabilities.create ?? false);
	const canManageShared = $derived(shell.currentUser?.is_admin === true && (access?.capabilities.manage ?? false));
	const canAdd = $derived(canCreate || canManageShared);
	const expiryFilters: { key: ExpiryFilter; label: string }[] = [
		{ key: 'all', label: 'All stock' },
		{ key: 'soon', label: 'Expiring soon' },
		{ key: 'expired', label: 'Expired' },
		{ key: 'none', label: 'No date' }
	];

	let items = $state<PantryItem[]>([]);
	let loading = $state(true);
	let saving = $state(false);
	let error = $state('');
	let message = $state('');
	let filter = $state<ExpiryFilter>('all');
	let editorOpen = $state(false);
	let editingItem = $state<PantryItem | null>(null);
	let name = $state('');
	let quantity = $state('');
	let unit = $state('pcs');
	let location = $state('');
	let bestBefore = $state('');
	let visibility = $state<'private' | 'household'>('private');
	let adjustmentItem = $state<PantryItem | null>(null);
	let adjustment = $state('');
	let adjustmentNote = $state('');
	let historyItemId = $state<number | null>(null);
	let history = $state<PantryMovement[]>([]);
	let historyLoading = $state(false);

	const expiringSoon = $derived(items.filter((item) => item.expiry_status === 'today' || item.expiry_status === 'soon').length);
	const expired = $derived(items.filter((item) => item.expiry_status === 'expired').length);
	const totalStockLines = $derived(items.length);

	$effect(() => {
		if (!shell.loading && canView) void loadItems();
	});

	async function api<T extends PantryResponse>(path: string, method = 'GET', body?: object): Promise<T> {
		const headers: Record<string, string> = { Accept: 'application/json' };
		if (method !== 'GET') {
			const token = csrfToken();
			if (!token) throw new Error('Reload this page and try again.');
			headers['X-XSRF-TOKEN'] = token;
			if (body) headers['Content-Type'] = 'application/json';
		}
		const response = await fetch(path, {
			method,
			credentials: 'same-origin',
			headers,
			...(body ? { body: JSON.stringify(body) } : {})
		});
		if (response.status === 401) {
			await goto('/login');
			throw new Error('Your session has expired.');
		}
		const result = (await response.json()) as T & ApiResponse;
		if (!response.ok) throw new Error(firstError(result) || result.message || 'The pantry could not complete that request.');
		return result as T;
	}

	async function loadItems(): Promise<void> {
		loading = true;
		error = '';
		try {
			const query = filter === 'all' ? '' : `?expiry=${filter}`;
			const result = await api(`/api/pantry${query}`);
			items = result.items ?? [];
			if (historyItemId !== null && !items.some((item) => item.id === historyItemId)) {
				historyItemId = null;
				history = [];
			}
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The pantry could not be loaded.';
		} finally {
			loading = false;
		}
	}

	function openNewItem(): void {
		editingItem = null;
		name = '';
		quantity = '';
		unit = 'pcs';
		location = '';
		bestBefore = '';
		visibility = canCreate ? 'private' : 'household';
		error = '';
		message = '';
		editorOpen = true;
	}

	function openEdit(item: PantryItem): void {
		editingItem = item;
		name = item.name;
		location = item.location ?? '';
		bestBefore = item.best_before_on ?? '';
		error = '';
		message = '';
		editorOpen = true;
	}

	function closeEditor(): void {
		editorOpen = false;
		editingItem = null;
	}

	async function saveItem(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		error = '';
		message = '';
		const body = editingItem
			? {
				expected_version: editingItem.version,
				name,
				location: location || null,
				best_before_on: bestBefore || null
			}
			: {
				name,
				quantity,
				unit,
				location: location || null,
				best_before_on: bestBefore || null,
				visibility
			};
		saving = true;
		try {
			await api(editingItem ? `/api/pantry/${editingItem.id}` : '/api/pantry', editingItem ? 'PATCH' : 'POST', body);
			message = editingItem ? 'Pantry details updated.' : 'Pantry item added.';
			closeEditor();
			await loadItems();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The pantry item could not be saved.';
			if (error.includes('changed since it was loaded')) await loadItems();
		} finally {
			saving = false;
		}
	}

	function openAdjustment(item: PantryItem): void {
		adjustmentItem = item;
		adjustment = '';
		adjustmentNote = '';
		error = '';
		message = '';
	}

	async function saveAdjustment(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!adjustmentItem) return;
		error = '';
		message = '';
		saving = true;
		try {
			await api(`/api/pantry/${adjustmentItem.id}/adjustments`, 'POST', {
				expected_version: adjustmentItem.version,
				quantity_delta: adjustment,
				note: adjustmentNote || null
			});
			message = 'Stock adjustment recorded.';
			adjustmentItem = null;
			await loadItems();
			if (historyItemId !== null) await loadHistory(historyItemId);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The stock adjustment could not be saved.';
			if (error.includes('changed since it was loaded')) await loadItems();
		} finally {
			saving = false;
		}
	}

	async function toggleHistory(item: PantryItem): Promise<void> {
		if (historyItemId === item.id) {
			historyItemId = null;
			history = [];
			return;
		}
		await loadHistory(item.id);
	}

	async function loadHistory(itemId: number): Promise<void> {
		historyItemId = itemId;
		historyLoading = true;
		try {
			const result = await api(`/api/pantry/${itemId}/movements`);
			history = result.movements ?? [];
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Movement history could not be loaded.';
		} finally {
			historyLoading = false;
		}
	}

	function setFilter(value: ExpiryFilter): void {
		filter = value;
		void loadItems();
	}

	function expiryLabel(status: PantryExpiryStatus, date: string | null): string {
		if (!date || status === 'none') return 'No best-before date';
		if (status === 'expired') return `Expired · ${formatDate(date)}`;
		if (status === 'today') return `Best before today · ${formatDate(date)}`;
		if (status === 'soon') return `Best before ${formatDate(date)}`;
		return `Best before ${formatDate(date)}`;
	}

	function expiryTone(status: PantryExpiryStatus): string {
		if (status === 'expired') return 'border-rose-300/25 bg-rose-400/10 text-rose-100';
		if (status === 'today' || status === 'soon') return 'border-amber-300/25 bg-amber-300/10 text-amber-100';
		return 'border-white/10 bg-white/[0.04] text-slate-300';
	}

	function formatDate(value: string): string {
		return new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { dateStyle: 'medium' });
	}

	function formatTimestamp(value: string | null): string {
		if (!value) return 'Just now';
		return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
	}
</script>

<svelte:head>
	<title>Pantry · Metricove</title>
	<meta name="description" content="Track food stock, storage, quantities and best-before dates." />
</svelte:head>

<div class="mx-auto max-w-6xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
	<header class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
		<div>
			<p class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-300">Food · Stock</p>
			<h1 class="mt-3 text-3xl font-semibold tracking-tight text-white sm:text-4xl">Pantry</h1>
			<p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">Keep quantities and best-before dates together. Each stock change is recorded with its own unit and history.</p>
		</div>
		{#if canAdd}
			<button type="button" onclick={openNewItem} class="rounded-xl bg-emerald-300 px-4 py-3 text-sm font-semibold text-emerald-950 shadow-lg shadow-emerald-950/20 transition hover:bg-emerald-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-200">Add pantry item</button>
		{/if}
	</header>

	{#if message}
		<p role="status" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">{message}</p>
	{/if}
	{#if error}
		<p role="alert" class="rounded-xl border border-rose-300/20 bg-rose-300/10 px-4 py-3 text-sm text-rose-100">{error}</p>
	{/if}

	{#if !shell.loading && !canView}
		<section class="rounded-2xl border border-white/10 bg-slate-900/70 p-6 text-sm text-slate-300">Pantry access is not enabled for your account.</section>
	{:else}
		<section class="grid gap-3 sm:grid-cols-3" aria-label="Pantry summary">
			<div class="rounded-2xl border border-white/10 bg-slate-900/70 p-5">
				<p class="text-sm text-slate-400">Stock lines</p>
				<p class="mt-2 text-3xl font-semibold text-white">{loading ? '—' : totalStockLines}</p>
			</div>
			<div class="rounded-2xl border border-amber-300/15 bg-amber-300/[0.06] p-5">
				<p class="text-sm text-amber-100/70">Expiring in 7 days</p>
				<p class="mt-2 text-3xl font-semibold text-amber-100">{loading ? '—' : expiringSoon}</p>
			</div>
			<div class="rounded-2xl border border-rose-300/15 bg-rose-300/[0.05] p-5">
				<p class="text-sm text-rose-100/70">Past best-before</p>
				<p class="mt-2 text-3xl font-semibold text-rose-100">{loading ? '—' : expired}</p>
			</div>
		</section>

		{#if editorOpen}
			<section class="rounded-2xl border border-emerald-200/20 bg-slate-900/90 p-5 shadow-xl shadow-black/10 sm:p-6" aria-labelledby="pantry-editor-title">
				<div class="mb-5 flex items-start justify-between gap-4">
					<div>
						<p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-300">{editingItem ? 'Item details' : 'New stock line'}</p>
						<h2 id="pantry-editor-title" class="mt-1 text-xl font-semibold text-white">{editingItem ? `Edit ${editingItem.name}` : 'Add to pantry'}</h2>
					</div>
					<button type="button" onclick={closeEditor} class="rounded-lg px-3 py-2 text-sm text-slate-400 hover:bg-white/5 hover:text-white">Close</button>
				</div>
				<form onsubmit={saveItem} class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
					<label class="space-y-2 text-sm text-slate-300">
						<span>Food or item name</span>
						<input bind:value={name} required maxlength="180" class="w-full rounded-xl border border-white/10 bg-slate-950/80 px-3 py-3 text-white outline-none focus:border-emerald-300/50" placeholder="Oat milk" />
					</label>
					{#if !editingItem}
						<label class="space-y-2 text-sm text-slate-300">
							<span>Starting quantity</span>
							<input bind:value={quantity} required inputmode="decimal" class="w-full rounded-xl border border-white/10 bg-slate-950/80 px-3 py-3 text-white outline-none focus:border-emerald-300/50" placeholder="1.000" />
						</label>
						<label class="space-y-2 text-sm text-slate-300">
							<span>Unit</span>
							<input bind:value={unit} required maxlength="32" class="w-full rounded-xl border border-white/10 bg-slate-950/80 px-3 py-3 text-white outline-none focus:border-emerald-300/50" placeholder="pcs, g, ml" />
						</label>
					{:else}
						<div class="flex items-end pb-3 text-sm text-slate-400">Quantity and unit: <span class="ml-1 text-white">{formatNumber(editingItem.quantity)} {editingItem.unit}</span></div>
					{/if}
					<label class="space-y-2 text-sm text-slate-300">
						<span>Storage location <span class="text-slate-500">(optional)</span></span>
						<input bind:value={location} maxlength="120" class="w-full rounded-xl border border-white/10 bg-slate-950/80 px-3 py-3 text-white outline-none focus:border-emerald-300/50" placeholder="Fridge, pantry shelf" />
					</label>
					<label class="space-y-2 text-sm text-slate-300">
						<span>Best-before date <span class="text-slate-500">(optional)</span></span>
						<input bind:value={bestBefore} type="date" class="w-full rounded-xl border border-white/10 bg-slate-950/80 px-3 py-3 text-white outline-none [color-scheme:dark] focus:border-emerald-300/50" />
					</label>
					{#if !editingItem && canManageShared}
						<label class="space-y-2 text-sm text-slate-300">
							<span>Visibility</span>
							<select bind:value={visibility} class="w-full rounded-xl border border-white/10 bg-slate-950/80 px-3 py-3 text-white outline-none focus:border-emerald-300/50">
								<option value="private">Private to me</option>
								<option value="household">Shared with household</option>
							</select>
						</label>
					{/if}
					<div class="flex items-end gap-3 sm:col-span-2 lg:col-span-3">
						<button disabled={saving} class="rounded-xl bg-emerald-300 px-4 py-3 text-sm font-semibold text-emerald-950 disabled:opacity-50">{saving ? 'Saving…' : editingItem ? 'Save details' : 'Add item'}</button>
						<p class="text-xs leading-5 text-slate-500">Units stay explicit and are never converted automatically.</p>
					</div>
				</form>
			</section>
		{/if}

		<section class="space-y-4" aria-labelledby="stock-list-title">
			<div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
				<div>
					<p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Inventory</p>
					<h2 id="stock-list-title" class="mt-1 text-xl font-semibold text-white">Your stock</h2>
				</div>
				<div class="flex flex-wrap gap-2" role="group" aria-label="Filter pantry items by best-before date">
					{#each expiryFilters as option}
						<button type="button" aria-pressed={filter === option.key} onclick={() => setFilter(option.key)} class="rounded-full border px-3 py-2 text-xs font-medium transition {filter === option.key ? 'border-emerald-300/40 bg-emerald-300/10 text-emerald-100' : 'border-white/10 bg-white/[0.03] text-slate-400 hover:text-white'}">{option.label}</button>
					{/each}
				</div>
			</div>

			{#if loading}
				<div class="rounded-2xl border border-white/10 bg-slate-900/60 p-8 text-center text-sm text-slate-400">Loading pantry…</div>
			{:else if items.length === 0}
				<div class="rounded-2xl border border-dashed border-white/15 bg-slate-900/40 px-6 py-12 text-center">
					<p class="text-base font-medium text-white">{filter === 'all' ? 'Nothing in the pantry yet' : 'No items match this date filter'}</p>
					<p class="mt-2 text-sm text-slate-400">{filter === 'all' ? 'Add a food item to keep its quantity, location and best-before date in one place.' : 'Try another best-before filter to see more of your stock.'}</p>
					{#if filter === 'all' && canAdd}<button type="button" onclick={openNewItem} class="mt-5 rounded-xl border border-emerald-300/25 px-4 py-2.5 text-sm font-medium text-emerald-100 hover:bg-emerald-300/10">Add first item</button>{/if}
				</div>
			{:else}
				<div class="grid gap-3 lg:grid-cols-2">
					{#each items as item (item.id)}
						<article class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900/70">
							<div class="p-5">
								<div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
									<div class="min-w-0">
										<div class="flex flex-wrap items-center gap-2">
											<h3 class="truncate text-lg font-semibold text-white">{item.name}</h3>
											<span class="rounded-full border px-2.5 py-1 text-[11px] font-medium {item.visibility === 'household' ? 'border-sky-300/20 bg-sky-300/10 text-sky-100' : 'border-white/10 bg-white/[0.04] text-slate-400'}">{item.visibility === 'household' ? 'Household' : 'Private'}</span>
										</div>
										<p class="mt-2 text-2xl font-semibold tracking-tight text-emerald-100">{formatNumber(item.quantity)} <span class="text-base font-medium text-emerald-100/70">{item.unit}</span></p>
										<div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-xs text-slate-400">
											{#if item.location}<span>Stored in {item.location}</span>{/if}
											<span class="rounded-full border px-2 py-1 {expiryTone(item.expiry_status)}">{expiryLabel(item.expiry_status, item.best_before_on)}</span>
										</div>
									</div>
									<div class="flex flex-wrap gap-2 sm:justify-end">
										{#if item.can_edit}
											<button type="button" onclick={() => openAdjustment(item)} class="rounded-lg border border-emerald-300/20 px-3 py-2 text-xs font-semibold text-emerald-100 hover:bg-emerald-300/10">Adjust stock</button>
											<button type="button" onclick={() => openEdit(item)} class="rounded-lg border border-white/10 px-3 py-2 text-xs font-semibold text-slate-300 hover:bg-white/5">Edit</button>
										{/if}
										<button type="button" aria-expanded={historyItemId === item.id} onclick={() => void toggleHistory(item)} class="rounded-lg border border-white/10 px-3 py-2 text-xs font-semibold text-slate-300 hover:bg-white/5">{historyItemId === item.id ? 'Hide history' : 'History'}</button>
									</div>
								</div>
								{#if adjustmentItem?.id === item.id}
									<form onsubmit={saveAdjustment} class="mt-5 grid gap-3 rounded-xl border border-emerald-300/15 bg-emerald-300/[0.04] p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
										<label class="space-y-2 text-xs text-slate-300"><span>Change in {item.unit} <span class="text-slate-500">(use minus to remove)</span></span><input bind:value={adjustment} required inputmode="decimal" class="w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-300/50" placeholder="0.500 or -1" /></label>
										<label class="space-y-2 text-xs text-slate-300"><span>Note <span class="text-slate-500">(optional)</span></span><input bind:value={adjustmentNote} maxlength="500" class="w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-300/50" placeholder="Used for dinner" /></label>
										<div class="flex gap-2"><button disabled={saving} class="rounded-lg bg-emerald-300 px-3 py-2.5 text-xs font-semibold text-emerald-950 disabled:opacity-50">Save</button><button type="button" onclick={() => (adjustmentItem = null)} class="rounded-lg border border-white/10 px-3 py-2.5 text-xs text-slate-300">Cancel</button></div>
									</form>
								{/if}
								{#if historyItemId === item.id}
									<div class="mt-5 border-t border-white/10 pt-4">
										<h4 class="text-sm font-semibold text-white">Stock history</h4>
										{#if historyLoading}
											<p class="mt-3 text-xs text-slate-400">Loading movements…</p>
										{:else if history.length === 0}
											<p class="mt-3 text-xs text-slate-400">No stock movements recorded.</p>
										{:else}
											<ol class="mt-3 space-y-3">
												{#each history as movement (movement.id)}
													<li class="flex items-start justify-between gap-4 text-xs">
														<div><p class="font-medium text-slate-200">{movement.type === 'initial' ? 'Initial stock' : 'Manual adjustment'} · {formatNumber(movement.quantity_delta)} {movement.unit}</p>{#if movement.note}<p class="mt-1 text-slate-500">{movement.note}</p>{/if}<p class="mt-1 text-slate-500">{formatNumber(movement.quantity_before)} → {formatNumber(movement.quantity_after)} {movement.unit}</p></div>
														<time class="shrink-0 text-right text-slate-500">{formatTimestamp(movement.created_at)}</time>
													</li>
												{/each}
											</ol>
										{/if}
									</div>
								{/if}
							</div>
						</article>
					{/each}
				</div>
			{/if}
		</section>
	{/if}
</div>
