<script lang="ts">
	import { iconButton, panel } from '#lib/ui/styles';
	import { getContext } from 'svelte';
	import { goto } from '$app/navigation';
	import { page } from '$app/state';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { t } from '#lib/i18n';
	import { formatMeasurement } from '#lib/number-format';
	import type { Recipe, RecipeResponse } from '#lib/recipes';
	import Badge from '#lib/ui/Badge.svelte';
	import BackLink from '#lib/ui/BackLink.svelte';
	import Button from '#lib/ui/Button.svelte';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import Panel from '#lib/ui/Panel.svelte';
	import RecipeEditor from '../RecipeEditor.svelte';
	import { recipeRequest } from '#lib/api-client';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const recipeId = $derived(Number(page.params.recipeId));
	const access = $derived(shell.modules.find((module) => module.key === 'recipes'));
	const canView = $derived(access?.capabilities.view ?? false);

	let recipe = $state<Recipe | null>(null);
	let loading = $state(true);
	let error = $state('');
	let message = $state('');
	let editorOpen = $state(false);
	let deleteOpen = $state(false);
	let deleting = $state(false);

	$effect(() => {
		if (!shell.loading) void loadRecipe(recipeId);
	});

	async function loadRecipe(id: number): Promise<void> {
		loading = true;
		error = '';
		if (!canView) {
			recipe = null;
			loading = false;
			return;
		}
		try {
			if (!Number.isInteger(id) || id <= 0) throw new Error('This recipe is unavailable to your account.');
			const result = await recipeRequest<RecipeResponse>(`/api/recipes/${id}`);
			recipe = result.recipe ?? null;
			if (!recipe) throw new Error('This recipe is unavailable to your account.');
		} catch (cause) {
			recipe = null;
			error = cause instanceof Error ? cause.message : 'The recipe could not be loaded.';
		} finally {
			loading = false;
		}
	}

	async function toggleFavorite(current: Recipe): Promise<void> {
		message = '';
		error = '';
		try {
			const result = await recipeRequest<RecipeResponse>(`/api/recipes/${current.id}/favorite`, current.is_favorite ? 'DELETE' : 'PUT');
			if (result.recipe) recipe = result.recipe;
			else await loadRecipe(current.id);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The favorite could not be updated.';
		}
	}

	async function deleteRecipe(current: Recipe): Promise<void> {
		deleting = true;
		error = '';
		try {
			await recipeRequest<RecipeResponse>(`/api/recipes/${current.id}`, 'DELETE');
			deleteOpen = false;
			await goto('/modules/recipes');
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The recipe could not be deleted.';
		} finally {
			deleting = false;
		}
	}

	async function recipeSaved(saved: Recipe | null): Promise<void> {
		message = 'Recipe updated.';
		if (saved) recipe = saved;
		else await loadRecipe(recipeId);
	}
</script>

<svelte:head>
	<title>{recipe ? recipe.title : $t('Recipes')} {$t('· Metricove')}</title>
	<meta name="description" content={$t('Keep household recipes, measured ingredients and preparation notes together.')} />
</svelte:head>

{#snippet star(filled: boolean)}
	<svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill={filled ? 'currentColor' : 'none'}><path d="m12 3.5 2.6 5.3 5.9.9-4.25 4.1 1 5.8L12 16.9l-5.25 2.7 1-5.8L3.5 9.7l5.9-.9L12 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" /></svg>
{/snippet}

<div class="mx-auto max-w-6xl space-y-6">
	<BackLink href="/modules/recipes" label="All recipes" />

	{#if message}<Notice tone="success" message={message} />{/if}
	{#if error && recipe}<Notice tone="error" message={error} />{/if}

	{#if shell.loading || loading}
		<p class="{panel} p-5 max-sm:p-4 text-sm text-ink-muted" role="status">{$t('Loading recipe…')}</p>
	{:else if !canView}
		<Notice tone="info" message="Recipes is enabled, but your account does not have Recipes View access. Ask a household administrator to grant the required module permission." />
	{:else if !recipe}
		<Panel>
			<h1 class="text-xl font-semibold">{$t('Recipe unavailable')}</h1>
			<p class="mt-2 text-sm leading-6 text-ink-muted">{$t(error || 'This recipe is unavailable to your account.')}</p>
			<Button href="/modules/recipes" variant="secondary" class="mt-5">{$t('All recipes')}</Button>
		</Panel>
	{:else}
		<header class="flex flex-wrap items-end justify-between gap-4 max-sm:items-start">
			<div class="min-w-0">
				<p class="text-[0.8125rem] font-semibold tracking-[0.01em] text-accent-ink">{recipe.category ?? $t('Uncategorized')} · {$t(recipe.visibility === 'household' ? 'Household' : 'Private')}</p>
				<h1 class="page-title break-words">{recipe.title}</h1>
				<p class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm tabular-nums text-ink-muted">
					{#if recipe.servings}<span>{$t(recipe.servings === 1 ? '{count} serving' : '{count} servings', { count: recipe.servings })}</span>{:else}<span class="text-warning">{$t('Servings missing')}</span>{/if}
					{#if recipe.preparation_minutes}<span>{$t('{count} min', { count: recipe.preparation_minutes })}</span>{/if}
					<span>{$t(recipe.ingredients.length === 1 ? '{count} ingredient' : '{count} ingredients', { count: recipe.ingredients.length })}</span>
					{#if recipe.needs_review}<Badge tone="warning">{$t('Needs review')}</Badge>{/if}
				</p>
			</div>
			<div class="flex items-center gap-3 max-sm:w-full max-sm:flex-wrap max-sm:*:flex-auto">
				<button type="button" aria-label={$t(recipe.is_favorite ? 'Remove {title} from favorites' : 'Add {title} to favorites', { title: recipe.title })} aria-pressed={recipe.is_favorite} onclick={() => void toggleFavorite(recipe!)} class="{iconButton} aria-pressed:text-warning max-sm:grow-0!">{@render star(recipe.is_favorite)}</button>
				{#if recipe.can_edit}
					<Button variant="ghost" onclick={() => (deleteOpen = true)}>{$t('Delete')}</Button>
					<Button onclick={() => { message = ''; editorOpen = true; }}>{$t('Edit')}</Button>
				{/if}
			</div>
		</header>

		{#if recipe.photo_url}
			{#if recipe.photo_is_pdf}<a href={recipe.photo_url} target="_blank" rel="noreferrer" class="inline-flex min-h-12 items-center gap-3 rounded-xl border border-line bg-surface-subtle px-4 text-sm font-medium text-ink-secondary hover:border-accent-ink">{$t('Open recipe PDF')}<span class="rounded border border-line-strong px-2 py-1 text-xs text-ink-muted">PDF</span></a>{:else}<img src={recipe.photo_url} alt={$t('Photo of {title}', { title: recipe.title })} class="aspect-[21/9] w-full rounded-2xl border border-line object-cover" />{/if}
		{/if}

		<div class="grid gap-6 md:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
			<Panel>
				<h2 class="text-base font-semibold">{$t('Ingredients')}</h2>
				<ul class="mt-3 divide-y divide-line text-sm">
					{#each recipe.ingredients as ingredient (ingredient.id)}
						<li class="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-3 py-2.5">
							{#if ingredient.quantity && ingredient.unit}<span class="tabular-nums text-accent-ink">{formatMeasurement(ingredient.quantity, ingredient.unit)} {ingredient.unit}</span>{:else}<span class="text-xs leading-5 text-warning">{$t('Amount missing')}</span>{/if}
							<span class="text-ink">{ingredient.name}{#if ingredient.note}<span class="text-ink-muted"> · {ingredient.note}</span>{/if}</span>
						</li>
					{/each}
					{#if recipe.ingredients.length === 0}<li class="py-2.5 text-ink-muted">{$t('No ingredients recorded.')}</li>{/if}
				</ul>
			</Panel>
			<Panel>
				<h2 class="text-base font-semibold">{$t('Preparation')}</h2>
				<p class="mt-3 whitespace-pre-line text-sm leading-7 text-ink-secondary">{recipe.instructions || $t('No preparation steps recorded.')}</p>
			</Panel>
		</div>

		{#if recipe.can_edit}
			<RecipeEditor bind:open={editorOpen} {recipe} visibilityOptions={[recipe.visibility]} onSaved={(saved) => recipeSaved(saved)} />
			<ConfirmModal bind:open={deleteOpen} title={$t('Delete recipe?')} message={$t('Delete this recipe?')} busy={deleting} onConfirm={() => void deleteRecipe(recipe!)} />
		{/if}
	{/if}
</div>
