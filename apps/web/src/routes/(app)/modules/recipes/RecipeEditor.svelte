<script lang="ts">
	import { untrack } from 'svelte';
	import Button from '#lib/ui/Button.svelte';
	import Modal from '#lib/ui/Modal.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import { numberLocale, t } from '#lib/i18n';
	import { canonicalizeDecimalInput } from '#lib/number-format';
	import { emptyRecipeDraft, emptyRecipeIngredient, recipeDraftFrom, type Recipe, type RecipeDraft, type RecipeResponse, type RecipeVisibility } from '#lib/recipes';
	import { recipeRequest } from '#lib/api-client';

	let {
		open = $bindable(false),
		recipe = null,
		draft = null,
		notices = [],
		visibilityOptions,
		visibilityHint = '',
		onSaved
	}: {
		open?: boolean;
		/** Recipe being edited; `null` creates a new recipe. */
		recipe?: Recipe | null;
		/** Prefilled values for a new recipe, for example from an import preview. */
		draft?: RecipeDraft | null;
		notices?: string[];
		visibilityOptions: RecipeVisibility[];
		/** Shown instead of the visibility select when only one option applies. */
		visibilityHint?: string;
		onSaved: (recipe: Recipe | null, created: boolean) => void | Promise<void>;
	} = $props();

	const formId = $props.id();
	let form = $state<RecipeDraft>(emptyRecipeDraft('private'));
	let photoFile = $state<File | null>(null);
	let removePhoto = $state(false);
	let saving = $state(false);
	let error = $state('');
	const editing = $derived(recipe !== null);

	// Reset the form each time the dialog opens so stale edits never leak into the next recipe.
	$effect(() => {
		if (!open) return;
		untrack(() => {
			form = recipe ? recipeDraftFrom(recipe) : draft ? $state.snapshot(draft) : emptyRecipeDraft(visibilityOptions[0] ?? 'private');
			photoFile = null;
			removePhoto = false;
			error = '';
		});
	});

	function addIngredient(): void {
		form.ingredients = [...form.ingredients, emptyRecipeIngredient()];
	}

	function removeIngredient(index: number): void {
		form.ingredients = form.ingredients.filter((_, current) => current !== index);
	}

	function choosePhoto(event: Event): void {
		photoFile = (event.currentTarget as HTMLInputElement).files?.[0] ?? null;
		if (photoFile) removePhoto = false;
	}

	async function save(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		error = '';
		const body = new FormData();
		body.set('title', form.title);
		body.set('category', form.category);
		body.set('servings', form.servings || '');
		body.set('preparation_minutes', form.preparationMinutes);
		body.set('instructions', form.instructions);
		body.set('ingredients', JSON.stringify(form.ingredients.map((item) => ({
			name: item.name,
			quantity: item.quantity.trim() ? canonicalizeDecimalInput(item.quantity, $numberLocale) : null,
			unit: item.unit.trim() || null,
			note: item.note
		}))));
		if (!recipe) body.set('visibility', form.visibility);
		if (photoFile) body.set('photo', photoFile);
		if (removePhoto) body.set('remove_photo', '1');
		if (recipe) body.set('_method', 'PATCH');
		saving = true;
		try {
			const result = await recipeRequest<RecipeResponse>(recipe ? `/api/recipes/${recipe.id}` : '/api/recipes', 'POST', body);
			open = false;
			await onSaved(result.recipe ?? null, !recipe);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The recipe could not be saved.';
		} finally {
			saving = false;
		}
	}
</script>

<Modal bind:open size="fullscreen" title={$t(editing ? 'Edit recipe' : 'Add a recipe')} description={editing ? recipe?.title : undefined}>
	{#if error}<Notice tone="error" message={error} class="mb-5" />{/if}
	{#if recipe?.needs_review}<Notice tone="warning" message={$t('This recipe has source details to review. Blank fields are kept as provided; fill in what you know.')} class="mb-5" />{/if}
	{#if notices.length}
		<ul role="status" class="ui-notice ui-notice--warning mb-5 list-disc space-y-1 pl-8">{#each notices as notice}<li>{$t(notice)}</li>{/each}</ul>
	{/if}

	<form id={formId} onsubmit={save} class="grid gap-x-8 gap-y-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
		<div class="space-y-5">
			<label class="ui-field">
				<span class="ui-field-label">{$t('Title')}</span>
				<input bind:value={form.title} required maxlength="180" class="ui-control" />
			</label>
			<div class="grid gap-4 sm:grid-cols-2">
				<label class="ui-field">
					<span class="ui-field-label">{$t('Category')} <span class="text-xs font-normal text-[var(--text-muted)]">{$t('optional')}</span></span>
					<input bind:value={form.category} maxlength="80" placeholder={$t('Dinner')} class="ui-control" />
				</label>
				<label class="ui-field">
					<span class="ui-field-label">{$t('Servings')}</span>
					<input bind:value={form.servings} type="number" min="1" max="1000" required={!editing} class="ui-control" />
				</label>
				<label class="ui-field">
					<span class="ui-field-label">{$t('Prep time')} <span class="text-xs font-normal text-[var(--text-muted)]">{$t('minutes')}</span></span>
					<input bind:value={form.preparationMinutes} type="number" min="1" max="1440" placeholder={$t('Optional')} class="ui-control" />
				</label>
				{#if !editing && visibilityOptions.length > 1}
					<label class="ui-field">
						<span class="ui-field-label">{$t('Visibility')}</span>
						<select bind:value={form.visibility} class="ui-control">
							{#each visibilityOptions as option}<option value={option}>{$t(option === 'private' ? 'Private' : 'Household')}</option>{/each}
						</select>
					</label>
				{:else}
					<p class="self-end pb-3 text-xs leading-5 text-[var(--text-muted)]">{editing ? $t('Visibility: {visibility}', { visibility: $t(form.visibility === 'private' ? 'Private' : 'Household') }) : $t(visibilityHint)}</p>
				{/if}
			</div>
			<label class="ui-field">
				<span class="ui-field-label">{$t('Preparation instructions')}</span>
				<textarea bind:value={form.instructions} maxlength="20000" rows="10" placeholder={$t('Write the steps in the order you follow them…')} class="ui-control resize-y leading-6"></textarea>
			</label>
			<div class="ui-field">
				<label class="ui-field">
					<span class="ui-field-label">{$t('Recipe photo')} <span class="text-xs font-normal text-[var(--text-muted)]">{$t('optional · JPEG, PNG or WebP · 5 MB max')}</span></span>
					<input type="file" accept="image/jpeg,image/png,image/webp" onchange={choosePhoto} class="ui-file-input" />
				</label>
				{#if photoFile}
					<p class="text-xs text-[var(--text-muted)]">{$t('Selected:')} {photoFile.name}</p>
				{:else if recipe?.photo_url && !removePhoto}
					<div class="flex items-center gap-3">
						<img src={recipe.photo_url} alt={$t('Current recipe')} class="h-14 w-20 rounded-lg object-cover" />
						<Button variant="ghost" size="small" type="button" onclick={() => (removePhoto = true)}>{$t('Remove current photo')}</Button>
					</div>
				{:else if removePhoto}
					<p class="text-xs text-[var(--text-muted)]">{$t('The current photo will be removed when you save.')}</p>
				{/if}
			</div>
		</div>

		<fieldset class="min-w-0 space-y-3">
			<legend class="ui-field-label mb-3">{$t('Ingredients')}</legend>
			<ol class="space-y-3 sm:space-y-2">
				{#each form.ingredients as ingredient, index (index)}
					<li class="grid grid-cols-[minmax(0,1fr)_auto] gap-2 rounded-xl border border-[var(--border-default)] p-3 sm:grid-cols-[minmax(0,1.6fr)_5.5rem_5.5rem_minmax(0,1.2fr)_auto] sm:items-end sm:border-0 sm:p-0">
						<label class="ui-field gap-1 text-xs text-[var(--text-muted)]">
							<span class={index > 0 ? 'sm:sr-only' : ''}>{$t('Ingredient')}</span>
							<input bind:value={ingredient.name} required maxlength="120" placeholder={$t('Pasta')} class="ui-control" />
						</label>
						<button type="button" aria-label={$t('Remove ingredient')} onclick={() => removeIngredient(index)} class="ui-icon-button order-2 self-end sm:order-last">
							<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" /></svg>
						</button>
						<div class="order-3 col-span-2 grid grid-cols-2 gap-2 sm:contents">
							<label class="ui-field gap-1 text-xs text-[var(--text-muted)]">
								<span class={index > 0 ? 'sm:sr-only' : ''}>{$t('Quantity')}</span>
								<input bind:value={ingredient.quantity} type="text" inputmode="decimal" placeholder={$t('Optional')} class="ui-control" />
							</label>
							<label class="ui-field gap-1 text-xs text-[var(--text-muted)]">
								<span class={index > 0 ? 'sm:sr-only' : ''}>{$t('Unit')}</span>
								<input bind:value={ingredient.unit} maxlength="40" placeholder={$t('Optional')} class="ui-control" />
							</label>
						</div>
						<label class="ui-field order-4 col-span-2 gap-1 text-xs text-[var(--text-muted)] sm:col-span-1">
							<span class={index > 0 ? 'sm:sr-only' : ''}>{$t('Note')} <span class="text-[var(--text-subtle)]">{$t('optional')}</span></span>
							<input bind:value={ingredient.note} maxlength="160" placeholder={$t('finely grated')} class="ui-control" />
						</label>
					</li>
				{/each}
			</ol>
			<Button variant="secondary" size="small" type="button" onclick={addIngredient} disabled={form.ingredients.length >= 100}>{$t('Add ingredient')}</Button>
		</fieldset>

		<p class="text-xs leading-5 text-[var(--text-muted)] lg:col-span-2">{$t('Private recipes and photos remain hidden from administrators unless you grant access. Household recipes require an active admin with Recipes Manage.')}</p>
	</form>

	{#snippet footer()}
		<Button variant="secondary" type="button" disabled={saving} onclick={() => (open = false)}>{$t('Cancel')}</Button>
		<Button type="submit" form={formId} disabled={saving}>{$t(saving ? 'Saving…' : editing ? 'Save changes' : 'Save recipe')}</Button>
	{/snippet}
</Modal>
