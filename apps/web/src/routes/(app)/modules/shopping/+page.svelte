<script lang="ts">
	import Notice from '#lib/ui/Notice.svelte';
	import Badge from '#lib/ui/Badge.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import { t, visibilityLabel } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';
	import Modal from '#lib/ui/Modal.svelte';
	import { canonicalizeDecimalInput, formatDecimalInput, formatMeasurement } from '#lib/number-format';
	import { formatDate as formatLocaleDate, numberLocale } from '#lib/i18n';
	import type {
		MealSuggestionPreview,
		MealSuggestionsResponse,
		ShoppingActivity,
		ShoppingItem,
		ShoppingList,
		ShoppingListsResponse
	} from '#lib/shopping';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'shopping'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreate = $derived(access?.capabilities.create ?? false);
	const canManageShared = $derived(shell.currentUser?.is_admin === true && (access?.capabilities.manage ?? false));
	const canCreateList = $derived(canCreate || canManageShared);

	let lists = $state<ShoppingList[]>([]);
	let activeListId = $state<number | null>(null);
	let activeList = $state<ShoppingList | null>(null);
	let items = $state<ShoppingItem[]>([]);
	let activity = $state<ShoppingActivity[]>([]);
	let loading = $state(true);
	let listLoading = $state(false);
	let saving = $state(false);
	let error = $state('');
	let message = $state('');
	let listName = $state('');
	let visibility = $state<'private' | 'household'>('private');
	let quickName = $state('');
	let quickQuantity = $state('');
	let quickUnit = $state('');
	let filter = $state<'open' | 'checked' | 'all'>('open');
	let sortMode = $state<'manual' | 'name'>('manual');
	let historyOpen = $state(false);
	let historyLoading = $state(false);
	let editingItemId = $state<number | null>(null);
	let itemToRemove = $state<ShoppingItem | null>(null);
	let removeModalOpen = $state(false);
	let listModalOpen = $state(false);
	let addItemModalOpen = $state(false);
	let renameModalOpen = $state(false);
	let editItemModalOpen = $state(false);
	let editName = $state('');
	let editQuantity = $state('');
	let editUnit = $state('');
	let suggestionWeekStart = $state(mondayDate());
	let suggestionPreview = $state<MealSuggestionPreview | null>(null);
	let selectedSuggestionIds = $state<string[]>([]);
	let suggestionQuantities = $state<Record<string, string>>({});
	let suggestionLoading = $state(false);
	let suggestionImporting = $state(false);

	const openItems = $derived(items.filter((item) => !item.is_checked));
	const checkedItems = $derived(items.filter((item) => item.is_checked));
	const visibleItems = $derived.by(() => {
		let result = items.filter((item) => filter === 'all' || (filter === 'checked' ? item.is_checked : !item.is_checked));
		if (sortMode === 'name') result = [...result].sort((a, b) => a.name.localeCompare(b.name));
		return result;
	});

	$effect(() => {
		if (!shell.loading && canView) void loadLists();
		else if (!shell.loading) loading = false;
	});

	async function api<T>(path: string, method = 'GET', body?: object): Promise<T> {
		const headers: Record<string, string> = { Accept: 'application/json' };
		if (method !== 'GET') {
			const token = csrfToken();
			if (!token) throw new Error('Reload this page and try again.');
			headers['X-XSRF-TOKEN'] = token;
			headers['Content-Type'] = 'application/json';
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
		if (!response.ok) throw new Error(firstError(result) || result.message || 'Shopping could not complete that request.');
		return result as T;
	}

	async function loadLists(preferredId: number | null = activeListId): Promise<void> {
		loading = true;
		error = '';
		try {
			const result = await api<ShoppingListsResponse>('/api/shopping/lists');
			lists = result.lists ?? [];
			const previousId = activeListId;
			const next = lists.find((list) => list.id === preferredId) ?? lists[0] ?? null;
			activeListId = next?.id ?? null;
			if (next?.id !== previousId) {
				historyOpen = false;
				activity = [];
				suggestionPreview = null;
				selectedSuggestionIds = [];
				suggestionQuantities = {};
			}
			if (next) await loadList(next.id);
			else {
				activeList = null;
				items = [];
				activity = [];
			}
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Shopping lists could not be loaded.';
		} finally {
			loading = false;
		}
	}

	async function loadList(id: number): Promise<void> {
		listLoading = true;
		error = '';
		try {
			const result = await api<ShoppingListsResponse>('/api/shopping/lists/' + id);
			activeList = result.list ?? null;
			items = result.items ?? [];
			activeListId = id;
			editingItemId = null;
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'This shopping list could not be loaded.';
		} finally {
			listLoading = false;
		}
	}

	async function refresh(): Promise<void> {
		const reloadActivity = historyOpen;
		activity = [];
		await loadLists(activeListId);
		if (reloadActivity && activeList) await loadActivity();
	}

	async function createList(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		error = '';
		message = '';
		saving = true;
		try {
			const result = await api<ShoppingListsResponse>('/api/shopping/lists', 'POST', {
				name: listName,
				visibility: canManageShared ? visibility : 'private'
			});
			listName = '';
			visibility = 'private';
			message = 'Shopping list created.';
			listModalOpen = false;
			await loadLists(result.list?.id ?? null);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The shopping list could not be created.';
		} finally {
			saving = false;
		}
	}

	async function addItem(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!activeListId) return;
		error = '';
		message = '';
		saving = true;
		try {
			await api<ShoppingListsResponse>('/api/shopping/lists/' + activeListId + '/items', 'POST', {
				name: quickName,
				quantity: quickQuantity.trim() ? canonicalizeDecimalInput(quickQuantity, $numberLocale) : null,
				unit: quickUnit.trim() || null
			});
			quickName = '';
			quickQuantity = '';
			quickUnit = '';
			message = 'Item added to the list.';
			addItemModalOpen = false;
			await refresh();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The item could not be added.';
		} finally {
			saving = false;
		}
	}

	async function previewMealSuggestions(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!activeListId) return;
		error = '';
		message = '';
		suggestionLoading = true;
		suggestionPreview = null;
		selectedSuggestionIds = [];
		suggestionQuantities = {};
		try {
			const result = await api<MealSuggestionsResponse>(
				'/api/shopping/lists/' + activeListId + '/meal-suggestions',
				'POST',
				{ week_start: suggestionWeekStart }
			);
			suggestionPreview = result.preview ?? null;
			selectedSuggestionIds = [];
			suggestionQuantities = Object.fromEntries(
				(suggestionPreview?.suggestions ?? []).map((suggestion) => [
					suggestion.id,
					formatDecimalInput(suggestion.quantity, $numberLocale)
				])
			);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Meal suggestions could not be prepared.';
		} finally {
			suggestionLoading = false;
		}
	}

	async function importMealSuggestions(): Promise<void> {
		if (!activeListId || !suggestionPreview || selectedSuggestionIds.length === 0) return;
		error = '';
		message = '';
		suggestionImporting = true;
		try {
			const quantities = Object.fromEntries(
				selectedSuggestionIds.map((id) => [
					id,
					canonicalizeDecimalInput(suggestionQuantities[id] ?? '', $numberLocale)
				])
			);
			const result = await api<MealSuggestionsResponse>(
				'/api/shopping/lists/' + activeListId + '/meal-suggestions/import',
				'POST',
				{
					week_start: suggestionPreview.week_start,
					expected_list_version: suggestionPreview.list_version,
					preview_expires_at: suggestionPreview.preview_expires_at,
					preview_token: suggestionPreview.preview_token,
					confirmed: true,
					suggestion_ids: selectedSuggestionIds,
					quantities
				}
			);
			const added = result.items?.length ?? 0;
			suggestionPreview = null;
			selectedSuggestionIds = [];
			suggestionQuantities = {};
			message = `${added} meal suggestion${added === 1 ? '' : 's'} added to the list.`;
			await refresh();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Meal suggestions could not be added.';
			if (error.toLowerCase().includes('changed') || error.toLowerCase().includes('expired')) {
				suggestionPreview = null;
				selectedSuggestionIds = [];
				suggestionQuantities = {};
			}
		} finally {
			suggestionImporting = false;
		}
	}

	async function setChecked(item: ShoppingItem, checked: boolean): Promise<void> {
		error = '';
		message = '';
		try {
			await api<ShoppingListsResponse>('/api/shopping/items/' + item.id + '/checked', 'PATCH', {
				expected_version: item.version,
				checked
			});
			message = checked ? 'Item checked off.' : 'Item restored to the list.';
			await refresh();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The item status could not be changed.';
		}
	}

	function startEdit(item: ShoppingItem): void {
		editItemModalOpen = true;
		editingItemId = item.id;
		editName = item.name;
		editQuantity = formatDecimalInput(item.quantity, $numberLocale);
		editUnit = item.unit ?? '';
		error = '';
	}

	async function saveItem(event: SubmitEvent, item: ShoppingItem): Promise<void> {
		event.preventDefault();
		error = '';
		message = '';
		saving = true;
		try {
			await api<ShoppingListsResponse>('/api/shopping/items/' + item.id, 'PATCH', {
				expected_version: item.version,
				name: editName,
				quantity: editQuantity.trim() ? canonicalizeDecimalInput(editQuantity, $numberLocale) : null,
				unit: editUnit.trim() || null
			});
			editingItemId = null;
			editItemModalOpen = false;
			message = 'Shopping item updated.';
			await refresh();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The item could not be updated.';
		} finally {
			saving = false;
		}
	}

	async function moveItem(item: ShoppingItem, direction: 'up' | 'down'): Promise<void> {
		error = '';
		try {
			await api<ShoppingListsResponse>('/api/shopping/items/' + item.id + '/move', 'POST', {
				expected_version: item.version,
				direction
			});
			await refresh();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The item order could not be changed.';
		}
	}

	async function removeItem(item: ShoppingItem): Promise<void> {
		error = '';
		message = '';
		try {
			await api<ShoppingListsResponse>('/api/shopping/items/' + item.id, 'DELETE');
			message = 'Item removed. Its activity remains in the list history.';
			itemToRemove = null;
			removeModalOpen = false;
			await refresh();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The item could not be removed.';
		}
	}

	async function renameList(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!activeList) return;
		error = '';
		message = '';
		try {
			await api<ShoppingListsResponse>('/api/shopping/lists/' + activeList.id, 'PATCH', {
				expected_version: activeList.version,
				name: activeList.name
			});
			message = 'Shopping list renamed.';
			renameModalOpen = false;
			await refresh();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The list name could not be changed.';
		}
	}

	async function loadActivity(): Promise<void> {
		if (!activeList) return;
		historyLoading = true;
		error = '';
		try {
			const result = await api<ShoppingListsResponse>('/api/shopping/lists/' + activeList.id + '/history');
			activity = result.events ?? [];
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'List history could not be loaded.';
		} finally {
			historyLoading = false;
		}
	}

	async function toggleHistory(): Promise<void> {
		historyOpen = !historyOpen;
		if (!historyOpen || !activeList || activity.length > 0) return;
		await loadActivity();
	}

	function chooseList(id: number): void {
		historyOpen = false;
		activity = [];
		message = '';
		invalidateMealPreview();
		void loadList(id);
	}

	function toggleSuggestionSelection(): void {
		if (!suggestionPreview) return;
		const ids = suggestionPreview.suggestions.map((suggestion) => suggestion.id);
		const allSelected = ids.every((id) => selectedSuggestionIds.includes(id));
		selectedSuggestionIds = allSelected ? [] : ids;
	}

	function isFirstInGroup(item: ShoppingItem): boolean {
		const group = items.filter((entry) => entry.is_checked === item.is_checked)
			.sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
		return group[0]?.id === item.id;
	}

	function isLastInGroup(item: ShoppingItem): boolean {
		const group = items.filter((entry) => entry.is_checked === item.is_checked)
			.sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
		return group.at(-1)?.id === item.id;
	}

	function eventLabel(entry: ShoppingActivity): string {
		const subject = String(entry.after?.name ?? entry.before?.name ?? 'List');
		const actor = entry.actor ? entry.actor + ' ' : '';
		const action: Record<string, string> = {
			list_created: 'created the list',
			list_renamed: 'renamed the list',
			item_added: 'added ' + subject,
			item_updated: 'updated ' + subject,
			item_checked: 'checked off ' + subject,
			item_unchecked: 'restored ' + subject,
			item_deleted: 'removed ' + subject,
			item_reordered: 'reordered ' + subject
		};
		return actor + (action[entry.type] ?? 'changed the list');
	}

	function formatDate(value: string | null): string {
		if (!value) return $t('Just now');
		return formatLocaleDate(value, { dateStyle: 'medium', timeStyle: 'short' });
	}

	function mondayDate(): string {
		const date = new Date();
		const monday = new Date(date.getFullYear(), date.getMonth(), date.getDate());
		monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7));
		return `${monday.getFullYear()}-${String(monday.getMonth() + 1).padStart(2, '0')}-${String(monday.getDate()).padStart(2, '0')}`;
	}

	function invalidateMealPreview(): void {
		suggestionPreview = null;
		selectedSuggestionIds = [];
		suggestionQuantities = {};
	}
</script>

<svelte:head>
	<title>{$t("Shopping")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Keep shared shopping lists organized, current and easy to restore.")} />
</svelte:head>

<div class="mx-auto max-w-7xl space-y-8">
	<PageHeading eyebrow="Food · Shopping" title="The list everyone can trust" description="Add what you need, check it off at the store and put it back if plans change. Shopping works on its own.">
		{#snippet actions()}
			{#if activeList}
				<Badge>{openItems.length} {$t("to buy")}</Badge>
				<Badge>{checkedItems.length} {$t("checked")}</Badge>
			{/if}
		{/snippet}
	</PageHeading>

	{#if message}<Notice tone="success" message={message} />{/if}
	{#if error}<Notice tone="error" message={error} />{/if}

	{#if shell.loading || loading}
		<div class="rounded-2xl border border-line bg-surface p-7 text-sm text-ink-muted">{$t("Loading your shopping lists…")}</div>
	{:else if !canView}
		<section class="rounded-2xl border border-warning/20 bg-warning/5 p-6 sm:p-8">
			<p class="text-sm font-medium text-warning">{$t("Shopping View access is required.")}</p>
			<h2 class="mt-2 text-xl font-semibold text-ink">{$t("Your lists stay private to the right members.")}</h2>
			<p class="mt-3 text-sm leading-6 text-ink-muted">{$t("Ask a household administrator to enable Shopping and grant your account View access.")}</p>
		</section>
	{:else}
		<div class="grid gap-5 lg:grid-cols-[17rem_minmax(0,1fr)]">
			<aside class="space-y-4">
				<section class="rounded-2xl border border-line bg-surface p-4">
					<div class="mb-3 flex items-center justify-between gap-3">
						<h2 class="text-sm font-semibold text-ink">{$t("Your lists")}</h2>
						<span class="text-xs text-ink-subtle">{lists.length} / 100</span>
					</div>
					{#if lists.length}
						<nav aria-label={$t("Shopping lists")} class="space-y-2">
							{#each lists as list (list.id)}
								<button type="button" onclick={() => chooseList(list.id)} aria-current={activeListId === list.id ? 'page' : undefined} class="w-full rounded-xl border px-3 py-3 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink {activeListId === list.id ? 'border-accent-ink/50 bg-accent/10' : 'border-line bg-page hover:border-line-strong'}">
									<span class="flex items-center justify-between gap-2">
										<span class="truncate text-sm font-medium text-ink">{list.name}</span>
										{#if list.visibility === 'household'}<span class="shrink-0 text-[0.65rem] text-accent-ink">{$t("Shared")}</span>{/if}
									</span>
									<span class="mt-1 block text-xs text-ink-subtle">{list.open_item_count} {$t("open ·")} {list.item_count} total</span>
								</button>
							{/each}
						</nav>
					{:else}
						<p class="rounded-xl border border-dashed border-line-strong px-3 py-4 text-xs leading-5 text-ink-muted">{$t("No lists yet. Create one to start collecting what you need.")}</p>
					{/if}
				</section>

				{#if canCreateList}
					<button type="button" onclick={() => (listModalOpen = true)} class="min-h-11 w-full rounded-xl bg-accent px-4 text-sm font-semibold text-on-accent">{$t('Create a list')}</button>
					<Modal bind:open={listModalOpen} title={$t('Create a list')}>
					<form onsubmit={createList} class="space-y-3 rounded-2xl border border-line bg-surface p-4">
						<h2 class="text-sm font-semibold text-ink">{$t("Create a list")}</h2>
						<label class="block text-xs font-medium text-ink-muted">{$t("List name")}
							<input bind:value={listName} maxlength="160" required placeholder={$t("Weekly groceries")} class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink placeholder:text-ink-subtle focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" />
						</label>
						{#if canManageShared}
							<label class="block text-xs font-medium text-ink-muted">{$t("Who can see it?")}
								<select bind:value={visibility} class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base">
									<option value="private">{$t("Only me")}</option>
									<option value="household">{$t("Household")}</option>
								</select>
							</label>
						{/if}
						<button type="submit" disabled={saving} class="min-h-11 w-full rounded-xl bg-accent px-4 text-sm font-semibold text-on-accent hover:bg-accent/90 disabled:opacity-60">{$t(saving ? 'Creating…' : 'Create list')}</button>
					</form>
					</Modal>
				{/if}
			</aside>

			{#if !activeList}
				<section class="flex min-h-64 flex-col items-center justify-center rounded-2xl border border-line bg-surface px-6 py-10 text-center">
					<p class="text-xs font-semibold text-accent-ink">{$t("Ready when you are")}</p>
					<h2 class="mt-3 text-2xl font-semibold text-ink">{$t("Start with one thing.")}</h2>
					<p class="mt-2 max-w-md text-sm leading-6 text-ink-muted">{$t(canCreateList ? 'Create a list on the left, then add items as you think of them.' : 'Ask a household administrator to create a shared list or grant you Create access.')}</p>
				</section>
			{:else}
				<section class="min-w-0 space-y-4">
					{#if activeList.can_add_items}
						<button type="button" onclick={() => (addItemModalOpen = true)} class="min-h-11 rounded-xl bg-accent px-4 text-sm font-semibold text-on-accent">{$t('Add item')}</button>
						<Modal bind:open={addItemModalOpen} title={$t('Add item')} description={activeList.name}>
						<form onsubmit={addItem} class="rounded-2xl border border-accent-ink/30 bg-surface p-4 shadow-xs sm:p-5">
							<div class="mb-3 flex items-center justify-between gap-3">
								<div>
									<p class="text-xs font-semibold text-accent-ink">{$t("Quick add")}</p>
									<h2 class="mt-1 text-lg font-semibold text-ink">{$t("What do you need?")}</h2>
								</div>
								{#if activeList.visibility === 'household'}<span class="rounded-full border border-accent-ink/30 px-3 py-1.5 text-xs text-accent-ink">{$t("Shared list")}</span>{/if}
							</div>
							<div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_7rem_7rem_auto]">
								<label class="text-xs font-medium text-ink-muted">{$t("Item")}
									<input bind:value={quickName} maxlength="180" required placeholder={$t("Apples, olive oil…")} class="mt-1.5 min-h-12 w-full rounded-[10px] border border-line-strong bg-page px-3 text-base text-ink placeholder:text-ink-subtle focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20" />
								</label>
								<label class="text-xs font-medium text-ink-muted">{$t("Quantity")} <span class="font-normal text-ink-subtle">{$t('optional')}</span>
									<input bind:value={quickQuantity} type="text" inputmode="decimal" maxlength="15" placeholder="2" class="mt-1.5 min-h-12 w-full rounded-[10px] border border-line-strong bg-page px-3 text-base text-ink placeholder:text-ink-subtle focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20" />
								</label>
								<label class="text-xs font-medium text-ink-muted">{$t("Unit")} <span class="font-normal text-ink-subtle">{$t('optional')}</span>
									<input bind:value={quickUnit} maxlength="32" placeholder={$t("kg, cans")} class="mt-1.5 min-h-12 w-full rounded-[10px] border border-line-strong bg-page px-3 text-base text-ink placeholder:text-ink-subtle focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20" />
								</label>
								<button type="submit" disabled={saving} class="min-h-12 self-end rounded-xl bg-accent px-5 text-sm font-semibold text-on-accent hover:bg-accent/90 disabled:opacity-60">{$t(saving ? 'Adding…' : 'Add item')}</button>
							</div>
							<p class="mt-2 text-xs text-ink-subtle">{$t("Add both quantity and unit, or leave both blank. No unit conversions are applied.")}</p>
						</form>
						</Modal>
					{/if}

					{#if activeList.can_add_items}
						<section class="rounded-2xl border border-accent-ink/25 bg-surface p-4 sm:p-5" aria-labelledby="meal-suggestions-title">
							<div>
								<p class="text-xs font-semibold text-accent-ink">{$t("Optional connection")}</p>
								<h2 id="meal-suggestions-title" class="mt-1 text-lg font-semibold text-ink">{$t("Add ingredients from the weekly plan")}</h2>
								<p class="mt-2 text-sm leading-6 text-ink-muted">{$t("Preview recipe ingredients and combined quantities for a week, then choose only what you want to add to this list.")}</p>
							</div>
							<form onsubmit={previewMealSuggestions} class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
								<label class="block text-xs font-medium text-ink-muted">{$t("Week starting Monday")}
									<input bind:value={suggestionWeekStart} onchange={invalidateMealPreview} type="date" required class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" />
								</label>
								<button type="submit" disabled={suggestionLoading || suggestionImporting} class="min-h-11 rounded-xl border border-accent-ink/40 px-4 text-sm font-semibold text-accent-ink hover:bg-accent/10 disabled:opacity-60">{$t(suggestionLoading ? 'Preparing…' : 'Review suggestions')}</button>
							</form>

							{#if suggestionPreview}
								<div class="mt-4 space-y-3 border-t border-line pt-4" aria-live="polite">
									{#if !suggestionPreview.availability.meal_planning}
										<p role="status" class="rounded-xl border border-warning/20 bg-warning/5 px-3 py-3 text-sm text-warning">{$t("Meal Planning is disabled or unavailable to your account. No plan data was loaded.")}</p>
									{:else}
										{#if suggestionPreview.suggestions.length === 0}
											<p role="status" class="rounded-xl border border-line px-3 py-3 text-sm text-ink-muted">{$t("No importable recipe ingredients were found for this week.")}</p>
										{:else}
											<div class="flex flex-wrap items-center justify-between gap-2">
												<p class="text-xs text-ink-muted">{$t("Select the ingredients you want and adjust each quantity for what you already have. Matching ingredients are combined across linked recipes.")}</p>
												<button type="button" onclick={toggleSuggestionSelection} class="min-h-9 rounded-lg border border-line-strong px-3 text-xs font-medium text-ink-secondary hover:border-accent-ink/60">{$t(selectedSuggestionIds.length === suggestionPreview.suggestions.length ? 'Clear selection' : 'Select all')}</button>
											</div>
											<div class="max-h-80 space-y-2 overflow-y-auto">
												{#each suggestionPreview.suggestions as suggestion (suggestion.id)}
													<div class="grid min-h-12 grid-cols-[auto_minmax(0,1fr)] items-center gap-3 rounded-xl border border-line bg-page px-3 py-2.5 sm:grid-cols-[auto_minmax(0,1fr)_auto]">
														<input id={'meal-suggestion-' + suggestion.id} bind:group={selectedSuggestionIds} type="checkbox" value={suggestion.id} aria-label={$t('Select {item}', { item: suggestion.name })} class="size-4 shrink-0 accent-accent" />
														<label for={'meal-suggestion-' + suggestion.id} class="break-words text-sm font-medium text-ink">{suggestion.name}</label>
														<div class="flex items-center gap-2">
															<input bind:value={suggestionQuantities[suggestion.id]} type="text" inputmode="decimal" autocomplete="off" aria-label={$t('Quantity to add for {item}', { item: suggestion.name })} class="min-h-10 w-24 rounded-[10px] border border-line-strong bg-page px-2 text-right text-sm tabular-nums text-ink focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" />
															<span class="min-w-8 text-sm text-accent-ink">{suggestion.unit}</span>
														</div>
													</div>
												{/each}
											</div>
											{#if suggestionPreview.skipped.free_text_meals > 0}<p class="text-xs text-ink-subtle">{suggestionPreview.skipped.free_text_meals} {$t("free-text meal(s) have no recipe ingredients to suggest.")}</p>{/if}
											{#if suggestionPreview.skipped.unavailable_recipes > 0}<p class="text-xs text-ink-subtle">{$t("Some linked recipes are unavailable to your account and were skipped.")}</p>{/if}
											{#if suggestionPreview.skipped.unsupported_quantities > 0}<p class="text-xs text-warning">{$t("Some quantities cannot be represented exactly in Shopping and were skipped.")}</p>{/if}
											<p class="text-xs leading-5 text-ink-subtle">{$t("Adding suggestions copies items into this list only. Confirming will not change meal plans or recipes.")}</p>
											<button type="button" onclick={importMealSuggestions} disabled={selectedSuggestionIds.length === 0 || suggestionImporting} class="min-h-11 w-full rounded-xl bg-accent px-4 text-sm font-semibold text-on-accent hover:bg-accent/90 disabled:opacity-50">{$t(suggestionImporting ? 'Adding…' : 'Add selected ingredients')} · {selectedSuggestionIds.length}</button>
										{/if}
									{/if}
								</div>
							{/if}
						</section>
					{/if}

					<div class="rounded-2xl border border-line bg-surface">
						<header class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
							<div class="min-w-0">
								<div class="flex flex-wrap items-center gap-2">
									<h2 class="truncate text-xl font-semibold text-ink">{activeList.name}</h2>
									<Badge tone={activeList.visibility === 'household' ? 'accent' : 'neutral'}>{$t(visibilityLabel(activeList.visibility))}</Badge>
								</div>
								<p class="mt-1 text-xs text-ink-subtle">{activeList.open_item_count} {$t("left to buy ·")} {activeList.item_count} total</p>
							</div>
							<div class="flex flex-wrap items-center gap-2">
								<label class="sr-only" for="shopping-filter">{$t("Show items")}</label>
								<select id="shopping-filter" bind:value={filter} class="min-h-10 rounded-[10px] border border-line-strong bg-page px-3 text-xs text-ink-secondary focus:border-accent-ink focus:outline-none max-sm:text-base">
									<option value="open">{$t("To buy")}</option>
									<option value="checked">{$t("Checked")}</option>
									<option value="all">{$t("All items")}</option>
								</select>
								<label class="sr-only" for="shopping-sort">{$t("Sort items")}</label>
								<select id="shopping-sort" bind:value={sortMode} class="min-h-10 rounded-[10px] border border-line-strong bg-page px-3 text-xs text-ink-secondary focus:border-accent-ink focus:outline-none max-sm:text-base">
									<option value="manual">{$t("List order")}</option>
									<option value="name">{$t("Name A–Z")}</option>
								</select>
								{#if activeList.can_edit}
									<button type="button" onclick={() => (renameModalOpen = true)} class="min-h-10 rounded-lg border border-line-strong px-3 text-xs text-ink-secondary hover:border-accent-ink/60">{$t("Rename list")}</button>
								{/if}
							</div>
						</header>
						{#if activeList.can_edit}<Modal bind:open={renameModalOpen} title={$t('Rename shopping list')}><form onsubmit={renameList} class="space-y-4"><label class="block text-sm font-medium text-ink-secondary" for="shopping-list-name">{$t('List name')}<input id="shopping-list-name" bind:value={activeList.name} maxlength="160" required class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" /></label><button type="submit" disabled={saving} class="min-h-10 rounded-lg bg-accent px-4 text-sm font-semibold text-on-accent">{$t('Rename')}</button></form></Modal>{/if}

						{#if listLoading}
							<p class="p-6 text-sm text-ink-muted">{$t("Loading items…")}</p>
						{:else if visibleItems.length}
							<ul aria-label={$t("Shopping items")} class="divide-y divide-line">
								{#each visibleItems as item (item.id)}
									<li class="flex min-w-0 items-start gap-3 px-4 py-4 sm:items-center sm:px-5">
										<input type="checkbox" checked={item.is_checked} disabled={!item.can_edit} aria-label={item.is_checked ? 'Restore ' + item.name + ' to the list' : 'Check off ' + item.name} onchange={(event) => void setChecked(item, (event.currentTarget as HTMLInputElement).checked)} class="mt-1 h-5 w-5 shrink-0 rounded border-line-strong bg-page text-accent-ink focus:ring-2 focus:ring-accent-ink/40 disabled:opacity-50 sm:mt-0" />
										<div class="min-w-0 flex-1">
											<p class="break-words text-sm font-medium {item.is_checked ? 'text-ink-subtle line-through' : 'text-ink'}">{item.name}</p>
											<p class="mt-1 text-xs text-ink-subtle">{#if item.quantity}{formatMeasurement(item.quantity, item.unit)} {item.unit}{:else}{$t("Quantity not set")}{/if}{#if item.is_checked && item.checked_at}<span> {$t("· Checked")} {formatDate(item.checked_at)}</span>{/if}</p>
										</div>
										{#if item.can_edit}
											<div class="flex shrink-0 flex-wrap items-center justify-end gap-1">
												{#if item.is_checked}
													<button type="button" onclick={() => void setChecked(item, false)} class="min-h-10 rounded-lg border border-accent-ink/30 px-2.5 text-xs font-medium text-accent-ink hover:bg-accent/10">{$t("Put back")}</button>
												{:else}
													<button type="button" disabled={sortMode !== 'manual' || isFirstInGroup(item)} onclick={() => void moveItem(item, 'up')} aria-label={'Move ' + item.name + ' up'} class="min-h-10 w-10 rounded-lg border border-line-strong text-sm text-ink-secondary hover:border-accent-ink/60 disabled:opacity-30">↑</button>
													<button type="button" disabled={sortMode !== 'manual' || isLastInGroup(item)} onclick={() => void moveItem(item, 'down')} aria-label={'Move ' + item.name + ' down'} class="min-h-10 w-10 rounded-lg border border-line-strong text-sm text-ink-secondary hover:border-accent-ink/60 disabled:opacity-30">↓</button>
												{/if}
												<button type="button" onclick={() => startEdit(item)} class="min-h-10 rounded-lg border border-line-strong px-2.5 text-xs text-ink-secondary hover:border-accent-ink/60">{$t("Edit")}</button>
											<button type="button" onclick={() => { itemToRemove = item; removeModalOpen = true; }} class="min-h-10 rounded-lg border border-line-strong px-2.5 text-xs text-ink-muted hover:border-danger/60 hover:text-danger">{$t("Remove")}</button>
											</div>
										{/if}
									</li>
									{#if editingItemId === item.id}
										<li>
											<Modal bind:open={editItemModalOpen} title={$t('Edit shopping item')} description={item.name}>
											<form onsubmit={(event) => saveItem(event, item)} class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_8rem_8rem_auto_auto]">
												<label class="text-xs font-medium text-ink-muted">{$t("Item name")}
													<input bind:value={editName} maxlength="180" required class="mt-1 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" />
												</label>
												<label class="text-xs font-medium text-ink-muted">{$t("Quantity")}
													<input bind:value={editQuantity} type="text" inputmode="decimal" maxlength="15" class="mt-1 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" />
												</label>
												<label class="text-xs font-medium text-ink-muted">{$t("Unit")}
													<input bind:value={editUnit} maxlength="32" class="mt-1 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" />
												</label>
												<button type="submit" disabled={saving} class="min-h-11 self-end rounded-lg bg-accent px-4 text-xs font-semibold text-on-accent">{$t("Save")}</button>
											<button type="button" onclick={() => { editingItemId = null; editItemModalOpen = false; }} class="min-h-11 self-end rounded-lg border border-line-strong px-4 text-xs text-ink-secondary">{$t("Cancel")}</button>
											</form>
										</Modal>
										</li>
									{/if}
								{/each}
							</ul>
						{:else}
							<div class="px-5 py-10 text-center">
								<p class="text-sm font-medium text-ink">{items.length === 0 ? 'This list is ready for its first item.' : filter === 'open' ? 'Everything is checked off.' : 'No items match this view.'}</p>
								<p class="mt-1 text-xs text-ink-subtle">{$t(filter === 'checked' && checkedItems.length ? 'Checked items can be put back on the list at any time.' : 'Use Quick add above to keep the list current.')}</p>
							</div>
						{/if}
					</div>

					<section class="overflow-hidden rounded-2xl border border-line bg-surface">
						<button type="button" aria-expanded={historyOpen} onclick={() => void toggleHistory()} class="flex min-h-12 w-full items-center justify-between gap-4 px-4 text-left text-sm font-medium text-ink-secondary hover:bg-surface sm:px-5">
							<span>{$t("List activity")}</span><span class="text-xs text-ink-subtle">{$t(historyOpen ? 'Hide' : 'Show last 100 events')} <span aria-hidden="true">{$t(historyOpen ? '−' : '+')}</span></span>
						</button>
						{#if historyOpen}
							<div class="border-t border-line px-4 py-3 sm:px-5">
								{#if historyLoading}
									<p class="py-3 text-xs text-ink-subtle">{$t("Loading activity…")}</p>
								{:else if activity.length}
									<ol class="divide-y divide-line">
										{#each activity as entry (entry.id)}
											<li class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
												<span class="break-words text-xs text-ink-secondary">{eventLabel(entry)}</span>
												<time class="shrink-0 text-[0.68rem] text-ink-subtle">{formatDate(entry.created_at)}</time>
											</li>
										{/each}
									</ol>
								{:else}
									<p class="py-3 text-xs text-ink-subtle">{$t("No list activity yet.")}</p>
								{/if}
							</div>
						{/if}
					</section>
{#if itemToRemove}<ConfirmModal bind:open={removeModalOpen} title={$t('Remove shopping item?')} message={$t('Remove this item? Its activity will remain in the list history.')} onConfirm={() => void removeItem(itemToRemove!)} />{/if}
				</section>
			{/if}
		</div>
	{/if}
</div>
