import type { Vehicle } from '#lib/vehicles';

export type ReceiptFinanceCategory = {
	id: number;
	type: 'expense' | 'income';
	name: string;
	visibility: string;
};

export type ReceiptOptions = {
	finance_available: boolean;
	finance_categories: ReceiptFinanceCategory[];
	vehicles_available: boolean;
	vehicles: Vehicle[];
};

export type ReceiptSuggestions = {
	merchant: string | null;
	invoice_number: string | null;
	invoice_date: string | null;
	amount: string | null;
	currency: string | null;
	tax_amount: string | null;
	fuel_liters: string | null;
	fuel_unit_price: string | null;
	fuel_odometer_value: string | null;
};

export type ReceiptFieldStatus = 'recognized' | 'uncertain' | 'missing';

export type ReceiptDuplicateMatch = {
	id: number;
	merchant: string | null;
	invoice_date: string;
	amount: string;
	currency: string;
	reasons: string[];
};

export type ReceiptPreview = {
	preview_token: string;
	suggestions: ReceiptSuggestions;
	field_status: Record<string, ReceiptFieldStatus>;
	review_required: string[];
	duplicate_matches: ReceiptDuplicateMatch[];
	default_currency: string;
	needs_confirmation: true;
};

export type ReceiptDocument = {
	id: number;
	target_type: 'finance' | 'vehicle' | null;
	target_record_id: number | null;
	target_linked: boolean;
	target_category: string | null;
	merchant: string | null;
	description: string | null;
	invoice_number: string | null;
	invoice_date: string;
	amount: string;
	currency: string;
	tax_amount: string | null;
	fuel_liters: string | null;
	fuel_unit_price: string | null;
	fuel_odometer_value: string | null;
	mime_type: string;
	file_url: string;
	created_at: string | null;
	shared_with_household: boolean;
	is_owner: boolean;
};

export type ReceiptPagination = {
	current_page: number;
	last_page: number;
	per_page: number;
	total: number;
};

export type ReceiptResponse = {
	message?: string;
	receipts?: ReceiptDocument[];
	pagination?: ReceiptPagination;
	currencies?: string[];
	category_filters?: Array<{ value: string; label: string }>;
	receipt?: ReceiptDocument;
	preview_token?: string;
	suggestions?: ReceiptSuggestions;
	field_status?: Record<string, ReceiptFieldStatus>;
	review_required?: string[];
	duplicate_matches?: ReceiptDuplicateMatch[];
	default_currency?: string;
	needs_confirmation?: true;
	finance_available?: boolean;
	finance_categories?: ReceiptFinanceCategory[];
	vehicles_available?: boolean;
	vehicles?: Vehicle[];
	targets?: Array<{ id: number; label: string; date: string; amount: string; currency: string }>;
	create_categories?: ReceiptFinanceCategory[];
	create_vehicles?: Vehicle[];
	target?: { type: 'finance' | 'vehicle'; id: number; record?: Record<string, unknown> | null } | null;
	errors?: Record<string, string[]>;
};
