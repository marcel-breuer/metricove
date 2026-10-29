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
};

export type ReceiptPreview = {
	preview_token: string;
	suggestions: ReceiptSuggestions;
	default_currency: string;
	needs_confirmation: true;
};

export type ReceiptDocument = {
	id: number;
	target_type: 'finance' | 'vehicle' | null;
	target_record_id: number | null;
	target_linked: boolean;
	merchant: string | null;
	invoice_number: string | null;
	invoice_date: string;
	amount: string;
	currency: string;
	tax_amount: string | null;
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
	receipt?: ReceiptDocument;
	preview_token?: string;
	suggestions?: ReceiptSuggestions;
	default_currency?: string;
	needs_confirmation?: true;
	finance_available?: boolean;
	finance_categories?: ReceiptFinanceCategory[];
	vehicles_available?: boolean;
	vehicles?: Vehicle[];
	target?: { type: 'finance' | 'vehicle'; id: number; record?: Record<string, unknown> | null } | null;
	errors?: Record<string, string[]>;
};
