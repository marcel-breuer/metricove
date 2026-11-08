<script lang="ts">
	import { control, field, fieldLabel } from '#lib/ui/styles';
	import Notice from '#lib/ui/Notice.svelte';
	import Button from '#lib/ui/Button.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import { t } from '#lib/i18n';
	import { formatDate as formatLocaleDate, formatDateTime } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import Modal from '#lib/ui/Modal.svelte';
	import { formatMeasurement } from '#lib/number-format';
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
	let adjustmentOpen = $state(false);
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
		adjustmentOpen = true;
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
			adjustmentOpen = false;
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
		if (status === 'expired') return 'border-danger/25 bg-danger/10 text-danger';
		if (status === 'today' || status === 'soon') return 'border-warning/25 bg-warning/10 text-warning';
		return 'border-line bg-surface/[0.04] text-ink-secondary';
	}

	function formatDate(value: string): string {
		return formatLocaleDate(`${value}T00:00:00`, { dateStyle: 'medium' });
	}

	function formatTimestamp(value: string | null): string {
		if (!value) return 'Just now';
		return formatDateTime(value);
	}
</script>

<svelte:head>
	<title>{$t("Pantry")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Track food stock, storage, quantities and best-before dates.")} />
</svelte:head>

<div class="mx-auto max-w-6xl space-y-8">
	<PageHeading eyebrow="Food · Stock" title="Pantry" description="Keep quantities and best-before dates together. Each stock change is recorded with its own unit and history.">
		{#snippet actions()}
			{#if canAdd}<Button onclick={openNewItem}>{$t("Add pantry item")}</Button>{/if}
		{/snippet}
	</PageHeading>

	{#if message}
		<Notice tone="success" message={message} />
	{/if}
	{#if error}
		<Notice tone="error" message={error} />
	{/if}

	{#if !shell.loading && !canView}
		<section class="rounded-2xl border border-line bg-surface p-6 text-sm text-ink-secondary">{$t("Pantry access is not enabled for your account.")}</section>
	{:else}
		<section class="grid gap-3 sm:grid-cols-3" aria-label={$t("Pantry summary")}>
			<div class="rounded-2xl border border-line bg-surface p-5">
				<p class="text-sm text-ink-muted">{$t("Stock lines")}</p>
				<p class="mt-2 text-3xl font-semibold text-ink">{loading ? '—' : totalStockLines}</p>
			</div>
			<div class="rounded-2xl border border-warning/15 bg-warning/[0.06] p-5">
				<p class="text-sm text-warning/70">{$t("Expiring in 7 days")}</p>
				<p class="mt-2 text-3xl font-semibold text-warning">{loading ? '—' : expiringSoon}</p>
			</div>
			<div class="rounded-2xl border border-danger/15 bg-danger/[0.05] p-5">
				<p class="text-sm text-danger/70">{$t("Past best-before")}</p>
				<p class="mt-2 text-3xl font-semibold text-danger">{loading ? '—' : expired}</p>
			</div>
		</section>

		<Modal bind:open={editorOpen} size="fullscreen" title={$t(editingItem ? 'Edit pantry item' : 'Add to pantry')} description={editingItem ? editingItem.name : $t('New stock line')}>
			{#if error}<Notice tone="error" message={error} class="mb-5" />{/if}
			<form id="pantry-item-form" onsubmit={saveItem} class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4">
				<label class="{field} sm:col-span-2">
					<span class="{fieldLabel}">{$t("Food or item name")}</span>
					<input bind:value={name} required maxlength="180" class="{control}" placeholder={$t("Oat milk")} />
				</label>
				{#if !editingItem}
					<label class="{field}">
						<span class="{fieldLabel}">{$t("Starting quantity")}</span>
						<input bind:value={quantity} required inputmode="decimal" class="{control}" placeholder="1.000" />
					</label>
					<label class="{field}">
						<span class="{fieldLabel}">{$t("Unit")}</span>
						<input bind:value={unit} required maxlength="32" class="{control}" placeholder={$t("pcs, g, ml")} />
					</label>
				{:else}
					<p class="self-end pb-3 text-sm text-ink-muted sm:col-span-2">{$t("Quantity and unit:")} <span class="ml-1 text-ink">{formatMeasurement(editingItem.quantity, editingItem.unit)} {editingItem.unit}</span></p>
				{/if}
				<label class="{field} sm:col-span-2">
					<span class="{fieldLabel}">{$t("Storage location")} <span class="font-normal text-ink-muted">{$t("(optional)")}</span></span>
					<input bind:value={location} maxlength="120" class="{control}" placeholder={$t("Fridge, pantry shelf")} />
				</label>
				<label class="{field}">
					<span class="{fieldLabel}">{$t("Best-before date")} <span class="font-normal text-ink-muted">{$t("(optional)")}</span></span>
					<input bind:value={bestBefore} type="date" class="{control}" />
				</label>
				{#if !editingItem && canManageShared}
					<label class="{field}">
						<span class="{fieldLabel}">{$t("Visibility")}</span>
						<select bind:value={visibility} class="{control}">
							<option value="private">{$t("Private to me")}</option>
							<option value="household">{$t("Shared with household")}</option>
						</select>
					</label>
				{/if}
			</form>
			<p class="mt-6 text-xs leading-5 text-ink-muted">{$t("Units stay explicit and are never converted automatically.")}</p>
			{#snippet footer()}
				<Button variant="secondary" type="button" disabled={saving} onclick={closeEditor}>{$t('Cancel')}</Button>
				<Button type="submit" form="pantry-item-form" disabled={saving}>{$t(saving ? 'Saving…' : editingItem ? 'Save details' : 'Add item')}</Button>
			{/snippet}
		</Modal>

		<section class="space-y-4" aria-labelledby="stock-list-title">
			<div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
				<div>
					<p class="text-xs font-semibold text-ink-subtle">{$t("Inventory")}</p>
					<h2 id="stock-list-title" class="mt-1 text-xl font-semibold text-ink">{$t("Your stock")}</h2>
				</div>
				<div class="flex flex-wrap gap-2" role="group" aria-label={$t("Filter pantry items by best-before date")}>
					{#each expiryFilters as option}
						<button type="button" aria-pressed={filter === option.key} onclick={() => setFilter(option.key)} class="rounded-full border px-3 py-2 text-xs font-medium transition {filter === option.key ? 'border-positive/40 bg-positive/10 text-positive' : 'border-line bg-surface/[0.03] text-ink-muted hover:text-ink'}">{option.label}</button>
					{/each}
				</div>
			</div>

			{#if loading}
				<div class="rounded-2xl border border-line bg-surface p-8 text-center text-sm text-ink-muted">{$t("Loading pantry…")}</div>
			{:else if items.length === 0}
				<div class="rounded-2xl border border-dashed border-line bg-surface px-6 py-12 text-center">
					<p class="text-base font-medium text-ink">{$t(filter === 'all' ? 'Nothing in the pantry yet' : 'No items match this date filter')}</p>
					<p class="mt-2 text-sm text-ink-muted">{$t(filter === 'all' ? 'Add a food item to keep its quantity, location and best-before date in one place.' : 'Try another best-before filter to see more of your stock.')}</p>
					{#if filter === 'all' && canAdd}<button type="button" onclick={openNewItem} class="mt-5 rounded-xl border border-positive/25 px-4 py-2.5 text-sm font-medium text-positive hover:bg-positive/10">{$t("Add first item")}</button>{/if}
				</div>
			{:else}
				<div class="grid gap-3 lg:grid-cols-2">
					{#each items as item (item.id)}
						<article class="overflow-hidden rounded-2xl border border-line bg-surface">
							<div class="p-5">
								<div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
									<div class="min-w-0">
										<div class="flex flex-wrap items-center gap-2">
											<h3 class="truncate text-lg font-semibold text-ink">{item.name}</h3>
											<span class="rounded-full border px-2.5 py-1 text-[11px] font-medium {item.visibility === 'household' ? 'border-accent-ink/20 bg-accent/10 text-accent-ink' : 'border-line bg-surface/[0.04] text-ink-muted'}">{$t(item.visibility === 'household' ? 'Household' : 'Private')}</span>
										</div>
										<p class="mt-2 text-2xl font-semibold tracking-tight text-positive">{formatMeasurement(item.quantity, item.unit)} <span class="text-base font-medium text-positive/70">{item.unit}</span></p>
										<div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-xs text-ink-muted">
											{#if item.location}<span>{$t("Stored in")} {item.location}</span>{/if}
											<span class="rounded-full border px-2 py-1 {expiryTone(item.expiry_status)}">{expiryLabel(item.expiry_status, item.best_before_on)}</span>
										</div>
									</div>
									<div class="flex flex-wrap gap-2 sm:justify-end">
										{#if item.can_edit}
											<button type="button" onclick={() => openAdjustment(item)} class="rounded-lg border border-positive/20 px-3 py-2 text-xs font-semibold text-positive hover:bg-positive/10">{$t("Adjust stock")}</button>
											<button type="button" onclick={() => openEdit(item)} class="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-ink-secondary hover:bg-surface-subtle">{$t("Edit")}</button>
										{/if}
										<button type="button" aria-expanded={historyItemId === item.id} onclick={() => void toggleHistory(item)} class="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-ink-secondary hover:bg-surface-subtle">{$t(historyItemId === item.id ? 'Hide history' : 'History')}</button>
									</div>
								</div>
				{#if adjustmentItem?.id === item.id}
					<Modal bind:open={adjustmentOpen} title={$t('Adjust stock')} description={item.name}>
									<form onsubmit={saveAdjustment} class="mt-5 grid gap-3 rounded-xl border border-positive/15 bg-positive/[0.04] p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
										<label class="space-y-2 text-xs text-ink-secondary"><span>{$t("Change in")} {item.unit} <span class="text-ink-subtle">{$t("(use minus to remove)")}</span></span><input bind:value={adjustment} required inputmode="decimal" class="w-full rounded-[10px] border border-line bg-page px-3 py-2.5 text-sm text-ink outline-none focus:border-positive/50 max-sm:text-base" placeholder={$t("0.500 or -1")} /></label>
										<label class="space-y-2 text-xs text-ink-secondary"><span>{$t("Note")} <span class="text-ink-subtle">{$t("(optional)")}</span></span><input bind:value={adjustmentNote} maxlength="500" class="w-full rounded-[10px] border border-line bg-page px-3 py-2.5 text-sm text-ink outline-none focus:border-positive/50 max-sm:text-base" placeholder={$t("Used for dinner")} /></label>
						<div class="flex gap-2"><button disabled={saving} class="rounded-lg bg-positive px-3 py-2.5 text-xs font-semibold text-on-accent disabled:opacity-50">{$t("Save")}</button><button type="button" onclick={() => { adjustmentItem = null; adjustmentOpen = false; }} class="rounded-lg border border-line px-3 py-2.5 text-xs text-ink-secondary">{$t("Cancel")}</button></div>
									</form>
					</Modal>
								{/if}
								{#if historyItemId === item.id}
									<div class="mt-5 border-t border-line pt-4">
										<h4 class="text-sm font-semibold text-ink">{$t("Stock history")}</h4>
										{#if historyLoading}
											<p class="mt-3 text-xs text-ink-muted">{$t("Loading movements…")}</p>
										{:else if history.length === 0}
											<p class="mt-3 text-xs text-ink-muted">{$t("No stock movements recorded.")}</p>
										{:else}
											<ol class="mt-3 space-y-3">
												{#each history as movement (movement.id)}
													<li class="flex items-start justify-between gap-4 text-xs">
														<div><p class="font-medium text-ink-secondary">{$t(movement.type === 'initial' ? 'Initial stock' : 'Manual adjustment')} · {formatMeasurement(movement.quantity_delta, movement.unit)} {movement.unit}</p>{#if movement.note}<p class="mt-1 text-ink-subtle">{movement.note}</p>{/if}<p class="mt-1 text-ink-subtle">{formatMeasurement(movement.quantity_before, movement.unit)} → {formatMeasurement(movement.quantity_after, movement.unit)} {movement.unit}</p></div>
														<time class="shrink-0 text-right text-ink-subtle">{formatTimestamp(movement.created_at)}</time>
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
