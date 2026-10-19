export type FinanceType = 'income' | 'expense';
export type FinanceVisibility = 'private' | 'household';
export type FinanceFrequency = 'weekly' | 'monthly' | 'quarterly' | 'yearly';

export type FinanceCategory = {
	id: number;
	type: FinanceType;
	name: string;
	visibility: FinanceVisibility;
};

export type FinancialTransaction = {
	id: number;
	category: FinanceCategory | null;
	finance_category_id: number | null;
	type: FinanceType;
	description: string;
	amount: string;
	currency: string;
	occurred_on: string;
	visibility: FinanceVisibility;
	recurring_entry_id: number | null;
	recurring_occurrence_on: string | null;
};

export type FinanceRecurringEntry = {
	id: number;
	category: FinanceCategory | null;
	finance_category_id: number | null;
	type: FinanceType;
	description: string;
	amount: string;
	currency: string;
	frequency: FinanceFrequency;
	starts_on: string;
	next_due_on: string | null;
	ends_on: string | null;
	is_active: boolean;
	visibility: FinanceVisibility;
};

export type FinanceBudget = {
	id: number;
	category: FinanceCategory | null;
	finance_category_id: number | null;
	amount: string;
	spent: string | null;
	remaining: string | null;
	currency: string;
	period_start: string;
	visibility: FinanceVisibility;
};

export type FinanceTotals = {
	currency: string;
	income: string;
	expenses: string;
	net: string;
};

export type FinanceOverview = {
	month: string;
	totals: FinanceTotals[];
	budgets: FinanceBudget[];
};

export type FinanceResponse = {
	message?: string;
	categories?: FinanceCategory[];
	category?: FinanceCategory;
	transactions?: FinancialTransaction[];
	transaction?: FinancialTransaction;
	recurring_entries?: FinanceRecurringEntry[];
	recurring_entry?: FinanceRecurringEntry;
	budgets?: FinanceBudget[];
	budget?: FinanceBudget;
	month?: string;
	totals?: FinanceTotals[];
	errors?: Record<string, string[]>;
};

export const financeFrequencies: Array<{ value: FinanceFrequency; label: string }> = [
	{ value: 'weekly', label: 'Weekly' },
	{ value: 'monthly', label: 'Monthly' },
	{ value: 'quarterly', label: 'Quarterly' },
	{ value: 'yearly', label: 'Yearly' }
];
