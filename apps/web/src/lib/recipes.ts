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
	needs_review: boolean;
	photo_url: string | null;
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
