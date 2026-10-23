<script lang="ts">
	import { t } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { formatMeasurement } from '#lib/number-format';
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
	let editName = $state('');
	let editQuantity = $state('');
	let editUnit = $state('');
	let suggestionWeekStart = $state(mondayDate());
	let subtractPantry = $state(false);
	let suggestionPreview = $state<MealSuggestionPreview | null>(null);
	let selectedSuggestionIds = $state<string[]>([]);
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
				quantity: quickQuantity.trim() || null,
				unit: quickUnit.trim() || null
			});
			quickName = '';
			quickQuantity = '';
			quickUnit = '';
			message = 'Item added to the list.';
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
		try {
			const result = await api<MealSuggestionsResponse>(
				'/api/shopping/lists/' + activeListId + '/meal-suggestions',
				'POST',
				{ week_start: suggestionWeekStart, subtract_pantry: subtractPantry }
			);
			suggestionPreview = result.preview ?? null;
			selectedSuggestionIds = suggestionPreview?.suggestions.map((suggestion) => suggestion.id) ?? [];
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
			const result = await api<MealSuggestionsResponse>(
				'/api/shopping/lists/' + activeListId + '/meal-suggestions/import',
				'POST',
				{
					week_start: suggestionPreview.week_start,
					subtract_pantry: suggestionPreview.subtract_pantry,
					expected_list_version: suggestionPreview.list_version,
					preview_expires_at: suggestionPreview.preview_expires_at,
					preview_token: suggestionPreview.preview_token,
					confirmed: true,
					suggestion_ids: selectedSuggestionIds
				}
			);
			const added = result.items?.length ?? 0;
			suggestionPreview = null;
			selectedSuggestionIds = [];
			message = `${added} meal suggestion${added === 1 ? '' : 's'} added to the list. Pantry stock was not changed.`;
			await refresh();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Meal suggestions could not be added.';
			if (error.toLowerCase().includes('changed') || error.toLowerCase().includes('expired')) {
				suggestionPreview = null;
				selectedSuggestionIds = [];
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
		editingItemId = item.id;
		editName = item.name;
		editQuantity = item.quantity ?? '';
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
				quantity: editQuantity.trim() || null,
				unit: editUnit.trim() || null
			});
			editingItemId = null;
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
		void loadList(id);
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
		if (!value) return 'Just now';
		return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
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
	}
</script>

<svelte:head>
	<title>{$t("Shopping")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Keep shared shopping lists organized, current and easy to restore.")} />
</svelte:head>

<div class="mx-auto max-w-7xl space-y-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-10">
	<header class="flex flex-col gap-5 border-b border-slate-800 pb-7 sm:flex-row sm:items-end sm:justify-between">
		<div>
			<p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-300">{$t("Food · Shopping")}</p>
			<h1 class="mt-3 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{$t("The list everyone can trust")}</h1>
			<p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">{$t("Add what you need, check it off at the store and put it back if plans change. Shopping works on its own.")}</p>
		</div>
		{#if activeList}
			<div class="flex gap-2 text-xs text-slate-400">
				<span class="rounded-full border border-slate-700 px-3 py-2">{openItems.length} {$t("to buy")}</span>
				<span class="rounded-full border border-slate-700 px-3 py-2">{checkedItems.length} checked</span>
			</div>
		{/if}
	</header>

	{#if message}<p role="status" class="rounded-xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{$t(message)}</p>{/if}
	{#if error}<p role="alert" class="rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">{$t(error)}</p>{/if}

	{#if shell.loading || loading}
		<div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-7 text-sm text-slate-400">{$t("Loading your shopping lists…")}</div>
	{:else if !canView}
		<section class="rounded-2xl border border-amber-300/20 bg-amber-300/5 p-6 sm:p-8">
			<p class="text-sm font-medium text-amber-200">{$t("Shopping View access is required.")}</p>
			<h2 class="mt-2 text-xl font-semibold text-white">{$t("Your lists stay private to the right members.")}</h2>
			<p class="mt-3 text-sm leading-6 text-slate-400">{$t("Ask a household administrator to enable Shopping and grant your account View access.")}</p>
		</section>
	{:else}
		<div class="grid gap-5 lg:grid-cols-[17rem_minmax(0,1fr)]">
			<aside class="space-y-4">
				<section class="rounded-2xl border border-slate-800 bg-slate-900/55 p-4">
					<div class="mb-3 flex items-center justify-between gap-3">
						<h2 class="text-sm font-semibold text-white">{$t("Your lists")}</h2>
						<span class="text-xs text-slate-500">{lists.length} / 100</span>
					</div>
					{#if lists.length}
						<nav aria-label={$t("Shopping lists")} class="space-y-2">
							{#each lists as list (list.id)}
								<button type="button" onclick={() => chooseList(list.id)} aria-current={activeListId === list.id ? 'page' : undefined} class="w-full rounded-xl border px-3 py-3 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 {activeListId === list.id ? 'border-cyan-300/50 bg-cyan-300/10' : 'border-slate-800 bg-slate-950/40 hover:border-slate-600'}">
									<span class="flex items-center justify-between gap-2">
										<span class="truncate text-sm font-medium text-white">{list.name}</span>
										{#if list.visibility === 'household'}<span class="shrink-0 text-[0.65rem] uppercase tracking-wide text-cyan-300">{$t("Shared")}</span>{/if}
									</span>
									<span class="mt-1 block text-xs text-slate-500">{list.open_item_count} {$t("open ·")} {list.item_count} total</span>
								</button>
							{/each}
						</nav>
					{:else}
						<p class="rounded-xl border border-dashed border-slate-700 px-3 py-4 text-xs leading-5 text-slate-400">{$t("No lists yet. Create one to start collecting what you need.")}</p>
					{/if}
				</section>

				{#if canCreateList}
					<form onsubmit={createList} class="space-y-3 rounded-2xl border border-slate-800 bg-slate-900/55 p-4">
						<h2 class="text-sm font-semibold text-white">{$t("Create a list")}</h2>
						<label class="block text-xs font-medium text-slate-400">{$t("List name")}
							<input bind:value={listName} maxlength="160" required placeholder={$t("Weekly groceries")} class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white placeholder:text-slate-600 focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20" />
						</label>
						{#if canManageShared}
							<label class="block text-xs font-medium text-slate-400">{$t("Who can see it?")}
								<select bind:value={visibility} class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20">
									<option value="private">{$t("Only me")}</option>
									<option value="household">{$t("Household")}</option>
								</select>
							</label>
						{/if}
						<button type="submit" disabled={saving} class="min-h-11 w-full rounded-xl bg-cyan-300 px-4 text-sm font-semibold text-slate-950 hover:bg-cyan-200 disabled:opacity-60">{$t(saving ? 'Creating…' : 'Create list')}</button>
					</form>
				{/if}
			</aside>

			{#if !activeList}
				<section class="flex min-h-64 flex-col items-center justify-center rounded-2xl border border-slate-800 bg-slate-900/40 px-6 py-10 text-center">
					<p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-300">{$t("Ready when you are")}</p>
					<h2 class="mt-3 text-2xl font-semibold text-white">{$t("Start with one thing.")}</h2>
					<p class="mt-2 max-w-md text-sm leading-6 text-slate-400">{$t(canCreateList ? 'Create a list on the left, then add items as you think of them.' : 'Ask a household administrator to create a shared list or grant you Create access.')}</p>
				</section>
			{:else}
				<section class="min-w-0 space-y-4">
					{#if activeList.can_add_items}
						<form onsubmit={addItem} class="rounded-2xl border border-cyan-300/30 bg-slate-900/80 p-4 shadow-lg shadow-cyan-950/10 sm:p-5">
							<div class="mb-3 flex items-center justify-between gap-3">
								<div>
									<p class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-300">{$t("Quick add")}</p>
									<h2 class="mt-1 text-lg font-semibold text-white">{$t("What do you need?")}</h2>
								</div>
								{#if activeList.visibility === 'household'}<span class="rounded-full border border-cyan-300/30 px-3 py-1.5 text-xs text-cyan-200">{$t("Shared list")}</span>{/if}
							</div>
							<div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_7rem_7rem_auto]">
								<label class="text-xs font-medium text-slate-400">{$t("Item")}
									<input bind:value={quickName} maxlength="180" required placeholder={$t("Apples, olive oil…")} class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-base text-white placeholder:text-slate-600 focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20" />
								</label>
								<label class="text-xs font-medium text-slate-400">{$t("Quantity")} <span class="font-normal text-slate-600">optional</span>
									<input bind:value={quickQuantity} type="text" inputmode="decimal" maxlength="15" placeholder="2" class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-base text-white placeholder:text-slate-600 focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20" />
								</label>
								<label class="text-xs font-medium text-slate-400">{$t("Unit")} <span class="font-normal text-slate-600">optional</span>
									<input bind:value={quickUnit} maxlength="32" placeholder={$t("kg, cans")} class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-base text-white placeholder:text-slate-600 focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20" />
								</label>
								<button type="submit" disabled={saving} class="min-h-12 self-end rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 hover:bg-cyan-200 disabled:opacity-60">{$t(saving ? 'Adding…' : 'Add item')}</button>
							</div>
							<p class="mt-2 text-xs text-slate-500">{$t("Add both quantity and unit, or leave both blank. No unit conversions are applied.")}</p>
						</form>
					{/if}

					{#if activeList.can_add_items}
						<section class="rounded-2xl border border-violet-300/25 bg-slate-900/65 p-4 sm:p-5" aria-labelledby="meal-suggestions-title">
							<div>
								<p class="text-xs font-semibold uppercase tracking-[0.18em] text-violet-300">{$t("Optional connection")}</p>
								<h2 id="meal-suggestions-title" class="mt-1 text-lg font-semibold text-white">{$t("Suggestions from meal plans")}</h2>
								<p class="mt-2 text-sm leading-6 text-slate-400">{$t("Review recipe ingredients before adding them. Free-text meals stay untouched, pantry stock is read-only, and units are never converted.")}</p>
							</div>
							<form onsubmit={previewMealSuggestions} class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
								<label class="block text-xs font-medium text-slate-400">{$t("Week starting Monday")}
									<input bind:value={suggestionWeekStart} onchange={invalidateMealPreview} type="date" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-violet-300 focus:outline-none focus:ring-2 focus:ring-violet-300/20" />
								</label>
								<label class="flex min-h-11 items-center gap-2 rounded-xl border border-slate-800 px-3 py-2 text-xs leading-5 text-slate-300">
									<input bind:checked={subtractPantry} onchange={invalidateMealPreview} type="checkbox" class="size-4 shrink-0 accent-violet-300" />
									<span>{$t("Subtract visible Pantry stock in the preview")}</span>
								</label>
								<button type="submit" disabled={suggestionLoading || suggestionImporting} class="min-h-11 rounded-xl border border-violet-300/40 px-4 text-sm font-semibold text-violet-100 hover:bg-violet-300/10 disabled:opacity-60">{$t(suggestionLoading ? 'Preparing…' : 'Review suggestions')}</button>
							</form>

							{#if suggestionPreview}
								<div class="mt-4 space-y-3 border-t border-slate-800 pt-4" aria-live="polite">
									{#if !suggestionPreview.availability.meal_planning}
										<p role="status" class="rounded-xl border border-amber-300/20 bg-amber-300/5 px-3 py-3 text-sm text-amber-100">{$t("Meal Planning is disabled or unavailable to your account. No plan data was loaded.")}</p>
									{:else}
										{#if subtractPantry && !suggestionPreview.availability.pantry}
											<p role="status" class="rounded-xl border border-amber-300/20 bg-amber-300/5 px-3 py-3 text-sm text-amber-100">{$t("Pantry is disabled or unavailable to your account. Suggestions show the full recipe quantities.")}</p>
										{:else if suggestionPreview.availability.pantry && !subtractPantry}
											<p class="text-xs text-slate-500">{$t("Pantry was not read. Select the option above and review again to account for stock you can view.")}</p>
										{/if}
										{#if suggestionPreview.suggestions.length === 0}
											<p role="status" class="rounded-xl border border-slate-800 px-3 py-3 text-sm text-slate-400">{$t("No importable recipe ingredients were found for this week.")}</p>
										{:else}
											<p class="text-xs text-slate-400">{$t("All suggestions are selected. Review the quantities and uncheck anything you do not want to add.")}</p>
											<div class="max-h-80 space-y-2 overflow-y-auto">
												{#each suggestionPreview.suggestions as suggestion (suggestion.id)}
													<label class="flex min-h-12 items-start gap-3 rounded-xl border border-slate-800 bg-slate-950/45 px-3 py-3">
														<input bind:group={selectedSuggestionIds} type="checkbox" value={suggestion.id} class="mt-0.5 size-4 shrink-0 accent-violet-300" />
														<span class="min-w-0 flex-1">
															<span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
																<span class="break-words text-sm font-medium text-white">{suggestion.name}</span>
																<span class="text-sm tabular-nums text-violet-100">{formatMeasurement(suggestion.quantity, suggestion.unit)} {suggestion.unit}</span>
															</span>
															{#if suggestion.pantry_status === 'unit_mismatch'}<span class="mt-1 block text-xs text-amber-200">{$t("Visible Pantry stock uses a different unit; nothing was subtracted.")}</span>{/if}
														{#if suggestion.pantry_status === 'unavailable'}<span class="mt-1 block text-xs text-amber-200">{$t("Pantry was unavailable; the full amount is shown.")}</span>{/if}
														{#if formatMeasurement(suggestion.pantry_quantity_used, suggestion.unit) !== '0'}<span class="mt-1 block text-xs text-emerald-200">{$t("After previewing")} {formatMeasurement(suggestion.pantry_quantity_used, suggestion.unit)} {suggestion.unit} {$t("of visible stock.")}</span>{/if}
														</span>
													</label>
												{/each}
											</div>
											{#if suggestionPreview.skipped.free_text_meals > 0}<p class="text-xs text-slate-500">{suggestionPreview.skipped.free_text_meals} {$t("free-text meal(s) have no recipe ingredients to suggest.")}</p>{/if}
											{#if suggestionPreview.skipped.unavailable_recipes > 0}<p class="text-xs text-slate-500">{$t("Some linked recipes are unavailable to your account and were skipped.")}</p>{/if}
											{#if suggestionPreview.skipped.unsupported_quantities > 0}<p class="text-xs text-amber-200">{$t("Some quantities cannot be represented exactly in Shopping and were skipped.")}</p>{/if}
											{#if suggestionPreview.skipped.covered_by_pantry > 0}<p class="text-xs text-slate-500">{suggestionPreview.skipped.covered_by_pantry} {$t("ingredient group(s) are covered by visible Pantry stock. Stock remains unchanged.")}</p>{/if}
											<p class="text-xs leading-5 text-slate-500">{$t("Adding suggestions copies items into this list only. Confirming will not change meal plans, recipes or Pantry stock.")}</p>
											<button type="button" onclick={importMealSuggestions} disabled={selectedSuggestionIds.length === 0 || suggestionImporting} class="min-h-11 w-full rounded-xl bg-violet-300 px-4 text-sm font-semibold text-slate-950 hover:bg-violet-200 disabled:opacity-50">{suggestionImporting ? 'Adding…' : `Add ${selectedSuggestionIds.length} selected item${selectedSuggestionIds.length === 1 ? '' : 's'} to this list`}</button>
										{/if}
									{/if}
								</div>
							{/if}
						</section>
					{/if}

					<div class="rounded-2xl border border-slate-800 bg-slate-900/55">
						<header class="flex flex-col gap-3 border-b border-slate-800 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
							<div class="min-w-0">
								<div class="flex flex-wrap items-center gap-2">
									<h2 class="truncate text-xl font-semibold text-white">{activeList.name}</h2>
									<span class="rounded-full border border-slate-700 px-2.5 py-1 text-[0.65rem] uppercase tracking-wide {activeList.visibility === 'household' ? 'text-cyan-200' : 'text-slate-400'}">{activeList.visibility}</span>
								</div>
								<p class="mt-1 text-xs text-slate-500">{activeList.open_item_count} {$t("left to buy ·")} {activeList.item_count} total</p>
							</div>
							<div class="flex flex-wrap items-center gap-2">
								<label class="sr-only" for="shopping-filter">{$t("Show items")}</label>
								<select id="shopping-filter" bind:value={filter} class="min-h-10 rounded-lg border border-slate-700 bg-slate-950 px-3 text-xs text-slate-200 focus:border-cyan-300 focus:outline-none">
									<option value="open">{$t("To buy")}</option>
									<option value="checked">{$t("Checked")}</option>
									<option value="all">{$t("All items")}</option>
								</select>
								<label class="sr-only" for="shopping-sort">{$t("Sort items")}</label>
								<select id="shopping-sort" bind:value={sortMode} class="min-h-10 rounded-lg border border-slate-700 bg-slate-950 px-3 text-xs text-slate-200 focus:border-cyan-300 focus:outline-none">
									<option value="manual">{$t("List order")}</option>
									<option value="name">{$t("Name A–Z")}</option>
								</select>
								{#if activeList.can_edit}
									<form onsubmit={renameList} class="flex gap-2">
										<label class="sr-only" for="shopping-list-name">{$t("Rename list")}</label>
										<input id="shopping-list-name" bind:value={activeList.name} maxlength="160" required class="min-h-10 w-32 rounded-lg border border-slate-700 bg-slate-950 px-2.5 text-xs text-slate-200 sm:w-40" />
										<button type="submit" disabled={saving} class="min-h-10 rounded-lg border border-slate-700 px-3 text-xs text-slate-300 hover:border-cyan-300/60">{$t("Rename")}</button>
									</form>
								{/if}
							</div>
						</header>

						{#if listLoading}
							<p class="p-6 text-sm text-slate-400">{$t("Loading items…")}</p>
						{:else if visibleItems.length}
							<ul aria-label={$t("Shopping items")} class="divide-y divide-slate-800/80">
								{#each visibleItems as item (item.id)}
									<li class="flex min-w-0 items-start gap-3 px-4 py-4 sm:items-center sm:px-5">
										<input type="checkbox" checked={item.is_checked} disabled={!item.can_edit} aria-label={item.is_checked ? 'Restore ' + item.name + ' to the list' : 'Check off ' + item.name} onchange={(event) => void setChecked(item, (event.currentTarget as HTMLInputElement).checked)} class="mt-1 h-5 w-5 shrink-0 rounded border-slate-600 bg-slate-950 text-cyan-300 focus:ring-2 focus:ring-cyan-300/40 disabled:opacity-50 sm:mt-0" />
										<div class="min-w-0 flex-1">
											<p class="break-words text-sm font-medium {item.is_checked ? 'text-slate-500 line-through' : 'text-white'}">{item.name}</p>
											<p class="mt-1 text-xs text-slate-500">{#if item.quantity}{formatMeasurement(item.quantity, item.unit)} {item.unit}{:else}{$t("Quantity not set")}{/if}{#if item.is_checked && item.checked_at}<span> {$t("· Checked")} {formatDate(item.checked_at)}</span>{/if}</p>
										</div>
										{#if item.can_edit}
											<div class="flex shrink-0 flex-wrap items-center justify-end gap-1">
												{#if item.is_checked}
													<button type="button" onclick={() => void setChecked(item, false)} class="min-h-10 rounded-lg border border-cyan-300/30 px-2.5 text-xs font-medium text-cyan-200 hover:bg-cyan-300/10">{$t("Put back")}</button>
												{:else}
													<button type="button" disabled={sortMode !== 'manual' || isFirstInGroup(item)} onclick={() => void moveItem(item, 'up')} aria-label={'Move ' + item.name + ' up'} class="min-h-10 w-10 rounded-lg border border-slate-700 text-sm text-slate-300 hover:border-cyan-300/60 disabled:opacity-30">↑</button>
													<button type="button" disabled={sortMode !== 'manual' || isLastInGroup(item)} onclick={() => void moveItem(item, 'down')} aria-label={'Move ' + item.name + ' down'} class="min-h-10 w-10 rounded-lg border border-slate-700 text-sm text-slate-300 hover:border-cyan-300/60 disabled:opacity-30">↓</button>
												{/if}
												<button type="button" onclick={() => startEdit(item)} class="min-h-10 rounded-lg border border-slate-700 px-2.5 text-xs text-slate-300 hover:border-cyan-300/60">{$t("Edit")}</button>
												<button type="button" onclick={() => void removeItem(item)} class="min-h-10 rounded-lg border border-slate-700 px-2.5 text-xs text-slate-400 hover:border-rose-300/60 hover:text-rose-200">{$t("Remove")}</button>
											</div>
										{/if}
									</li>
									{#if editingItemId === item.id}
										<li class="border-t border-slate-800 bg-slate-950/50 px-4 py-4 sm:px-5">
											<form onsubmit={(event) => saveItem(event, item)} class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_8rem_8rem_auto_auto]">
												<label class="text-xs font-medium text-slate-400">{$t("Item name")}
													<input bind:value={editName} maxlength="180" required class="mt-1 min-h-11 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white" />
												</label>
												<label class="text-xs font-medium text-slate-400">{$t("Quantity")}
													<input bind:value={editQuantity} type="text" inputmode="decimal" maxlength="15" class="mt-1 min-h-11 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white" />
												</label>
												<label class="text-xs font-medium text-slate-400">{$t("Unit")}
													<input bind:value={editUnit} maxlength="32" class="mt-1 min-h-11 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white" />
												</label>
												<button type="submit" disabled={saving} class="min-h-11 self-end rounded-lg bg-cyan-300 px-4 text-xs font-semibold text-slate-950">{$t("Save")}</button>
												<button type="button" onclick={() => (editingItemId = null)} class="min-h-11 self-end rounded-lg border border-slate-700 px-4 text-xs text-slate-300">{$t("Cancel")}</button>
											</form>
										</li>
									{/if}
								{/each}
							</ul>
						{:else}
							<div class="px-5 py-10 text-center">
								<p class="text-sm font-medium text-white">{items.length === 0 ? 'This list is ready for its first item.' : filter === 'open' ? 'Everything is checked off.' : 'No items match this view.'}</p>
								<p class="mt-1 text-xs text-slate-500">{$t(filter === 'checked' && checkedItems.length ? 'Checked items can be put back on the list at any time.' : 'Use Quick add above to keep the list current.')}</p>
							</div>
						{/if}
					</div>

					<section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/40">
						<button type="button" aria-expanded={historyOpen} onclick={() => void toggleHistory()} class="flex min-h-12 w-full items-center justify-between gap-4 px-4 text-left text-sm font-medium text-slate-200 hover:bg-slate-900 sm:px-5">
							<span>{$t("List activity")}</span><span class="text-xs text-slate-500">{$t(historyOpen ? 'Hide' : 'Show last 100 events')} <span aria-hidden="true">{$t(historyOpen ? '−' : '+')}</span></span>
						</button>
						{#if historyOpen}
							<div class="border-t border-slate-800 px-4 py-3 sm:px-5">
								{#if historyLoading}
									<p class="py-3 text-xs text-slate-500">{$t("Loading activity…")}</p>
								{:else if activity.length}
									<ol class="divide-y divide-slate-800/70">
										{#each activity as entry (entry.id)}
											<li class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
												<span class="break-words text-xs text-slate-300">{eventLabel(entry)}</span>
												<time class="shrink-0 text-[0.68rem] text-slate-500">{formatDate(entry.created_at)}</time>
											</li>
										{/each}
									</ol>
								{:else}
									<p class="py-3 text-xs text-slate-500">{$t("No list activity yet.")}</p>
								{/if}
							</div>
						{/if}
					</section>
				</section>
			{/if}
		</div>
	{/if}
</div>
