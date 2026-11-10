export type RecipeVisibility = 'private' | 'household';

export type RecipeIngredient = {
	id: number;
	position: number;
	name: string;
	quantity: string | null;
	unit: string | null;
	note: string | null;
};

export type Recipe = {
	id: number;
	title: string;
	category: string | null;
	instructions: string;
	servings: number | null;
	preparation_minutes: number | null;
	visibility: RecipeVisibility;
	is_favorite: boolean;
	can_edit: boolean;
	can_change_visibility: boolean;
	needs_review: boolean;
	photo_url: string | null;
	photo_is_pdf: boolean;
	ingredients: RecipeIngredient[];
	updated_at: string | null;
};

export type RecipeIngredientInput = {
	name: string;
	quantity: string;
	unit: string;
	note: string;
};

export function editableRecipeIngredients(ingredients: RecipeIngredient[]): RecipeIngredientInput[] {
	return ingredients.map((ingredient) => ({
		name: ingredient.name,
		quantity: ingredient.quantity?.replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.0+$/, '') ?? '',
		unit: ingredient.unit ?? '',
		note: ingredient.note ?? ''
	}));
}

export type RecipeResponse = {
	message?: string;
	recipes?: Recipe[];
	recipe?: Recipe;
	errors?: Record<string, string[]>;
};

/** Editable form state for creating or updating a recipe. */
export type RecipeDraft = {
	title: string;
	category: string;
	servings: string;
	preparationMinutes: string;
	instructions: string;
	visibility: RecipeVisibility;
	ingredients: RecipeIngredientInput[];
};

export function emptyRecipeIngredient(): RecipeIngredientInput {
	return { name: '', quantity: '', unit: '', note: '' };
}

export function emptyRecipeDraft(visibility: RecipeVisibility): RecipeDraft {
	return { title: '', category: '', servings: '2', preparationMinutes: '', instructions: '', visibility, ingredients: [emptyRecipeIngredient()] };
}

export function recipeDraftFrom(recipe: Recipe): RecipeDraft {
	return {
		title: recipe.title,
		category: recipe.category ?? '',
		servings: recipe.servings === null ? '' : String(recipe.servings),
		preparationMinutes: recipe.preparation_minutes === null ? '' : String(recipe.preparation_minutes),
		instructions: recipe.instructions,
		visibility: recipe.visibility,
		ingredients: editableRecipeIngredients(recipe.ingredients)
	};
}

/** Splits an imported free-text ingredient line into quantity, unit and name where it is unambiguous. */
export function parseImportedIngredient(raw: string): RecipeIngredientInput {
	const fractionValues: Record<string, string> = { '¼': '0.25', '½': '0.5', '¾': '0.75', '⅓': '0.3333', '⅔': '0.6667' };
	const match = raw.trim().match(/^(?:(\d+\s+)?(\d+\s*\/\s*\d+|\d+(?:[.,]\d+)?|[¼½¾⅓⅔]))\s*(.*)$/u);
	if (!match) return { name: raw.trim(), quantity: '', unit: '', note: '' };
	let quantity = match[2].replace(/\s/g, '');
	if (fractionValues[quantity]) quantity = fractionValues[quantity];
	else if (quantity.includes('/')) {
		const [numerator, denominator] = quantity.split('/').map(Number);
		quantity = denominator > 0 ? String(Number((numerator / denominator).toFixed(4))) : '';
	} else quantity = quantity.replace(',', '.');
	if (match[1]) quantity = String(Number(match[1]) + Number(quantity));
	const tail = match[3].trim();
	const unitMatch = tail.match(/^(tsp|teaspoons?|tbsp|tablespoons?|cups?|ml|l|liters?|litres?|g|grams?|kg|kilograms?|oz|ounces?|lb|pounds?|pinches?|cloves?)\b\s*(.*)$/i);
	return { name: unitMatch?.[2]?.trim() || tail, quantity, unit: unitMatch?.[1] ?? '', note: '' };
}
