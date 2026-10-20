export type PantryVisibility = 'private' | 'household';
export type PantryExpiryStatus = 'expired' | 'today' | 'soon' | 'later' | 'none';

export type PantryItem = {
	id: number;
	name: string;
	quantity: string;
	unit: string;
	location: string | null;
	best_before_on: string | null;
	expiry_status: PantryExpiryStatus;
	days_until_best_before: number | null;
	visibility: PantryVisibility;
	is_private: boolean;
	version: number;
	can_edit: boolean;
	created_at: string | null;
	updated_at: string | null;
};

export type PantryMovement = {
	id: number;
	type: 'initial' | 'adjustment';
	quantity_before: string;
	quantity_delta: string;
	quantity_after: string;
	unit: string;
	note: string | null;
	created_at: string | null;
};

export type PantryItemsResponse = { items: PantryItem[] };
export type PantryMovementsResponse = { movements: PantryMovement[] };
export type PantryResponse = {
	message?: string;
	item?: PantryItem;
	items?: PantryItem[];
	movement?: PantryMovement;
	movements?: PantryMovement[];
	errors?: Record<string, string[]>;
};
