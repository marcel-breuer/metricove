import { describe, expect, it } from 'bun:test';
import { editableRecipeIngredients, type RecipeIngredient } from './recipes';

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
