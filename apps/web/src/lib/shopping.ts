export type ShoppingVisibility = 'private' | 'household';

export type ShoppingList = {
	id: number;
	name: string;
	visibility: ShoppingVisibility;
	is_private: boolean;
	version: number;
	item_count: number;
	open_item_count: number;
	can_edit: boolean;
	can_add_items: boolean;
	created_at: string | null;
	updated_at: string | null;
};

export type ShoppingItem = {
	id: number;
	name: string;
	quantity: string | null;
	unit: string | null;
	sort_order: number;
	is_checked: boolean;
	checked_at: string | null;
	checked_by: string | null;
	version: number;
	can_edit: boolean;
	created_at: string | null;
	updated_at: string | null;
};

export type ShoppingActivity = {
	id: number;
	type: string;
	item_id: number | null;
	actor: string | null;
	before: Record<string, unknown> | null;
	after: Record<string, unknown> | null;
	created_at: string | null;
};

export type MealShoppingSuggestion = {
	id: string;
	name: string;
	quantity: string;
	unit: string;
	source_count: number;
	pantry_quantity_used: string;
	pantry_status: 'not_requested' | 'unavailable' | 'none' | 'unit_mismatch' | 'partially_covered';
};

export type MealSuggestionPreview = {
	week_start: string;
	subtract_pantry: boolean;
	list_version: number;
	preview_expires_at: number;
	preview_token: string;
	availability: {
		meal_planning: boolean;
		recipes: boolean;
		pantry: boolean;
		pantry_subtracted: boolean;
	};
	skipped: {
		free_text_meals: number;
		unavailable_recipes: number;
		unsupported_quantities: number;
		covered_by_pantry: number;
	};
	suggestions: MealShoppingSuggestion[];
};

export type MealSuggestionsResponse = {
	preview?: MealSuggestionPreview;
	items?: ShoppingItem[];
	message?: string;
	selected_count?: number;
	errors?: Record<string, string[]>;
};

export type ShoppingListsResponse = {
	lists?: ShoppingList[];
	list?: ShoppingList;
	items?: ShoppingItem[];
	item?: ShoppingItem;
	events?: ShoppingActivity[];
	message?: string;
	errors?: Record<string, string[]>;
};
