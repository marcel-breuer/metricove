<script lang="ts">
	import { buttonClasses, control, field, fieldLabel, fileInput, iconButton, panel } from '#lib/ui/styles';
	import Notice from '#lib/ui/Notice.svelte';
	import Badge from '#lib/ui/Badge.svelte';
	import EmptyState from '#lib/ui/EmptyState.svelte';
	import Button from '#lib/ui/Button.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import { tick } from 'svelte';
	import { t } from '#lib/i18n';
	import { getContext } from 'svelte';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import Modal from '#lib/ui/Modal.svelte';
	import { emptyRecipeIngredient, parseImportedIngredient, type Recipe, type RecipeDraft, type RecipeResponse, type RecipeVisibility } from '#lib/recipes';
	import RecipeEditor from './RecipeEditor.svelte';
	import { recipeRequest as api } from '#lib/api-client';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'recipes'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreate = $derived(access?.capabilities.create ?? false);
	const canManageShared = $derived(shell.currentUser?.is_admin === true && (access?.capabilities.manage ?? false));
	const canAddRecipe = $derived(canCreate || canManageShared);

	let recipes = $state<Recipe[]>([]);
	let loading = $state(true);
	let error = $state('');
	let message = $state('');
	let search = $state('');
	let categoryFilter = $state('all');
	let favoriteOnly = $state(false);
	let editorOpen = $state(false);
	let editorDraft = $state<RecipeDraft | null>(null);
	let importNotices = $state<string[]>([]);
	let importOpen = $state(false);
	let importType = $state<'url' | 'pdf'>('url');
	let importUrl = $state('');
	let importFile = $state<File | null>(null);
	let importVisibility = $state<RecipeVisibility>('private');
	let importBusy = $state(false);
	let importError = $state('');
	const categories = $derived([...new Set(recipes.map((recipe) => recipe.category).filter((value): value is string => Boolean(value)))].sort());
	const visibleRecipes = $derived(recipes.filter((recipe) => {
		const matchesSearch = `${recipe.title} ${recipe.category ?? ''} ${recipe.ingredients.map((item) => item.name).join(' ')}`
			.toLowerCase().includes(search.trim().toLowerCase());
		return matchesSearch && (categoryFilter === 'all' || recipe.category === categoryFilter) && (!favoriteOnly || recipe.is_favorite);
	}));
	const formVisibilityOptions = $derived<RecipeVisibility[]>(canCreate && canManageShared ? ['private', 'household'] : canManageShared ? ['household'] : ['private']);
	const visibilityHint = $derived(canManageShared && !canCreate ? 'Shared recipes are managed by household admins.' : 'New recipes are private to you.');

	$effect(() => {
		if (!shell.loading) void loadRecipes();
	});

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

	async function importRecipe(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		importBusy = true;
		importError = '';
		const body = new FormData();
		body.set('visibility', importVisibility);
		if (importType === 'url') body.set('source_url', importUrl.trim());
		else if (importFile) body.set('file', importFile);
		try {
			const preview = await api<{ recipe: { title: string; category: string; servings: number; preparation_minutes: number | null; ingredients: string[]; instructions: string[] }; warnings: string[] }>('/api/recipes/import-preview', 'POST', body);
			const parsedIngredients = preview.recipe.ingredients.slice(0, 100).map(parseImportedIngredient);
			editorDraft = {
				title: preview.recipe.title,
				category: preview.recipe.category,
				servings: String(preview.recipe.servings || 2),
				preparationMinutes: preview.recipe.preparation_minutes === null ? '' : String(preview.recipe.preparation_minutes),
				instructions: preview.recipe.instructions.join('\n'),
				visibility: importVisibility,
				ingredients: parsedIngredients.length > 0 ? parsedIngredients : [emptyRecipeIngredient()]
			};
			importNotices = [
				...preview.warnings,
				...(parsedIngredients.some((item) => !item.quantity || !item.unit) ? ['Check imported ingredient quantities and units before saving.'] : [])
			];
			importUrl = '';
			importFile = null;
			importOpen = false;
			await tick();
			editorOpen = true;
		} catch (cause) {
			importError = cause instanceof Error ? cause.message : 'The recipe could not be imported.';
		} finally {
			importBusy = false;
		}
	}

	function chooseImportFile(event: Event): void {
		importFile = (event.currentTarget as HTMLInputElement).files?.[0] ?? null;
	}

	function openImport(): void {
		importError = '';
		if (!formVisibilityOptions.includes(importVisibility)) importVisibility = formVisibilityOptions[0];
		importOpen = true;
	}

	function createRecipe(): void {
		message = '';
		editorDraft = null;
		importNotices = [];
		editorOpen = true;
	}

	async function recipeSaved(saved: Recipe | null, created: boolean): Promise<void> {
		message = created ? 'Recipe created.' : 'Recipe updated.';
		await loadRecipes();
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

</script>

<svelte:head>
	<title>{$t("Recipes")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Keep household recipes, measured ingredients and preparation notes together.")} />
</svelte:head>

{#snippet star(filled: boolean)}
	<svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill={filled ? 'currentColor' : 'none'}><path d="m12 3.5 2.6 5.3 5.9.9-4.25 4.1 1 5.8L12 16.9l-5.25 2.7 1-5.8L3.5 9.7l5.9-.9L12 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" /></svg>
{/snippet}

{#snippet favoriteButton(recipe: Recipe)}
	<button type="button" aria-label={$t(recipe.is_favorite ? 'Remove {title} from favorites' : 'Add {title} to favorites', { title: recipe.title })} aria-pressed={recipe.is_favorite} onclick={() => void toggleFavorite(recipe)} class="{iconButton} aria-pressed:text-warning">{@render star(recipe.is_favorite)}</button>
{/snippet}

<div class="mx-auto max-w-7xl space-y-8">
	<PageHeading eyebrow="Food · Recipes" title="Recipes worth keeping" description="A private collection for your household: clear quantities, useful notes and the way you like to make it.">
		{#snippet actions()}
			<Badge>{recipes.length} {$t(recipes.length === 1 ? 'recipe' : 'recipes')}</Badge>
			{#if canAddRecipe}<Button variant="secondary" onclick={openImport}>{$t("Import recipe")}</Button><Button onclick={createRecipe}>{$t("Add a recipe")}</Button>{/if}
		{/snippet}
	</PageHeading>

	{#if message}<Notice tone="success" message={message} />{/if}
	{#if error}<Notice tone="error" message={error} />{/if}

	<section aria-labelledby="recipe-list-title" class="space-y-6">
		<div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_auto] sm:items-end">
			<label class="{fieldLabel} block">{$t("Search recipes and ingredients")}
				<input bind:value={search} type="search" placeholder={$t("Try tomato, soup or breakfast")} class="{control} mt-1.5" />
			</label>
			<label class="{fieldLabel} block">{$t("Category")}
				<select bind:value={categoryFilter} class="{control} mt-1.5">
					<option value="all">{$t("All categories")}</option>
					{#each categories as recipeCategory}<option value={recipeCategory}>{recipeCategory}</option>{/each}
				</select>
			</label>
			<button type="button" aria-pressed={favoriteOnly} onclick={() => (favoriteOnly = !favoriteOnly)} class="{buttonClasses({ variant: 'secondary' })} aria-pressed:border-warning aria-pressed:text-warning">
				{@render star(favoriteOnly)}{$t("Favorites")}
			</button>
		</div>

		<h2 id="recipe-list-title" class="sr-only">{$t("Your recipes")}</h2>
		{#if loading}
			<p class="{panel} p-5 max-sm:p-4 text-sm text-ink-muted">{$t("Loading recipes…")}</p>
		{:else if !canView}
			<Notice tone="info" message="Recipes is enabled, but your account does not have Recipes View access. Ask a household administrator to grant the required module permission." />
		{:else if visibleRecipes.length === 0}
			<EmptyState
				title={recipes.length === 0 ? 'Your recipe book is ready.' : 'No recipes match these filters.'}
				description={recipes.length === 0 ? 'Start with a dish you make often. Recipes stay independent from meal planning and shopping.' : 'Clear the search or favorite filter to see the rest of your collection.'}
			>
				{#snippet actions()}
					{#if recipes.length === 0 && canAddRecipe}<Button onclick={createRecipe}>{$t("Create the first recipe")}</Button>{/if}
				{/snippet}
			</EmptyState>
		{:else}
			<ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
				{#each visibleRecipes as recipe (recipe.id)}
					<li class="{panel} flex min-w-0 flex-col overflow-hidden">
						{#if recipe.photo_url}{#if recipe.photo_is_pdf}<a href={recipe.photo_url} target="_blank" rel="noreferrer" class="flex aspect-[16/9] items-center gap-3 border-b border-line bg-surface-subtle px-5 text-sm font-medium text-ink-secondary hover:text-accent-ink">{$t('Open recipe PDF')}<span class="rounded border border-line-strong px-2 py-1 text-xs text-ink-muted">PDF</span></a>{:else}<img src={recipe.photo_url} alt={$t('Photo of {title}', { title: recipe.title })} loading="lazy" class="aspect-[16/9] w-full border-b border-line object-cover" />{/if}{/if}
						<div class="flex flex-1 flex-col gap-3 p-5">
							<div class="flex items-start justify-between gap-3">
								<div class="min-w-0">
									<p class="text-xs text-ink-muted">{recipe.category ?? $t('Uncategorized')} · {$t(recipe.visibility === 'household' ? 'Household' : 'Private')}</p>
									<h3 class="mt-1 text-lg font-semibold leading-snug text-ink">
										<a href="/modules/recipes/{recipe.id}" class="rounded transition-colors hover:text-accent-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink">{recipe.title}</a>
									</h3>
								</div>
								{@render favoriteButton(recipe)}
							</div>
							<p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs tabular-nums text-ink-muted">
								{#if recipe.servings}<span>{$t(recipe.servings === 1 ? '{count} serving' : '{count} servings', { count: recipe.servings })}</span>{:else}<span class="text-warning">{$t('Servings missing')}</span>{/if}
								{#if recipe.preparation_minutes}<span>{$t('{count} min', { count: recipe.preparation_minutes })}</span>{/if}
								<span>{$t(recipe.ingredients.length === 1 ? '{count} ingredient' : '{count} ingredients', { count: recipe.ingredients.length })}</span>
								{#if recipe.needs_review}<Badge tone="warning">{$t('Needs review')}</Badge>{/if}
							</p>
							<p class="line-clamp-2 text-sm leading-6 text-ink-secondary">
								{recipe.ingredients.length > 0 ? recipe.ingredients.map((item) => item.name).join(', ') : $t('No ingredients recorded.')}
							</p>
							<div class="mt-auto flex flex-wrap items-center gap-2 border-t border-line pt-3">
								<Button href="/modules/recipes/{recipe.id}" variant="secondary" size="small">{$t('Show recipe')}</Button>
							</div>
						</div>
					</li>
				{/each}
			</ul>
		{/if}
	</section>

		{#if canAddRecipe}
			<Modal bind:open={importOpen} title={$t('Import a recipe')} description={$t('Paste a recipe page link or upload a text PDF. You can review and edit everything before saving.')}>
				<div class="mb-4 grid grid-cols-2 gap-2" role="group" aria-label={$t('Import a recipe')}>
					<button type="button" aria-pressed={importType === 'url'} onclick={() => (importType = 'url')} class="{buttonClasses({ variant: 'secondary' })} aria-pressed:border-accent-ink aria-pressed:text-accent-ink">{$t('Web link')}</button>
					<button type="button" aria-pressed={importType === 'pdf'} onclick={() => (importType = 'pdf')} class="{buttonClasses({ variant: 'secondary' })} aria-pressed:border-accent-ink aria-pressed:text-accent-ink">{$t('PDF file')}</button>
				</div>
				<form onsubmit={importRecipe} class="space-y-4">
					{#if importType === 'url'}
						<label class="{field}"><span class="{fieldLabel}">{$t('Recipe page URL')}</span><input bind:value={importUrl} type="url" required maxlength="2048" placeholder="https://example.com/recipe" class="{control}" /></label>
					{:else}
						<label class="{field}"><span class="{fieldLabel}">{$t('Recipe PDF')}</span><input type="file" accept="application/pdf,.pdf" required onchange={chooseImportFile} class="{fileInput}" /><span class="text-xs text-ink-muted">{$t('PDF up to 10 MB; scanned PDFs are not supported yet.')}</span></label>
					{/if}
					<label class="{field}"><span class="{fieldLabel}">{$t('Save as')}</span><select bind:value={importVisibility} class="{control}">{#each formVisibilityOptions as option}<option value={option}>{$t(option === 'private' ? 'Private' : 'Household')}</option>{/each}</select></label>
					{#if importError}<Notice tone="error" message={importError} />{/if}
					<Button type="submit" fullWidth disabled={importBusy || (importType === 'pdf' && !importFile)}>{$t(importBusy ? 'Reading recipe…' : 'Parse recipe')}</Button>
				</form>
			</Modal>
		{/if}
		{#if canAddRecipe}
			<RecipeEditor bind:open={editorOpen} draft={editorDraft} notices={importNotices} visibilityOptions={formVisibilityOptions} {visibilityHint} onSaved={recipeSaved} />
		{/if}
		{#if canView && !canAddRecipe}
			<Notice tone="info" message="Your account can view recipes. Ask an administrator for Recipes Create to add private recipes or Recipes Manage to create household recipes." />
		{/if}
</div>
