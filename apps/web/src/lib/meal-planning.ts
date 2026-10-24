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

export function parseMealPlanDate(value: string): Date | null {
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
	if (!match) return null;

	const year = Number(match[1]);
	const month = Number(match[2]);
	const day = Number(match[3]);
	const date = new Date(year, month - 1, day);

	if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return null;

	return date;
}
