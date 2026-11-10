import { describe, expect, it } from 'bun:test';
import { editableRecipeIngredients, emptyRecipeDraft, parseImportedIngredient, recipeDraftFrom, type Recipe, type RecipeIngredient } from './recipes';

describe('editableRecipeIngredients', () => {
	it('preserves visible ingredients and leaves missing source measurements blank', () => {
		const ingredients: RecipeIngredient[] = [
			{ id: 1, position: 0, name: 'Pasta', quantity: '250.0000', unit: 'g', note: null },
			{ id: 2, position: 1, name: 'Salt', quantity: null, unit: null, note: 'to taste' }
		];

		expect(editableRecipeIngredients(ingredients)).toEqual([
			{ name: 'Pasta', quantity: '250', unit: 'g', note: '' },
			{ name: 'Salt', quantity: '', unit: '', note: 'to taste' }
		]);
	});
});

describe('recipe drafts', () => {
	const recipe: Recipe = {
		id: 7, title: 'Soup', category: null, instructions: 'Stir.', servings: null, preparation_minutes: 20, visibility: 'household',
		is_favorite: false, can_edit: true, can_change_visibility: true, needs_review: true, photo_url: null, photo_is_pdf: false, updated_at: null,
		ingredients: [{ id: 1, position: 0, name: 'Water', quantity: '1.5000', unit: 'l', note: null }]
	};

	it('starts a new recipe with one blank ingredient row and the chosen visibility', () => {
		expect(emptyRecipeDraft('household')).toEqual({
			title: '', category: '', servings: '2', preparationMinutes: '', instructions: '', visibility: 'household',
			ingredients: [{ name: '', quantity: '', unit: '', note: '' }]
		});
	});

	it('keeps missing servings blank when editing a recipe that needs review', () => {
		expect(recipeDraftFrom(recipe)).toEqual({
			title: 'Soup', category: '', servings: '', preparationMinutes: '20', instructions: 'Stir.', visibility: 'household',
			ingredients: [{ name: 'Water', quantity: '1.5', unit: 'l', note: '' }]
		});
	});
});

describe('parseImportedIngredient', () => {
	it('splits quantities, fractions and known units', () => {
		expect(parseImportedIngredient('1 1/2 cups flour')).toEqual({ name: 'flour', quantity: '1.5', unit: 'cups', note: '' });
		expect(parseImportedIngredient('½ tsp salt')).toEqual({ name: 'salt', quantity: '0.5', unit: 'tsp', note: '' });
		expect(parseImportedIngredient('200,5 g butter')).toEqual({ name: 'butter', quantity: '200.5', unit: 'g', note: '' });
	});

	it('keeps unparseable lines as the ingredient name', () => {
		expect(parseImportedIngredient('  Salt to taste ')).toEqual({ name: 'Salt to taste', quantity: '', unit: '', note: '' });
		expect(parseImportedIngredient('2 eggs')).toEqual({ name: 'eggs', quantity: '2', unit: '', note: '' });
	});
});
