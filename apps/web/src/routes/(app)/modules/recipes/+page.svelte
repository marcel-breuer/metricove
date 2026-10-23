<script lang="ts">
	import { t } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { formatMeasurement } from '#lib/number-format';
	import type { Recipe, RecipeIngredientInput, RecipeResponse, RecipeVisibility } from '#lib/recipes';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'recipes'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreate = $derived(access?.capabilities.create ?? false);
	const canManageShared = $derived(shell.currentUser?.is_admin === true && (access?.capabilities.manage ?? false));
	const canAddRecipe = $derived(canCreate || canManageShared);

	let recipes = $state<Recipe[]>([]);
	let loading = $state(true);
	let saving = $state(false);
	let error = $state('');
	let message = $state('');
	let formError = $state('');
	let search = $state('');
	let categoryFilter = $state('all');
	let favoriteOnly = $state(false);
	let recipeId = $state<number | null>(null);
	let title = $state('');
	let category = $state('');
	let servings = $state('2');
	let preparationMinutes = $state('');
	let instructions = $state('');
	let visibility = $state<RecipeVisibility>('private');
	let ingredients = $state<RecipeIngredientInput[]>([{ name: '', quantity: '', unit: '', note: '' }]);
	let photoFile = $state<File | null>(null);
	let existingPhoto = $state<string | null>(null);
	let removePhoto = $state(false);
	let deleteTarget = $state<number | null>(null);
	const categories = $derived([...new Set(recipes.map((recipe) => recipe.category).filter((value): value is string => Boolean(value)))].sort());
	const visibleRecipes = $derived(recipes.filter((recipe) => {
		const matchesSearch = `${recipe.title} ${recipe.category ?? ''} ${recipe.ingredients.map((item) => item.name).join(' ')}`
			.toLowerCase().includes(search.trim().toLowerCase());
		return matchesSearch && (categoryFilter === 'all' || recipe.category === categoryFilter) && (!favoriteOnly || recipe.is_favorite);
	}));
	const formVisibilityOptions = $derived(canCreate && canManageShared ? ['private', 'household'] : canManageShared ? ['household'] : ['private']);

	$effect(() => {
		if (!shell.loading) void loadRecipes();
	});

	async function api<T extends RecipeResponse>(path: string, method = 'GET', body?: object | FormData): Promise<T> {
		const headers: Record<string, string> = { Accept: 'application/json' };
		if (method !== 'GET') {
			const token = csrfToken();
			if (!token) throw new Error('Reload this page and try again.');
			headers['X-XSRF-TOKEN'] = token;
			if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
		}
		const response = await fetch(path, {
			method,
			credentials: 'same-origin',
			headers,
			...(body ? { body: body instanceof FormData ? body : JSON.stringify(body) } : {})
		});
		if (response.status === 401) {
			await goto('/login');
			throw new Error('Your session has expired.');
		}
		const result = (await response.json()) as T & ApiResponse;
		if (!response.ok) throw new Error(firstError(result) || result.message || 'Recipes could not complete that request.');
		return result as T;
	}

	async function loadRecipes(): Promise<void> {
		loading = true;
		error = '';
		if (!canView) {
			recipes = [];
			loading = false;
			return;
		}
		try {
			const result = await api<RecipeResponse>('/api/recipes');
			recipes = result.recipes ?? [];
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Recipes could not be loaded.';
		} finally {
			loading = false;
		}
	}

	function resetForm(): void {
		recipeId = null;
		title = '';
		category = '';
		servings = '2';
		preparationMinutes = '';
		instructions = '';
		visibility = canCreate ? 'private' : 'household';
		ingredients = [{ name: '', quantity: '', unit: '', note: '' }];
		photoFile = null;
		existingPhoto = null;
		removePhoto = false;
		formError = '';
	}

	function editRecipe(recipe: Recipe): void {
		message = '';
		formError = '';
		recipeId = recipe.id;
		title = recipe.title;
		category = recipe.category ?? '';
		servings = String(recipe.servings);
		preparationMinutes = recipe.preparation_minutes === null ? '' : String(recipe.preparation_minutes);
		instructions = recipe.instructions;
		visibility = recipe.visibility;
		ingredients = recipe.ingredients.map((item) => ({
			name: item.name,
			quantity: item.quantity.replace(/0+$/, '').replace(/\.$/, ''),
			unit: item.unit,
			note: item.note ?? ''
		}));
		photoFile = null;
		existingPhoto = recipe.photo_url;
		removePhoto = false;
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	function addIngredient(): void {
		ingredients = [...ingredients, { name: '', quantity: '', unit: '', note: '' }];
	}

	function removeIngredient(index: number): void {
		if (ingredients.length > 1) ingredients = ingredients.filter((_, current) => current !== index);
	}

	async function saveRecipe(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		message = '';
		formError = '';
		const form = new FormData();
		form.set('title', title);
		form.set('category', category);
		form.set('servings', servings);
		form.set('preparation_minutes', preparationMinutes);
		form.set('instructions', instructions);
		form.set('ingredients', JSON.stringify(ingredients.map((item) => ({
			name: item.name, quantity: item.quantity, unit: item.unit, note: item.note
		}))));
		if (recipeId === null) form.set('visibility', visibility);
		if (photoFile) form.set('photo', photoFile);
		if (removePhoto) form.set('remove_photo', '1');
		if (recipeId !== null) form.set('_method', 'PATCH');
		saving = true;
		try {
			await api<RecipeResponse>(recipeId === null ? '/api/recipes' : `/api/recipes/${recipeId}`, 'POST', form);
			message = recipeId === null ? 'Recipe created.' : 'Recipe updated.';
			resetForm();
			await loadRecipes();
		} catch (cause) {
			formError = cause instanceof Error ? cause.message : 'The recipe could not be saved.';
		} finally {
			saving = false;
		}
	}

	async function toggleFavorite(recipe: Recipe): Promise<void> {
		message = '';
		error = '';
		try {
			if (recipe.is_favorite) await api<RecipeResponse>(`/api/recipes/${recipe.id}/favorite`, 'DELETE');
			else await api<RecipeResponse>(`/api/recipes/${recipe.id}/favorite`, 'PUT');
			await loadRecipes();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The favorite could not be updated.';
		}
	}

	async function deleteRecipe(id: number): Promise<void> {
		error = '';
		try {
			await api<RecipeResponse>(`/api/recipes/${id}`, 'DELETE');
			deleteTarget = null;
			if (recipeId === id) resetForm();
			message = 'Recipe deleted.';
			await loadRecipes();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The recipe could not be deleted.';
		}
	}

	function choosePhoto(event: Event): void {
		photoFile = (event.currentTarget as HTMLInputElement).files?.[0] ?? null;
		if (photoFile) removePhoto = false;
	}
</script>

<svelte:head>
	<title>{$t("Recipes")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Keep household recipes, measured ingredients and preparation notes together.")} />
</svelte:head>

<div class="mx-auto max-w-7xl space-y-8 px-4 py-6 sm:px-6 lg:px-8 lg:py-10">
	<header class="flex flex-col gap-5 border-b border-slate-800 pb-7 sm:flex-row sm:items-end sm:justify-between">
		<div>
			<p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-300">{$t("Food · Recipes")}</p>
			<h1 class="mt-3 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{$t("Recipes worth keeping")}</h1>
			<p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">{$t("A private collection for your household: clear quantities, useful notes and the way you like to make it.")}</p>
		</div>
		<div class="flex items-center gap-3 text-sm text-slate-400">
			<span class="rounded-full border border-slate-700 px-3 py-1.5">{recipes.length} {$t(recipes.length === 1 ? 'recipe' : 'recipes')}</span>
			{#if canAddRecipe}<a href="#recipe-form" class="inline-flex min-h-11 items-center rounded-xl bg-cyan-300 px-4 font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-100">{$t("Add a recipe")}</a>{/if}
		</div>
	</header>

	{#if message}<p role="status" class="rounded-xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{$t(message)}</p>{/if}
	{#if error}<p role="alert" class="rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">{$t(error)}</p>{/if}

	<div class="grid gap-8 lg:grid-cols-[minmax(0,1.35fr)_minmax(20rem,0.85fr)]">
		<section aria-labelledby="recipe-list-title" class="min-w-0 space-y-5">
			<div class="flex flex-col gap-3 sm:flex-row">
				<label class="min-w-0 flex-1 text-xs font-medium text-slate-400">{$t("Search recipes and ingredients")}
					<input bind:value={search} type="search" placeholder={$t("Try tomato, soup or breakfast")} class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 text-sm text-white placeholder:text-slate-600 focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20" />
				</label>
				<label class="text-xs font-medium text-slate-400">{$t("Category")}
					<select bind:value={categoryFilter} class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 text-sm text-white focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20 sm:min-w-40">
						<option value="all">{$t("All categories")}</option>
						{#each categories as recipeCategory}<option value={recipeCategory}>{recipeCategory}</option>{/each}
					</select>
				</label>
				<button type="button" aria-pressed={favoriteOnly} onclick={() => (favoriteOnly = !favoriteOnly)} class="mt-auto min-h-11 rounded-xl border px-4 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-200 {favoriteOnly ? 'border-amber-200/50 bg-amber-200/10 text-amber-100' : 'border-slate-700 text-slate-300 hover:border-slate-500'}">{$t("★ Favorites")}</button>
			</div>

			<h2 id="recipe-list-title" class="sr-only">{$t("Your recipes")}</h2>
			{#if loading}
				<div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 text-sm text-slate-400">{$t("Loading recipes…")}</div>
			{:else if !canView}
				<div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 text-sm leading-6 text-slate-400">{$t("Recipes is enabled, but your account does not have Recipes View access. Ask a household administrator to grant the required module permission.")}</div>
			{:else if visibleRecipes.length === 0}
				<div class="rounded-2xl border border-dashed border-slate-700 bg-slate-900/40 p-8 text-center sm:p-12">
					<p class="text-lg font-medium text-white">{$t(recipes.length === 0 ? 'Your recipe book is ready.' : 'No recipes match these filters.')}</p>
					<p class="mt-2 text-sm leading-6 text-slate-400">{$t(recipes.length === 0 ? 'Start with a dish you make often. Recipes stay independent from meal planning, pantry and shopping.' : 'Clear the search or favorite filter to see the rest of your collection.')}</p>
					{#if recipes.length === 0 && canAddRecipe}<button type="button" onclick={() => document.getElementById('recipe-title')?.focus()} class="mt-5 min-h-11 rounded-xl bg-cyan-300 px-4 text-sm font-semibold text-slate-950 hover:bg-cyan-200">{$t("Create the first recipe")}</button>{/if}
				</div>
			{:else}
				<div class="grid gap-4 xl:grid-cols-2">
					{#each visibleRecipes as recipe (recipe.id)}
						<article class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/75 shadow-lg shadow-black/10">
							{#if recipe.photo_url}<img src={recipe.photo_url} alt="Photo of {recipe.title}" loading="lazy" class="h-44 w-full border-b border-slate-800 object-cover" />{/if}
							<div class="space-y-4 p-5">
								<div class="flex items-start justify-between gap-3">
									<div class="min-w-0"><p class="text-xs font-medium uppercase tracking-[0.18em] text-cyan-300">{recipe.category ?? 'Uncategorized'} · {$t(recipe.visibility === 'household' ? 'Household' : 'Private')}</p><h3 class="mt-2 text-xl font-semibold leading-tight text-white">{recipe.title}</h3></div>
									<button type="button" aria-label={recipe.is_favorite ? `Remove ${recipe.title} from favorites` : `Add ${recipe.title} to favorites`} aria-pressed={recipe.is_favorite} onclick={() => void toggleFavorite(recipe)} class="min-h-11 min-w-11 rounded-xl border border-slate-700 text-xl transition hover:border-amber-200/50 hover:text-amber-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-100 {recipe.is_favorite ? 'text-amber-200' : 'text-slate-500'}">★</button>
								</div>
								<div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400"><span>{recipe.servings} servings</span>{#if recipe.preparation_minutes}<span>{recipe.preparation_minutes} min</span>{/if}<span>{recipe.ingredients.length} ingredients</span></div>
								<div><h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400">{$t("Ingredients")}</h4><ul class="mt-2 space-y-1.5 text-sm text-slate-200">{#each recipe.ingredients as ingredient (ingredient.id)}<li class="flex gap-2"><span class="shrink-0 text-cyan-300">{formatMeasurement(ingredient.quantity, ingredient.unit)} {ingredient.unit}</span><span>{ingredient.name}{#if ingredient.note}<span class="text-slate-500"> · {ingredient.note}</span>{/if}</span></li>{/each}</ul></div>
								<div><h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400">{$t("Preparation")}</h4><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-300">{recipe.instructions}</p></div>
								{#if recipe.can_edit}
									<div class="flex flex-wrap items-center gap-2 border-t border-slate-800 pt-3">
										<button type="button" onclick={() => editRecipe(recipe)} class="min-h-10 rounded-lg border border-slate-700 px-3 text-sm font-medium text-slate-200 hover:border-cyan-300/50 hover:text-cyan-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-200">{$t("Edit")}</button>
										{#if deleteTarget === recipe.id}<span class="text-xs text-rose-200">{$t("Delete this recipe?")}</span><button type="button" onclick={() => void deleteRecipe(recipe.id)} class="min-h-10 rounded-lg bg-rose-400/15 px-3 text-sm font-semibold text-rose-200 hover:bg-rose-400/25">{$t("Confirm delete")}</button><button type="button" onclick={() => (deleteTarget = null)} class="min-h-10 px-2 text-sm text-slate-400 hover:text-white">{$t("Cancel")}</button>{:else}<button type="button" onclick={() => (deleteTarget = recipe.id)} class="min-h-10 px-3 text-sm text-slate-400 hover:text-rose-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-200">{$t("Delete")}</button>{/if}
									</div>
								{/if}
							</div>
						</article>
					{/each}
				</div>
			{/if}
		</section>

		{#if canAddRecipe}
			<aside id="recipe-form" class="h-fit rounded-2xl border border-slate-800 bg-slate-900/80 p-5 shadow-xl shadow-black/15 sm:p-6 lg:sticky lg:top-6">
				<div class="flex items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-300">{$t("Recipe editor")}</p><h2 class="mt-2 text-xl font-semibold text-white">{$t(recipeId === null ? 'Add a recipe' : 'Edit recipe')}</h2></div>{#if recipeId !== null}<button type="button" onclick={resetForm} class="min-h-10 px-2 text-sm text-slate-400 hover:text-white">{$t("Cancel edit")}</button>{/if}</div>
				{#if formError}<p role="alert" class="mt-4 rounded-xl border border-rose-400/30 bg-rose-400/10 px-3 py-2.5 text-sm text-rose-200">{$t(formError)}</p>{/if}
				<form onsubmit={saveRecipe} class="mt-5 space-y-4">
					<label class="block text-sm font-medium text-slate-200">{$t("Title")}<input id="recipe-title" bind:value={title} required maxlength="180" class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20" /></label>
					<div class="grid grid-cols-2 gap-3"><label class="block text-sm font-medium text-slate-200">{$t("Category")} <span class="text-xs font-normal text-slate-500">optional</span><input bind:value={category} maxlength="80" placeholder={$t("Dinner")} class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white placeholder:text-slate-600 focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20" /></label><label class="block text-sm font-medium text-slate-200">{$t("Servings")}<input bind:value={servings} type="number" min="1" max="1000" required class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20" /></label></div>
					<div class="grid grid-cols-2 gap-3"><label class="block text-sm font-medium text-slate-200">{$t("Prep time")} <span class="text-xs font-normal text-slate-500">minutes</span><input bind:value={preparationMinutes} type="number" min="1" max="1440" placeholder={$t("Optional")} class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white placeholder:text-slate-600 focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20" /></label>
						{#if recipeId === null && formVisibilityOptions.length > 1}<label class="block text-sm font-medium text-slate-200">{$t("Visibility")}<select bind:value={visibility} class="mt-1.5 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20"><option value="private">{$t("Private")}</option><option value="household">{$t("Household")}</option></select></label>{:else}<div class="flex items-end pb-3 text-xs leading-5 text-slate-500">{recipeId !== null ? `Visibility: ${visibility === 'private' ? 'private' : 'household'}` : canManageShared && !canCreate ? 'Shared recipes are managed by household admins.' : 'New recipes are private to you.'}</div>{/if}
					</div>
					<fieldset class="space-y-3"><legend class="text-sm font-semibold text-slate-200">{$t("Ingredients")}</legend>
						{#each ingredients as ingredient, index (index)}
							<div class="rounded-xl border border-slate-800 bg-slate-950/60 p-3">
								<div class="grid grid-cols-[minmax(0,1fr)_auto] gap-2 sm:grid-cols-[minmax(0,1fr)_6rem_6rem_auto]"><label class="min-w-0 text-xs text-slate-400">{$t("Ingredient")}<input bind:value={ingredient.name} required maxlength="120" placeholder={$t("Pasta")} class="mt-1 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-900 px-2.5 text-sm text-white placeholder:text-slate-600 focus:border-cyan-300 focus:outline-none" /></label><label class="order-3 text-xs text-slate-400 sm:order-none">{$t("Quantity")}<input bind:value={ingredient.quantity} required type="number" min="0.0001" step="0.0001" inputmode="decimal" placeholder="250" class="mt-1 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-900 px-2 text-sm text-white placeholder:text-slate-600 focus:border-cyan-300 focus:outline-none" /></label><label class="order-4 text-xs text-slate-400 sm:order-none">{$t("Unit")}<input bind:value={ingredient.unit} required maxlength="40" placeholder="g" class="mt-1 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-900 px-2 text-sm text-white placeholder:text-slate-600 focus:border-cyan-300 focus:outline-none" /></label><button type="button" aria-label={$t("Remove ingredient")} disabled={ingredients.length === 1} onclick={() => removeIngredient(index)} class="order-2 mt-5 min-h-10 min-w-9 rounded-lg text-slate-500 hover:bg-rose-400/10 hover:text-rose-200 disabled:opacity-30 sm:order-none">×</button></div>
								<label class="mt-2 block text-xs text-slate-400">{$t("Note")} <span class="text-slate-600">optional</span><input bind:value={ingredient.note} maxlength="160" placeholder={$t("finely grated")} class="mt-1 min-h-9 w-full rounded-lg border border-slate-700 bg-slate-900 px-2.5 text-sm text-white placeholder:text-slate-600 focus:border-cyan-300 focus:outline-none" /></label>
							</div>
						{/each}
						<button type="button" onclick={addIngredient} disabled={ingredients.length >= 100} class="min-h-10 rounded-lg border border-dashed border-slate-700 px-3 text-sm text-cyan-200 hover:border-cyan-300/50 disabled:opacity-40">+ Add ingredient</button>
					</fieldset>
					<label class="block text-sm font-medium text-slate-200">{$t("Preparation instructions")}<textarea bind:value={instructions} required maxlength="20000" rows="5" placeholder={$t("Write the steps in the order you follow them…")} class="mt-1.5 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm leading-6 text-white placeholder:text-slate-600 focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20"></textarea></label>
					<div><label class="block text-sm font-medium text-slate-200">{$t("Recipe photo")} <span class="text-xs font-normal text-slate-500">{$t("optional · JPEG, PNG or WebP · 5 MB max")}</span><input type="file" accept="image/jpeg,image/png,image/webp" onchange={choosePhoto} class="mt-1.5 block min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-800 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-200 focus:border-cyan-300 focus:outline-none" /></label>
						{#if photoFile}<p class="mt-1 text-xs text-slate-400">{$t("Selected:")} {photoFile.name}</p>{:else if existingPhoto && !removePhoto}<div class="mt-2 flex items-center gap-3"><img src={existingPhoto} alt={$t("Current recipe")} class="h-14 w-20 rounded-lg object-cover" /><button type="button" onclick={() => (removePhoto = true)} class="min-h-9 px-2 text-xs text-slate-400 hover:text-rose-200">{$t("Remove current photo")}</button></div>{:else if removePhoto}<p class="mt-2 text-xs text-slate-400">{$t("The current photo will be removed when you save.")}</p>{/if}</div>
					<div class="border-t border-slate-800 pt-4"><p class="text-xs leading-5 text-slate-500">{$t("Private recipes and photos remain hidden from administrators unless you grant access. Household recipes require an active admin with Recipes Manage.")}</p><div class="mt-4 flex gap-2"><button type="submit" disabled={saving} class="min-h-11 flex-1 rounded-xl bg-cyan-300 px-4 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-100 disabled:cursor-wait disabled:opacity-60">{saving ? 'Saving…' : recipeId === null ? 'Save recipe' : 'Save changes'}</button>{#if recipeId !== null}<button type="button" onclick={resetForm} class="min-h-11 rounded-xl border border-slate-700 px-4 text-sm text-slate-300 hover:border-slate-500">{$t("Cancel")}</button>{/if}</div></div>
				</form>
			</aside>
		{:else if canView}
			<aside class="h-fit rounded-2xl border border-slate-800 bg-slate-900/60 p-5 text-sm leading-6 text-slate-400 lg:sticky lg:top-6">{$t("Your account can view recipes. Ask an administrator for Recipes Create to add private recipes or Recipes Manage to create household recipes.")}</aside>
		{/if}
	</div>
</div>
