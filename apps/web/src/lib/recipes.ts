export type RecipeVisibility = 'private' | 'household';

export type RecipeIngredient = {
	id: number;
	position: number;
	name: string;
	quantity: string;
	unit: string;
	note: string | null;
};

export type Recipe = {
	id: number;
	title: string;
	category: string | null;
	instructions: string;
	servings: number;
	preparation_minutes: number | null;
	visibility: RecipeVisibility;
	is_favorite: boolean;
	can_edit: boolean;
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

export type RecipeResponse = {
	message?: string;
	recipes?: Recipe[];
	recipe?: Recipe;
	errors?: Record<string, string[]>;
};
