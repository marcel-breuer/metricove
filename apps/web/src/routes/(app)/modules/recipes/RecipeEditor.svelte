<script lang="ts">
	import { Input } from '#lib/components/ui/input/index.js';
	import { Label } from '#lib/components/ui/label/index.js';
	import * as NativeSelect from '#lib/components/ui/native-select/index.js';
	import MarkdownEditor from '#lib/ui/MarkdownEditor.svelte';
	import { control, field, fieldLabel, iconButton } from '#lib/ui/styles';
	import { untrack } from 'svelte';
	import Button from '#lib/ui/Button.svelte';
	import Modal from '#lib/ui/Modal.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import { numberLocale, t } from '#lib/i18n';
	import { canonicalizeDecimalInput } from '#lib/number-format';
	import { emptyRecipeDraft, emptyRecipeIngredient, moveRecipeIngredient, recipeDraftFrom, type Recipe, type RecipeDraft, type RecipeResponse, type RecipeVisibility } from '#lib/recipes';
	import { recipeRequest } from '#lib/api-client';

	let {
		open = $bindable(false),
		recipe = null,
		draft = null,
		notices = [],
		visibilityOptions,
		unitOptions = ['piece', 'g', 'kg', 'ml', 'l', 'bunch', 'tsp', 'tbsp'],
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
		unitOptions?: string[];
		/** Shown instead of the visibility select when only one option applies. */
		visibilityHint?: string;
		onSaved: (recipe: Recipe | null, created: boolean) => void | Promise<void>;
	} = $props();

	const formId = $props.id();
	const allVisibilityOptions: RecipeVisibility[] = ['private', 'household'];
	let form = $state<RecipeDraft>(emptyRecipeDraft('private'));
	let photoFile = $state<File | null>(null);
	let removePhoto = $state(false);
	let saving = $state(false);
	let error = $state('');
	let draggedIngredientIndex = $state<number | null>(null);
	let dragOverIngredientIndex = $state<number | null>(null);
	let ingredientOrderAnnouncement = $state('');
	const editing = $derived(recipe !== null);

	// Reset the form each time the dialog opens so stale edits never leak into the next recipe.
	$effect(() => {
		if (!open) return;
		untrack(() => {
			form = recipe ? recipeDraftFrom(recipe) : draft ? $state.snapshot(draft) : emptyRecipeDraft(visibilityOptions[0] ?? 'private');
			photoFile = null;
			removePhoto = false;
			error = '';
			draggedIngredientIndex = null;
			dragOverIngredientIndex = null;
			ingredientOrderAnnouncement = '';
		});
	});

	function addIngredient(): void {
		form.ingredients = [...form.ingredients, emptyRecipeIngredient()];
	}

	function removeIngredient(index: number): void {
		form.ingredients = form.ingredients.filter((_, current) => current !== index);
		ingredientOrderAnnouncement = '';
	}

	function moveIngredient(fromIndex: number, toIndex: number): void {
		const reordered = moveRecipeIngredient(form.ingredients, fromIndex, toIndex);
		if (reordered === form.ingredients) return;

		form.ingredients = reordered;
		dragOverIngredientIndex = null;
		ingredientOrderAnnouncement = $t('Moved ingredient to position {position}', { position: toIndex + 1 });
	}

	function startIngredientDrag(event: DragEvent, index: number): void {
		if (!event.dataTransfer) return;
		draggedIngredientIndex = index;
		event.dataTransfer.effectAllowed = 'move';
		event.dataTransfer.setData('text/plain', String(index));
	}

	function finishIngredientDrag(): void {
		draggedIngredientIndex = null;
		dragOverIngredientIndex = null;
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
		if (!recipe || recipe.can_change_visibility) body.set('visibility', form.visibility);
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
		<ul role="status" class="rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm leading-normal text-warning mb-5 list-disc space-y-1 pl-8">{#each notices as notice}<li>{$t(notice)}</li>{/each}</ul>
	{/if}

	<form id={formId} onsubmit={save} class="grid gap-x-8 gap-y-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
		<div class="space-y-5">
			<Label variant="field">
				<span class="{fieldLabel}">{$t('Title')}</span>
				<Input variant="glass" bind:value={form.title} required maxlength={180} />
			</Label>
			<div class="grid gap-4 sm:grid-cols-2">
				<Label variant="field">
					<span class="{fieldLabel}">{$t('Category')} <span class="text-xs font-normal text-ink-muted">{$t('optional')}</span></span>
					<Input variant="glass" bind:value={form.category} maxlength={80} placeholder={$t('Dinner')} />
				</Label>
				<Label variant="field">
					<span class="{fieldLabel}">{$t('People')}</span>
					<Input variant="glass" bind:value={() => form.servings, (value) => (form.servings = value == null ? '' : String(value))} type="number" min="1" max="1000" required={!editing} />
				</Label>
				<Label variant="field">
					<span class="{fieldLabel}">{$t('Prep time')} <span class="text-xs font-normal text-ink-muted">{$t('minutes')}</span></span>
					<Input variant="glass" bind:value={() => form.preparationMinutes, (value) => (form.preparationMinutes = value == null ? '' : String(value))} type="number" min="1" max="1440" placeholder={$t('Optional')} />
				</Label>
				{#if (!editing && visibilityOptions.length > 1) || (editing && recipe?.can_change_visibility)}
					<div class="{field} font-[550]">
						<Label variant="field" for="{formId}-visibility" class={fieldLabel}>{$t('Visibility')}</Label>
						<NativeSelect.Root variant="glass" id="{formId}-visibility" bind:value={form.visibility}>
							{#each (editing ? allVisibilityOptions : visibilityOptions) as option}<NativeSelect.Option value={option}>{$t(option === 'private' ? 'Private' : 'Household')}</NativeSelect.Option>{/each}
						</NativeSelect.Root>
					</div>
				{:else}
					<p class="self-end pb-3 text-xs leading-5 text-ink-muted">{editing ? $t('Visibility: {visibility}', { visibility: $t(form.visibility === 'private' ? 'Private' : 'Household') }) : $t(visibilityHint)}</p>
				{/if}
			</div>
			<div class={field}>
				<Label variant="field" for="{formId}-instructions" class={fieldLabel}>{$t('Preparation instructions')}</Label>
				<MarkdownEditor id="{formId}-instructions" bind:value={form.instructions} maxlength={20000} rows={10} placeholder={$t('Write the steps in the order you follow them…')} class="{control} resize-y leading-6" />
			</div>
			<div class="{field}">
				<Label variant="field">
					<span class="{fieldLabel}">{$t('Recipe photo or PDF')} <span class="text-xs font-normal text-ink-muted">{$t('optional · JPEG, PNG or WebP up to 5 MB; PDF up to 10 MB')}</span></span>
					<Input variant="file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf,.pdf" onchange={choosePhoto} />
				</Label>
				{#if photoFile}
					<p class="text-xs text-ink-muted">{$t('Selected:')} {photoFile.name}</p>
				{:else if recipe?.photo_url && !removePhoto}
					<div class="flex items-center gap-3">
						{#if recipe.photo_is_pdf}<a href={recipe.photo_url} target="_blank" rel="noreferrer" class="rounded-lg border border-line px-3 py-2 text-sm text-ink-secondary hover:border-accent-ink">{$t('Open recipe PDF')}</a>{:else}<img src={recipe.photo_url} alt={$t('Current recipe')} class="h-14 w-20 rounded-lg object-cover" />{/if}
						<Button variant="ghost" size="small" type="button" onclick={() => (removePhoto = true)}>{$t('Remove current file')}</Button>
					</div>
				{:else if removePhoto}
					<p class="text-xs text-ink-muted">{$t('The current photo will be removed when you save.')}</p>
				{/if}
			</div>
		</div>

		<fieldset class="min-w-0 space-y-3">
			<legend class="{fieldLabel} mb-3">{$t('Ingredients')}</legend>
			<ol aria-label={$t('Reorder ingredients')} class="space-y-3 sm:space-y-2">
				{#each form.ingredients as ingredient, index (ingredient)}
					<li
						ondragover={(event) => {
							if (draggedIngredientIndex !== null) {
								event.preventDefault();
								if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
								dragOverIngredientIndex = index;
							}
						}}
						ondragleave={() => {
							if (dragOverIngredientIndex === index) dragOverIngredientIndex = null;
						}}
						ondrop={(event) => {
							event.preventDefault();
							if (draggedIngredientIndex !== null) moveIngredient(draggedIngredientIndex, index);
							finishIngredientDrag();
						}}
						class="grid gap-2 rounded-xl border border-line p-3 transition-colors sm:grid-cols-[minmax(0,1.6fr)_5.5rem_5.5rem_minmax(0,1.2fr)_auto] sm:items-end sm:border-0 sm:p-0 {dragOverIngredientIndex === index ? 'bg-accent/5 outline outline-2 outline-accent-ink/45' : ''}"
					>
						<div class="order-1 flex justify-end gap-1 sm:order-last sm:items-center">
							<Button
								type="button"
								draggable="true"
								aria-label={$t('Drag ingredient {number} to reorder', { number: index + 1 })}
								ondragstart={(event) => startIngredientDrag(event, index)}
								ondragend={finishIngredientDrag}
								variant="ghost"
								class="{iconButton} cursor-grab p-0 active:cursor-grabbing"
							>
								<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4"><path d="M8 5h.01M16 5h.01M8 12h.01M16 12h.01M8 19h.01M16 19h.01" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
							</Button>
							<Button type="button" aria-label={$t('Move ingredient {number} up', { number: index + 1 })} onclick={() => moveIngredient(index, index - 1)} disabled={index === 0} variant="ghost" class="{iconButton} p-0 disabled:opacity-45">
								<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4"><path d="m6 14 6-6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
							</Button>
							<Button type="button" aria-label={$t('Move ingredient {number} down', { number: index + 1 })} onclick={() => moveIngredient(index, index + 1)} disabled={index === form.ingredients.length - 1} variant="ghost" class="{iconButton} p-0 disabled:opacity-45">
								<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4"><path d="m6 10 6 6 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
							</Button>
							<Button type="button" aria-label={$t('Remove ingredient')} onclick={() => removeIngredient(index)} variant="ghost" class="{iconButton} p-0">
								<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" /></svg>
							</Button>
						</div>
						<Label variant="field" class="text-xs text-ink-muted">
							<span class={index > 0 ? 'sm:sr-only' : ''}>{$t('Ingredient')}</span>
							<Input variant="glass" bind:value={ingredient.name} required maxlength={120} placeholder={$t('Pasta')} />
						</Label>
						<div class="order-3 grid grid-cols-2 gap-2 sm:contents">
							<Label variant="field" class="text-xs text-ink-muted">
								<span class={index > 0 ? 'sm:sr-only' : ''}>{$t('Quantity')}</span>
								<Input variant="glass" bind:value={ingredient.quantity} type="text" inputmode="decimal" placeholder={$t('Optional')} />
							</Label>
							<div class="grid gap-2 font-[550]">
								<Label variant="field" for="{formId}-ingredient-unit-{index}" class={index > 0 ? 'sm:sr-only' : ''}>{$t('Unit')}</Label>
								<NativeSelect.Root variant="glass" id="{formId}-ingredient-unit-{index}" bind:value={ingredient.unit}>
									<NativeSelect.Option value="">{$t('Optional')}</NativeSelect.Option>
									{#each [...new Set([...unitOptions, ...(ingredient.unit && !unitOptions.includes(ingredient.unit) ? [ingredient.unit] : [])])] as unit (unit)}<NativeSelect.Option value={unit}>{$t(unit)}</NativeSelect.Option>{/each}
								</NativeSelect.Root>
							</div>
						</div>
						<Label variant="field" class="order-4 col-span-2 text-xs text-ink-muted sm:col-span-1">
							<span class={index > 0 ? 'sm:sr-only' : ''}>{$t('Note')} <span class="text-ink-subtle">{$t('optional')}</span></span>
							<Input variant="glass" bind:value={ingredient.note} maxlength={160} placeholder={$t('finely grated')} />
						</Label>
					</li>
				{/each}
			</ol>
			<p role="status" aria-live="polite" class="sr-only">{ingredientOrderAnnouncement}</p>
			<Button variant="secondary" size="small" type="button" onclick={addIngredient} disabled={form.ingredients.length >= 100}>{$t('Add ingredient')}</Button>
		</fieldset>

		<p class="text-xs leading-5 text-ink-muted lg:col-span-2">{$t('Private recipes and photos remain hidden from administrators unless you grant access. Household recipes require an active admin with Recipes Manage.')}</p>
	</form>

	{#snippet footer()}
		<Button variant="secondary" type="button" disabled={saving} onclick={() => (open = false)}>{$t('Cancel')}</Button>
		<Button type="submit" form={formId} disabled={saving}>{$t(saving ? 'Saving…' : editing ? 'Save changes' : 'Save recipe')}</Button>
	{/snippet}
</Modal>
