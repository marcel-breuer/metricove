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
	merchant: string | null;
	invoice_number: string | null;
	invoice_date: string;
	amount: string;
	currency: string;
	tax_amount: string | null;
	mime_type: string;
	file_url: string;
	created_at: string | null;
};

export type ReceiptResponse = {
	message?: string;
	receipts?: ReceiptDocument[];
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
