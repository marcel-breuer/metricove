<script lang="ts">
	import { segmented, segmentedButton } from '#lib/ui/styles';
	import Notice from '#lib/ui/Notice.svelte';
	import Button from '#lib/ui/Button.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import { t } from '#lib/i18n';
	import { formatDate as formatLocaleDate } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import Modal from '#lib/ui/Modal.svelte';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';
	import { parseMealPlanDate, type MealDishType, type MealPlanSlot, type MealPlanVisibility, type MealPlanWeek, type MealType } from '#lib/meal-planning';
	import type { Recipe, RecipeResponse } from '#lib/recipes';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'meal_planning'));
	const recipeAccess = $derived(shell.modules.find((module) => module.key === 'recipes'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreate = $derived(access?.capabilities.create ?? false);
	const canManageShared = $derived(shell.currentUser?.is_admin === true && (access?.capabilities.manage ?? false));
	const canAdd = $derived(canCreate || canManageShared);
	const canViewRecipes = $derived(recipeAccess?.capabilities.view ?? false);
	const mealTypes: { key: MealType; label: string }[] = [
		{ key: 'breakfast', label: 'Breakfast' },
		{ key: 'lunch', label: 'Lunch' },
		{ key: 'dinner', label: 'Dinner' }
	];

	let weekStart = $state(mondayOf(new Date()));
	let week = $state<MealPlanWeek | null>(null);
	let recipes = $state<Recipe[]>([]);
	let loading = $state(true);
	let saving = $state(false);
	let copyingWeek = $state(false);
	let copyPreviousWeekOpen = $state(false);
	let error = $state('');
	let message = $state('');
	let messageParams = $state<Record<string, string | number>>({});
	let editorOpen = $state(false);
	let slotToRemove = $state<MealPlanSlot | null>(null);
	let removeOpen = $state(false);
	let editingSlotId = $state<number | null>(null);
	let plannedFor = $state('');
	let mealType = $state<MealType>('dinner');
	let dishType = $state<MealDishType>('free_text');
	let originalDishType = $state<MealDishType>('free_text');
	let dishName = $state('');
	let originalDishName = $state('');
	let recipeId = $state('');
	let originalRecipeId = $state('');
	let servings = $state('2');
	let notes = $state('');
	let visibility = $state<MealPlanVisibility>('private');

	const days = $derived(Array.from({ length: 7 }, (_, index) => addDays(weekStart, index)));
	const recipeChoices = $derived(recipes);
	const isCurrentWeek = $derived(weekStart === mondayOf(new Date()));

	$effect(() => {
		if (!shell.loading && canView) void loadWeek();
	});

	$effect(() => {
		if (!shell.loading && canViewRecipes) void loadRecipes();
		else if (!canViewRecipes) recipes = [];
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
		if (!response.ok) throw new Error(firstError(result) || result.message || 'Meal planning could not complete that request.');
		return result as T;
	}

	async function loadWeek(): Promise<void> {
		loading = true;
		error = '';
		try {
			week = await api<MealPlanWeek>(`/api/meal-planning?week_start=${weekStart}`);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The meal plan could not be loaded.';
		} finally {
			loading = false;
		}
	}

	async function loadRecipes(): Promise<void> {
		try {
			const result = await api<RecipeResponse>('/api/recipes');
			recipes = result.recipes ?? [];
		} catch {
			// Recipe details remain optional; the API will still hide inaccessible linked titles.
			recipes = [];
		}
	}

	async function copyPreviousWeek(): Promise<void> {
		if (!canCreate || !isCurrentWeek || copyingWeek) return;
		error = ''; message = ''; messageParams = {};
		copyingWeek = true;
		try {
			const result = await api<{ copied_count: number; skipped_count: number }>('/api/meal-planning/copy-previous-week', 'POST', { week_start: weekStart });
			copyPreviousWeekOpen = false;
			if (result.copied_count === 0 && result.skipped_count === 0) {
				message = 'There are no meals in the previous week to copy.';
			} else if (result.copied_count === 0) {
				message = 'All meals from the previous week already have a plan this week.';
			} else if (result.skipped_count > 0) {
				message = 'Copied {count} meals. Existing meals were left unchanged ({skipped}).';
				messageParams = { count: result.copied_count, skipped: result.skipped_count };
			} else {
				message = 'Copied {count} meals from the previous week.';
				messageParams = { count: result.copied_count };
			}
			await loadWeek();
		} catch (cause) {
			copyPreviousWeekOpen = false;
			error = cause instanceof Error ? cause.message : 'The previous week could not be copied.';
		} finally { copyingWeek = false; }
	}

	function openNewSlot(day: string, type: MealType): void {
		resetEditor();
		plannedFor = day;
		mealType = type;
		visibility = canCreate ? 'private' : 'household';
		editorOpen = true;
		message = '';
	}

	function openEdit(slot: MealPlanSlot): void {
		editingSlotId = slot.id;
		plannedFor = slot.planned_for;
		mealType = slot.meal_type;
		dishType = slot.dish_type;
		originalDishType = slot.dish_type;
		dishName = slot.dish_name ?? '';
		originalDishName = slot.dish_name ?? '';
		recipeId = slot.recipe ? String(slot.recipe.id) : '';
		originalRecipeId = slot.recipe ? String(slot.recipe.id) : '';
		servings = String(slot.servings);
		notes = slot.notes ?? '';
		visibility = slot.visibility;
		error = '';
		message = '';
		editorOpen = true;
	}

	function resetEditor(): void {
		editingSlotId = null;
		dishType = 'free_text';
		originalDishType = 'free_text';
		dishName = '';
		originalDishName = '';
		recipeId = '';
		originalRecipeId = '';
		servings = '2';
		notes = '';
		error = '';
	}

	function closeEditor(): void {
		editorOpen = false;
		resetEditor();
	}

	async function saveSlot(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		error = '';
		message = '';
		const payload: Record<string, string | number> = {
			planned_for: plannedFor,
			meal_type: mealType,
			dish_type: dishType,
			servings: Number(servings),
			notes
		};
		const dishChanged = editingSlotId === null || dishType !== originalDishType
			|| (dishType === 'recipe' && recipeId !== originalRecipeId)
			|| (dishType === 'free_text' && dishName !== originalDishName);
		if (dishChanged) {
			payload.dish_type = dishType;
			if (dishType === 'recipe') payload.recipe_id = Number(recipeId);
			else payload.dish_name = dishName;
		}
		if (editingSlotId === null) payload.visibility = visibility;

		saving = true;
		try {
			await api(editingSlotId === null ? '/api/meal-planning/slots' : `/api/meal-planning/slots/${editingSlotId}`, editingSlotId === null ? 'POST' : 'PATCH', payload);
			message = editingSlotId === null ? 'Meal added to the week.' : 'Meal plan updated.';
			closeEditor();
			await loadWeek();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The meal could not be saved.';
		} finally {
			saving = false;
		}
	}

	async function removeSlot(slot: MealPlanSlot): Promise<void> {
		error = '';
		message = '';
		try {
			await api(`/api/meal-planning/slots/${slot.id}`, 'DELETE');
			message = 'Meal removed from the plan.';
			slotToRemove = null;
			removeOpen = false;
			if (editingSlotId === slot.id) closeEditor();
			await loadWeek();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The meal could not be removed.';
		}
	}

	function slotsFor(day: string): MealPlanSlot[] {
		return (week?.slots ?? []).filter((slot) => slot.planned_for === day);
	}

	function slotFor(day: string, type: MealType): MealPlanSlot | undefined {
		return slotsFor(day).find((slot) => slot.meal_type === type);
	}

	function changeWeek(amount: number): void {
		weekStart = addDays(weekStart, amount * 7);
		closeEditor();
		message = '';
	}

	function formatDay(day: string, options: Intl.DateTimeFormatOptions): string {
		const date = parseMealPlanDate(day);
		return date ? formatLocaleDate(date, options) : '';
	}

	function mondayOf(date: Date): string {
		const local = new Date(date.getFullYear(), date.getMonth(), date.getDate());
		const offset = (local.getDay() + 6) % 7;
		local.setDate(local.getDate() - offset);
		return localDateKey(local);
	}

	function addDays(day: string, amount: number): string {
		const date = new Date(`${day}T00:00:00`);
		date.setDate(date.getDate() + amount);
		return localDateKey(date);
	}

	function localDateKey(date: Date): string {
		return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
	}
</script>

<svelte:head>
	<title>{$t("Meal Planning")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Plan the week with free-text dishes or recipes you can access.")} />
</svelte:head>

<div class="mx-auto max-w-[90rem] space-y-8">
	<PageHeading eyebrow="Food · Meal planning" title="A week that works" description="Plan breakfast, lunch and dinner with a saved recipe or a simple dish name. Your plan works on its own.">
		{#snippet actions()}
			<div class="{segmented}" role="group" aria-label={$t("Week")}>
				<button type="button" onclick={() => changeWeek(-1)} aria-label={$t("Previous week")} class="{segmentedButton}"><span aria-hidden="true">‹</span></button>
				<div class="min-w-0 flex-1 px-2 text-center text-sm font-medium text-ink tabular-nums" aria-live="polite">{formatDay(weekStart, { month: 'short', day: 'numeric' })} – {formatDay(addDays(weekStart, 6), { month: 'short', day: 'numeric', year: 'numeric' })}</div>
				<button type="button" onclick={() => changeWeek(1)} aria-label={$t("Next week")} class="{segmentedButton}"><span aria-hidden="true">›</span></button>
			</div>
			<Button variant="secondary" disabled={isCurrentWeek} onclick={() => (weekStart = mondayOf(new Date()))}>{$t("This week")}</Button>
			{#if canCreate && isCurrentWeek}<Button variant="secondary" onclick={() => (copyPreviousWeekOpen = true)} disabled={copyingWeek}>{$t("Copy previous week")}</Button>{/if}
		{/snippet}
	</PageHeading>

	{#if message}<p role="status" class="rounded-xl border border-positive/30 bg-positive/10 px-4 py-3 text-sm text-positive">{$t(message, messageParams)}</p>{/if}
	{#if error}<Notice tone="error" message={error} />{/if}

	<Modal bind:open={editorOpen} size="wide" title={$t(editingSlotId === null ? 'Add to the plan' : 'Edit planned meal')} description={plannedFor ? formatDay(plannedFor, { weekday: 'long', month: 'long', day: 'numeric' }) + ' · ' + $t(mealTypes.find((entry) => entry.key === mealType)?.label ?? '') : ''}>
		{#if error}<Notice tone="error" message={error} class="mb-5" />{/if}
		<form id="meal-slot-form" onsubmit={saveSlot} class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4">
			<label class="text-xs font-medium text-ink-muted">{$t("Day")}
				<input bind:value={plannedFor} type="date" required class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" />
			</label>
			<label class="text-xs font-medium text-ink-muted">{$t("Meal")}
				<select bind:value={mealType} class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base">
					{#each mealTypes as entry}<option value={entry.key}>{$t(entry.label)}</option>{/each}
				</select>
			</label>
			{#if editingSlotId !== null && dishType === 'recipe' && recipeId === ''}
				<div class="sm:col-span-2 rounded-xl border border-line bg-page px-4 py-3 text-sm leading-6 text-ink-muted">
					{$t("The linked recipe is not available to your account right now. You can update servings or notes, or convert this slot to a dish name.")}
					<button type="button" onclick={() => { dishType = 'free_text'; dishName = ''; }} class="mt-2 block min-h-9 text-xs font-semibold text-accent-ink hover:text-accent-ink">{$t("Convert to a dish name")}</button>
				</div>
			{:else}<label class="text-xs font-medium text-ink-muted">{$t("Dish source")}
				<select bind:value={dishType} class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base">
					<option value="free_text">{$t("Write a dish name")}</option>
					{#if canViewRecipes}<option value="recipe">{$t("Link a recipe")}</option>{/if}
				</select>
			</label>{/if}
			<label class="text-xs font-medium text-ink-muted">{$t("Servings")}
				<input bind:value={servings} type="number" min="1" max="1000" required class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" />
			</label>
			{#if dishType === 'recipe' && (canViewRecipes || editingSlotId === null)}
				<label class="text-xs font-medium text-ink-muted sm:col-span-2">{$t("Recipe you can view")}
					<select bind:value={recipeId} required class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base">
						<option value="">{$t("Choose a recipe")}</option>
						{#each recipeChoices as recipe}<option value={recipe.id}>{recipe.title}</option>{/each}
					</select>
				</label>
			{:else if dishType === 'free_text'}
				<label class="text-xs font-medium text-ink-muted sm:col-span-2">{$t("Dish name")}
					<input bind:value={dishName} maxlength="180" required placeholder={$t("For example, tomato soup")} class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink placeholder:text-ink-subtle focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" />
				</label>
			{/if}
			<label class="text-xs font-medium text-ink-muted sm:col-span-2">{$t("Note")} <span class="font-normal text-ink-subtle">{$t("(optional)")}</span>
				<input bind:value={notes} maxlength="2000" placeholder={$t("Anything to remember?")} class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink placeholder:text-ink-subtle focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" />
			</label>
			{#if editingSlotId === null && canManageShared && canCreate}
				<label class="text-xs font-medium text-ink-muted">{$t("Who can see it?")}
					<select bind:value={visibility} class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base">
						<option value="private">{$t("Only me")}</option>
						<option value="household">{$t("Household plan")}</option>
					</select>
				</label>
			{/if}
		</form>
		{#snippet footer()}
			<Button variant="secondary" type="button" disabled={saving} onclick={closeEditor}>{$t("Cancel")}</Button>
			<Button type="submit" form="meal-slot-form" disabled={saving}>{$t(saving ? 'Saving…' : editingSlotId === null ? 'Add meal' : 'Save changes')}</Button>
		{/snippet}
	</Modal>

	{#if shell.loading || loading}
		<div class="rounded-2xl border glass-surface p-7 text-sm text-ink-muted">{$t("Loading your week…")}</div>
	{:else if !canView}
		<div class="rounded-2xl border glass-surface p-7 text-sm leading-6 text-ink-muted">{$t("Meal Planning is enabled, but your account does not have View access. Ask a household administrator for MealPlanning View permission.")}</div>
	{:else}
		<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
			{#each days as day (day)}
				<article class="min-w-0 rounded-[20px] border glass-surface p-3 sm:p-4">
					<header class="mb-3 border-b border-line pb-3">
						<p class="text-xs font-semibold text-accent-ink">{formatDay(day, { weekday: 'short' })}</p>
						<h2 class="mt-1 text-lg font-semibold text-ink">{formatDay(day, { month: 'short', day: 'numeric' })}</h2>
					</header>
					<div class="space-y-3">
						{#each mealTypes as entry (entry.key)}
							{@const slot = slotFor(day, entry.key)}
							<section aria-label={$t(entry.label)} class="min-h-24 rounded-xl border border-line bg-page p-3">
								<div class="flex items-center justify-between gap-2">
									<h3 class="text-[0.68rem] font-semibold text-ink-subtle">{$t(entry.label)}</h3>
									{#if slot && slot.visibility === 'household'}<span class="text-[0.65rem] text-accent-ink">{$t("Household")}</span>{/if}
								</div>
								{#if slot}
									<p class="mt-2 break-words text-sm font-medium leading-5 text-ink">{slot.dish_label}</p>
									<p class="mt-1 text-xs text-ink-subtle">{slot.servings} {$t(slot.servings === 1 ? 'serving' : 'servings')}{#if slot.notes}<span> · {slot.notes}</span>{/if}</p>
									{#if slot.can_edit}
										<div class="mt-3 flex gap-2">
											<button type="button" onclick={() => openEdit(slot)} class="min-h-9 rounded-lg border border-line-strong px-2.5 text-xs text-ink-secondary hover:border-accent-ink/60">{$t("Edit")}</button>
											<button type="button" onclick={() => { slotToRemove = slot; removeOpen = true; }} class="min-h-9 rounded-lg border border-line-strong px-2.5 text-xs text-ink-muted hover:border-danger/60 hover:text-danger">{$t("Remove")}</button>
										</div>
									{/if}
								{:else if canAdd}
									<button type="button" onclick={() => openNewSlot(day, entry.key)} class="mt-3 min-h-9 w-full rounded-lg border border-dashed border-line-strong px-2 text-xs font-medium text-ink-muted hover:border-accent-ink/60 hover:text-accent-ink">+ Plan meal</button>
								{:else}
									<p class="mt-3 text-xs text-ink-subtle">{$t("Not planned")}</p>
								{/if}
							</section>
						{/each}
					</div>
				</article>
			{/each}
		</div>
	{/if}

	<footer class="rounded-2xl border glass-surface px-5 py-4 text-xs leading-5 text-ink-subtle">
		{$t("Meal plans do not change shopping lists. Recipe links appear only when you can view both Meal Planning and that recipe.")}
	</footer>
	{#if slotToRemove}<ConfirmModal bind:open={removeOpen} title={$t('Remove planned meal?')} message={$t('Remove this meal from the plan?')} onConfirm={() => void removeSlot(slotToRemove!)} />{/if}
	{#if copyPreviousWeekOpen}<ConfirmModal bind:open={copyPreviousWeekOpen} title={$t('Copy previous week?')} message={$t('Copy visible meals from last week into empty slots this week? Existing meals will stay unchanged. Copied meals will be saved to your private plan.')} confirmLabel={$t('Copy previous week')} busy={copyingWeek} onConfirm={() => void copyPreviousWeek()} />{/if}
</div>
