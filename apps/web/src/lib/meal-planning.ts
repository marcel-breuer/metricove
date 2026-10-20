export type MealType = 'breakfast' | 'lunch' | 'dinner';
export type MealDishType = 'free_text' | 'recipe';
export type MealPlanVisibility = 'private' | 'household';

export type MealPlanRecipe = { id: number; title: string };

export type MealPlanSlot = {
	id: number;
	planned_for: string;
	meal_type: MealType;
	dish_type: MealDishType;
	dish_name: string | null;
	dish_label: string;
	recipe: MealPlanRecipe | null;
	servings: number;
	notes: string | null;
	visibility: MealPlanVisibility;
	is_private: boolean;
	can_edit: boolean;
};

export type MealPlanWeek = {
	week_start: string;
	week_end: string;
	slots: MealPlanSlot[];
};
