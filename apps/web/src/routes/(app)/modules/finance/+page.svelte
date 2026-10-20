<script lang="ts">
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { formatNumber, formatCurrency } from '#lib/number-format';
	import {
		financeFrequencies,
		type FinanceBudget,
		type FinanceCategory,
		type FinanceFrequency,
		type FinanceOverview,
		type FinanceRecurringEntry,
		type FinanceResponse,
		type FinanceType,
		type FinanceVisibility,
		type FinancialTransaction
	} from '#lib/finance';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'finance'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreate = $derived(access?.capabilities.create ?? false);
	const canEdit = $derived(access?.capabilities.edit ?? false);
	const canManageShared = $derived(shell.currentUser?.is_admin === true && (access?.capabilities.manage ?? false));
	const canEditCategory = (category: FinanceCategory): boolean =>
		category.visibility === 'household' ? canManageShared : canEdit;

	function localDate(date = new Date()): string {
		return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
	}

	let month = $state(localDate().slice(0, 7));
	let overview = $state<FinanceOverview | null>(null);
	let categories = $state<FinanceCategory[]>([]);
	let transactions = $state<FinancialTransaction[]>([]);
	let recurringEntries = $state<FinanceRecurringEntry[]>([]);
	let loading = $state(true);
	let saving = $state(false);
	let error = $state('');
	let message = $state('');
	let formError = $state('');

	let categoryId = $state<number | null>(null);
	let categoryName = $state('');
	let categoryType = $state<FinanceType>('expense');
	let categoryVisibility = $state<FinanceVisibility>('private');

	let transactionId = $state<number | null>(null);
	let transactionType = $state<FinanceType>('expense');
	let transactionCategory = $state('');
	let transactionDescription = $state('');
	let transactionAmount = $state('');
	let transactionCurrency = $state('EUR');
	let transactionDate = $state(localDate());
	let transactionVisibility = $state<FinanceVisibility>('private');

	let budgetId = $state<number | null>(null);
	let budgetCategory = $state('');
	let budgetAmount = $state('');
	let budgetCurrency = $state('EUR');
	let budgetVisibility = $state<FinanceVisibility>('private');

	let recurringId = $state<number | null>(null);
	let recurringType = $state<FinanceType>('expense');
	let recurringCategory = $state('');
	let recurringDescription = $state('');
	let recurringAmount = $state('');
	let recurringCurrency = $state('EUR');
	let recurringFrequency = $state<FinanceFrequency>('monthly');
	let recurringStartsOn = $state(localDate());
	let recurringNextDueOn = $state(localDate());
	let recurringEndsOn = $state('');
	let recurringActive = $state(true);
	let recurringVisibility = $state<FinanceVisibility>('private');
	const visibleTransactions = $derived(transactions);
	const expenseCategories = $derived(categories.filter((category) => category.type === 'expense'));
	const typeCategories = $derived(categories.filter((category) => category.type === transactionType));
	const recurringCategories = $derived(categories.filter((category) => category.type === recurringType));

	$effect(() => {
		if (!shell.loading) void loadFinance();
	});

	async function api<T extends FinanceResponse>(path: string, method = 'GET', body?: object): Promise<T> {
		const headers: Record<string, string> = { Accept: 'application/json' };
		if (method !== 'GET') {
			const token = csrfToken();
			if (!token) throw new Error('Reload this page and try again.');
			headers['X-XSRF-TOKEN'] = token;
			if (body) headers['Content-Type'] = 'application/json';
		}
		const response = await fetch(path, {
			method,
			credentials: 'same-origin',
			headers,
			...(body ? { body: JSON.stringify(body) } : {})
		});
		if (response.status === 401) {
			await goto('/login');
			throw new Error('Your session has expired.');
		}
		const result = (await response.json()) as T & ApiResponse;
		if (!response.ok) throw new Error(firstError(result) || result.message || 'Finance could not complete that request.');
		return result as T;
	}

	async function loadFinance(): Promise<void> {
		loading = true;
		error = '';
		if (!canView) {
			transactions = [];
			overview = null;
			loading = false;
			return;
		}
		try {
			const [categoryResult, transactionResult, recurringResult, overviewResult] = await Promise.all([
				api<FinanceResponse>('/api/finance/categories'),
				api<FinanceResponse>(`/api/finance/transactions?month=${month}`),
				api<FinanceResponse>('/api/finance/recurring'),
				api<FinanceOverview>(`/api/finance/overview?month=${month}`)
			]);
			categories = categoryResult.categories ?? [];
			transactions = transactionResult.transactions ?? [];
			recurringEntries = recurringResult.recurring_entries ?? [];
			overview = { month, totals: overviewResult.totals ?? [], budgets: overviewResult.budgets ?? [] };
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Finance could not be loaded.';
		} finally {
			loading = false;
		}
	}

	function shiftMonth(delta: number): void {
		const date = new Date(`${month}-01T12:00:00`);
		date.setMonth(date.getMonth() + delta);
		month = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
	}

	function clearFormMessages(): void {
		message = '';
		formError = '';
	}

	function resetCategory(): void {
		categoryId = null;
		categoryName = '';
		categoryType = 'expense';
		categoryVisibility = canCreate ? 'private' : 'household';
	}

	function resetTransaction(): void {
		transactionId = null;
		transactionType = 'expense';
		transactionCategory = '';
		transactionDescription = '';
		transactionAmount = '';
		transactionCurrency = 'EUR';
		transactionDate = localDate();
		transactionVisibility = canCreate ? 'private' : 'household';
	}

	function resetBudget(): void {
		budgetId = null;
		budgetCategory = '';
		budgetAmount = '';
		budgetCurrency = 'EUR';
		budgetVisibility = canCreate ? 'private' : 'household';
	}

	function resetRecurring(): void {
		recurringId = null;
		recurringType = 'expense';
		recurringCategory = '';
		recurringDescription = '';
		recurringAmount = '';
		recurringCurrency = 'EUR';
		recurringFrequency = 'monthly';
		recurringStartsOn = localDate();
		recurringNextDueOn = localDate();
		recurringEndsOn = '';
		recurringActive = true;
		recurringVisibility = canCreate ? 'private' : 'household';
	}

	async function saveCategory(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		clearFormMessages();
		saving = true;
		try {
			const result = await api<FinanceResponse>(categoryId ? `/api/finance/categories/${categoryId}` : '/api/finance/categories', categoryId ? 'PATCH' : 'POST', categoryId ? { name: categoryName } : { name: categoryName, type: categoryType, visibility: categoryVisibility });
			message = categoryId ? 'Category updated.' : 'Category created.';
			if (!canView && result.category) categories = [...categories.filter((entry) => entry.id !== result.category?.id), result.category];
			resetCategory();
			await loadFinance();
		} catch (cause) {
			formError = cause instanceof Error ? cause.message : 'The category could not be saved.';
		} finally { saving = false; }
	}

	function editCategory(category: FinanceCategory): void {
		clearFormMessages();
		categoryId = category.id;
		categoryName = category.name;
		categoryType = category.type;
		categoryVisibility = category.visibility;
	}

	async function saveTransaction(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		clearFormMessages();
		saving = true;
		const payload: Record<string, unknown> = { description: transactionDescription, amount: transactionAmount, currency: transactionCurrency, occurred_on: transactionDate };
		if (transactionCategory !== '') payload.finance_category_id = Number(transactionCategory);
		if (transactionId === null || transactionCategory !== '') payload.type = transactionType;
		try {
			if (transactionId === null) payload.visibility = transactionVisibility;
			await api<FinanceResponse>(transactionId ? `/api/finance/transactions/${transactionId}` : '/api/finance/transactions', transactionId ? 'PATCH' : 'POST', payload);
			message = transactionId ? 'Transaction updated.' : 'Transaction created.';
			resetTransaction();
			await loadFinance();
		} catch (cause) {
			formError = cause instanceof Error ? cause.message : 'The transaction could not be saved.';
		} finally { saving = false; }
	}

	function editTransaction(transaction: FinancialTransaction): void {
		clearFormMessages();
		transactionId = transaction.id;
		transactionType = transaction.type;
		transactionCategory = transaction.finance_category_id === null ? '' : String(transaction.finance_category_id);
		transactionDescription = transaction.description;
		transactionAmount = transaction.amount;
		transactionCurrency = transaction.currency;
		transactionDate = transaction.occurred_on;
		transactionVisibility = transaction.visibility;
	}

	async function saveBudget(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		clearFormMessages();
		saving = true;
		const payload: Record<string, unknown> = { amount: budgetAmount, currency: budgetCurrency, period_start: `${month}-01` };
		if (budgetCategory !== '') payload.finance_category_id = Number(budgetCategory);
		try {
			if (budgetId === null) payload.visibility = budgetVisibility;
			await api<FinanceResponse>(budgetId ? `/api/finance/budgets/${budgetId}` : '/api/finance/budgets', budgetId ? 'PATCH' : 'POST', payload);
			message = budgetId ? 'Budget updated.' : 'Budget created.';
			resetBudget();
			await loadFinance();
		} catch (cause) {
			formError = cause instanceof Error ? cause.message : 'The budget could not be saved.';
		} finally { saving = false; }
	}

	function editBudget(budget: FinanceBudget): void {
		clearFormMessages();
		budgetId = budget.id;
		budgetCategory = budget.finance_category_id === null ? '' : String(budget.finance_category_id);
		budgetAmount = budget.amount;
		budgetCurrency = budget.currency;
		budgetVisibility = budget.visibility;
	}

	async function saveRecurring(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		clearFormMessages();
		saving = true;
		const payload: Record<string, unknown> = { description: recurringDescription, amount: recurringAmount, currency: recurringCurrency, frequency: recurringFrequency, starts_on: recurringStartsOn, next_due_on: recurringNextDueOn || null, ends_on: recurringEndsOn || null, is_active: recurringActive };
		if (recurringCategory !== '') payload.finance_category_id = Number(recurringCategory);
		if (recurringId === null || recurringCategory !== '') payload.type = recurringType;
		try {
			if (recurringId === null) payload.visibility = recurringVisibility;
			await api<FinanceResponse>(recurringId ? `/api/finance/recurring/${recurringId}` : '/api/finance/recurring', recurringId ? 'PATCH' : 'POST', payload);
			message = recurringId ? 'Recurring entry updated.' : 'Recurring entry created.';
			resetRecurring();
			await loadFinance();
		} catch (cause) {
			formError = cause instanceof Error ? cause.message : 'The recurring entry could not be saved.';
		} finally { saving = false; }
	}

	function editRecurring(entry: FinanceRecurringEntry): void {
		clearFormMessages();
		recurringId = entry.id;
		recurringType = entry.type;
		recurringCategory = entry.finance_category_id === null ? '' : String(entry.finance_category_id);
		recurringDescription = entry.description;
		recurringAmount = entry.amount;
		recurringCurrency = entry.currency;
		recurringFrequency = entry.frequency;
		recurringStartsOn = entry.starts_on;
		recurringNextDueOn = entry.next_due_on ?? '';
		recurringEndsOn = entry.ends_on ?? '';
		recurringActive = entry.is_active;
		recurringVisibility = entry.visibility;
	}

	async function remove(path: string, subject: string): Promise<void> {
		if (!window.confirm(`Delete this ${subject}? This cannot be undone.`)) return;
		clearFormMessages();
		try {
			await api<FinanceResponse>(path, 'DELETE');
			message = `${subject[0].toUpperCase()}${subject.slice(1)} deleted.`;
			await loadFinance();
		} catch (cause) {
			formError = cause instanceof Error ? cause.message : `The ${subject} could not be deleted.`;
		}
	}

	async function recordOccurrence(entry: FinanceRecurringEntry): Promise<void> {
		if (!entry.next_due_on) return;
		clearFormMessages();
		try {
			await api<FinanceResponse>(`/api/finance/recurring/${entry.id}/record`, 'POST', { occurrence_on: entry.next_due_on });
			message = `${entry.description} was recorded.`;
			await loadFinance();
		} catch (cause) {
			formError = cause instanceof Error ? cause.message : 'The recurring occurrence could not be recorded.';
		}
	}

	function canMutate(visibility: FinanceVisibility): boolean {
		return visibility === 'household' ? canManageShared : canEdit;
	}
</script>

<svelte:head>
	<title>Finance · Metricove</title>
	<meta name="description" content="Track private or shared income, spending, recurring entries and monthly budgets." />
</svelte:head>

<section class="mx-auto max-w-screen-xl">
	<div class="flex flex-col gap-5 border-b border-slate-800 pb-6 sm:flex-row sm:items-end sm:justify-between">
		<div><p class="text-sm font-medium tracking-wide text-cyan-200">Finance module</p><h1 class="mt-2 text-3xl font-semibold tracking-tight text-white sm:text-4xl">A clearer view of your money.</h1><p class="mt-3 max-w-2xl text-sm leading-6 text-slate-400">Record income and spending, set a monthly category budget and keep recurring charges ready to record when they are due.</p></div>
		<div class="flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900/60 p-1" aria-label="Budget month">
			<button type="button" class="min-h-10 min-w-10 rounded-lg text-lg text-slate-200 hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-cyan-300" aria-label="Previous month" onclick={() => shiftMonth(-1)}>‹</button>
			<label class="sr-only" for="finance-month">Budget month</label><input id="finance-month" type="month" bind:value={month} class="min-h-10 w-36 rounded-lg border-0 bg-transparent px-2 text-sm text-white focus:ring-2 focus:ring-cyan-300/30" />
			<button type="button" class="min-h-10 min-w-10 rounded-lg text-lg text-slate-200 hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-cyan-300" aria-label="Next month" onclick={() => shiftMonth(1)}>›</button>
		</div>
	</div>

	{#if message}<p role="status" class="mt-5 rounded-xl border border-emerald-300/30 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">{message}</p>{/if}
	{#if formError}<p role="alert" class="mt-5 rounded-xl border border-rose-300/30 bg-rose-300/10 px-4 py-3 text-sm text-rose-100">{formError}</p>{/if}
	{#if error}<p role="alert" class="mt-5 rounded-xl border border-rose-300/30 bg-rose-300/10 px-4 py-3 text-sm text-rose-100">{error}</p>{/if}

	{#if shell.loading || loading}
		<div role="status" class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/60 p-6 text-sm text-slate-300">Loading finance records…</div>
	{:else if !canView && !canCreate && !canManageShared}
		<div class="mt-8 rounded-2xl border border-amber-300/20 bg-amber-300/5 p-5 text-sm leading-6 text-amber-100"><p class="font-semibold">View access is not enabled.</p><p class="mt-1 text-amber-100/75">Financial records are private. Ask an administrator for Finance View access to browse your transactions and budget overview.</p></div>
	{:else}
		{#if !canView}<div class="mt-7 rounded-xl border border-amber-300/20 bg-amber-300/5 px-4 py-3 text-sm leading-6 text-amber-100">You can add private records, but browsing and budget summaries require an explicit Finance View grant.</div>{/if}
		{#if overview}
			<section class="mt-7 grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-label="Monthly totals">
				{#each overview.totals as total (total.currency)}
					<article class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5"><p class="text-xs font-medium uppercase tracking-[0.14em] text-slate-400">{total.currency} · {month}</p><div class="mt-4 grid grid-cols-3 gap-3"><div><p class="text-xs text-slate-500">Income</p><p class="mt-1 text-sm font-semibold text-emerald-200">{formatNumber(total.income, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p></div><div><p class="text-xs text-slate-500">Spending</p><p class="mt-1 text-sm font-semibold text-rose-200">{formatNumber(total.expenses, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p></div><div><p class="text-xs text-slate-500">Net</p><p class="mt-1 text-sm font-semibold text-white">{formatNumber(total.net, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p></div></div></article>
				{:else}
					<article class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5 sm:col-span-2 xl:col-span-3"><p class="text-sm font-medium text-slate-200">No transactions for {month}</p><p class="mt-1 text-sm text-slate-400">Your totals will appear here once you add income or spending.</p></article>
				{/each}
			</section>
		{/if}

		{#if canCreate || canManageShared}
			<section class="mt-7 rounded-2xl border border-slate-800 bg-slate-900/60 p-5 sm:p-7" aria-labelledby="transaction-form-title">
				<div><p class="text-sm font-medium text-cyan-200">{transactionId ? 'Edit record' : 'Quick entry'}</p><h2 id="transaction-form-title" class="mt-1 text-xl font-semibold text-white">{transactionId ? 'Update a transaction.' : 'Add a transaction.'}</h2></div>
				{#if categories.length === 0}<p class="mt-4 rounded-xl border border-amber-300/20 bg-amber-300/5 px-4 py-3 text-sm text-amber-100">Create a category below before recording a transaction.</p>{/if}
				<form class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" onsubmit={saveTransaction}>
					<label class="block text-sm font-medium text-slate-200">Type<select bind:value={transactionType} disabled={transactionId !== null && transactionCategory === ''} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20 disabled:opacity-50"><option value="expense">Expense</option><option value="income">Income</option></select></label>
					<label class="block text-sm font-medium text-slate-200">Category<select bind:value={transactionCategory} required={transactionId === null || transactionCategory !== ''} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"><option value="">Choose a category</option>{#each typeCategories as category (category.id)}<option value={String(category.id)}>{category.name}{category.visibility === 'household' ? ' · household' : ''}</option>{/each}</select></label>
					<label class="block text-sm font-medium text-slate-200">Amount<input bind:value={transactionAmount} type="text" inputmode="decimal" pattern="[0-9]+(\.[0-9][0-9]?)?" required maxlength="19" placeholder="42.50" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
					<label class="block text-sm font-medium text-slate-200">Currency<input bind:value={transactionCurrency} required minlength="3" maxlength="3" pattern="[A-Za-z]{3}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm uppercase text-white focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
					<label class="block text-sm font-medium text-slate-200 sm:col-span-2">Description<input bind:value={transactionDescription} required maxlength="200" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder="Groceries" /></label>
					<label class="block text-sm font-medium text-slate-200">Date<input bind:value={transactionDate} type="date" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
					{#if !transactionId && canManageShared}<label class="block text-sm font-medium text-slate-200">Visibility<select bind:value={transactionVisibility} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"><option value="private">Private</option><option value="household">Shared household</option></select></label>{/if}
					<div class="flex flex-col-reverse gap-3 border-t border-slate-800 pt-4 sm:col-span-2 xl:col-span-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-xs leading-5 text-slate-500">Private transactions stay visible only to their owner unless access is granted for that record.</p><div class="flex flex-wrap gap-2"><button type="submit" disabled={saving || categories.length === 0} class="min-h-11 rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 hover:bg-cyan-200 disabled:cursor-wait disabled:opacity-60">{saving ? 'Saving…' : transactionId ? 'Save changes' : 'Add transaction'}</button>{#if transactionId}<button type="button" class="min-h-11 rounded-xl border border-slate-700 px-4 text-sm font-semibold text-slate-200 hover:border-slate-500" onclick={resetTransaction}>Cancel</button>{/if}</div></div>
				</form>
			</section>
		{/if}

		<section class="mt-8" aria-labelledby="transactions-title">
			<div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-sm font-medium text-cyan-200">Activity</p><h2 id="transactions-title" class="mt-1 text-2xl font-semibold text-white">Transactions</h2></div><p class="text-sm text-slate-400">Showing up to 200 records for {month}.</p></div>
			{#if visibleTransactions.length === 0}<p class="mt-4 rounded-xl border border-slate-800 bg-slate-900/40 p-5 text-sm text-slate-400">No transactions are visible for this month.</p>{:else}
				<ul class="mt-4 divide-y divide-slate-800 overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/50">
					{#each visibleTransactions as transaction (transaction.id)}
						<li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><p class="truncate font-medium text-white">{transaction.description}</p><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {transaction.type === 'income' ? 'bg-emerald-300/10 text-emerald-200' : 'bg-rose-300/10 text-rose-200'}">{transaction.type}</span><span class="text-xs text-slate-500">{transaction.visibility}</span></div><p class="mt-1 text-sm text-slate-400">{transaction.category?.name ?? 'Uncategorized'} · {transaction.occurred_on}</p></div><div class="flex items-center justify-between gap-3 sm:justify-end"><p class="whitespace-nowrap text-sm font-semibold {transaction.type === 'income' ? 'text-emerald-200' : 'text-white'}">{transaction.type === 'expense' ? '−' : '+'}{formatCurrency(transaction.amount, transaction.currency)}</p>{#if canMutate(transaction.visibility)}<div class="flex gap-2"><button type="button" class="min-h-10 rounded-lg border border-slate-700 px-3 text-xs font-semibold text-slate-200 hover:border-cyan-300" aria-label={`Edit ${transaction.description}`} onclick={() => editTransaction(transaction)}>Edit</button><button type="button" class="min-h-10 rounded-lg border border-slate-700 px-3 text-xs font-semibold text-rose-200 hover:border-rose-300" aria-label={`Delete ${transaction.description}`} onclick={() => remove(`/api/finance/transactions/${transaction.id}`, 'transaction')}>Delete</button></div>{/if}</div></li>
					{/each}
				</ul>
			{/if}
		</section>

		<div class="mt-8 grid gap-6 xl:grid-cols-2">
			<section class="rounded-2xl border border-slate-800 bg-slate-900/50 p-5 sm:p-6" aria-labelledby="budgets-title">
				<p class="text-sm font-medium text-cyan-200">Plan</p><h2 id="budgets-title" class="mt-1 text-xl font-semibold text-white">Monthly budgets</h2>
				{#if (canCreate || canManageShared) && expenseCategories.length > 0}
					<form class="mt-4 grid gap-3 sm:grid-cols-2" onsubmit={saveBudget}>
						<label class="block text-sm font-medium text-slate-200 sm:col-span-2">Expense category<select bind:value={budgetCategory} required={budgetId === null} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"><option value="">Choose a category</option>{#each expenseCategories as category (category.id)}<option value={String(category.id)}>{category.name}</option>{/each}</select></label>
						<label class="block text-sm font-medium text-slate-200">Monthly limit<input bind:value={budgetAmount} required inputmode="decimal" pattern="[0-9]+(\.[0-9][0-9]?)?" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder="500.00" /></label>
						<label class="block text-sm font-medium text-slate-200">Currency<input bind:value={budgetCurrency} required minlength="3" maxlength="3" pattern="[A-Za-z]{3}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm uppercase text-white focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
						{#if !budgetId && canManageShared}<label class="block text-sm font-medium text-slate-200">Visibility<select bind:value={budgetVisibility} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white"><option value="private">Private</option><option value="household">Shared household</option></select></label>{/if}
						<div class="flex flex-wrap gap-2 sm:col-span-2"><button type="submit" disabled={saving} class="min-h-10 rounded-xl bg-cyan-300 px-4 text-sm font-semibold text-slate-950 disabled:opacity-60">{budgetId ? 'Save budget' : `Set ${month} budget`}</button>{#if budgetId}<button type="button" class="min-h-10 rounded-xl border border-slate-700 px-4 text-sm text-slate-200" onclick={resetBudget}>Cancel</button>{/if}</div>
					</form>
				{/if}
				{#if overview?.budgets.length}
					<ul class="mt-5 space-y-3">{#each overview.budgets as budget (budget.id)}<li class="rounded-xl border border-slate-800 bg-slate-950/50 p-4"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="font-medium text-white">{budget.category?.name ?? 'Category'} <span class="text-xs font-normal text-slate-500">{budget.visibility}</span></p><p class="mt-1 text-sm text-slate-400">{formatCurrency(budget.spent, budget.currency)} of {formatCurrency(budget.amount, budget.currency)} · remaining {formatCurrency(budget.remaining, budget.currency)}</p></div>{#if canMutate(budget.visibility)}<div class="flex gap-2"><button type="button" class="min-h-9 rounded-lg border border-slate-700 px-3 text-xs text-slate-200" onclick={() => editBudget(budget)}>Edit</button><button type="button" class="min-h-9 rounded-lg border border-slate-700 px-3 text-xs text-rose-200" onclick={() => remove(`/api/finance/budgets/${budget.id}`, 'budget')}>Delete</button></div>{/if}</div><div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-800"><div class="h-full rounded-full bg-cyan-300" style={`width: ${Math.min(100, (Number(budget.spent) / Number(budget.amount)) * 100)}%`}></div></div></li>{/each}</ul>
				{:else}<p class="mt-5 rounded-xl border border-dashed border-slate-700 p-4 text-sm leading-6 text-slate-400">No budget is set for this month. Add one to compare planned spending with visible expense transactions.</p>{/if}
			</section>

			<section class="rounded-2xl border border-slate-800 bg-slate-900/50 p-5 sm:p-6" aria-labelledby="category-title">
				<p class="text-sm font-medium text-cyan-200">Organize</p><h2 id="category-title" class="mt-1 text-xl font-semibold text-white">Categories</h2>
				{#if canCreate || canManageShared}
					<form class="mt-4 grid gap-3 sm:grid-cols-2" onsubmit={saveCategory}>
						<label class="block text-sm font-medium text-slate-200 sm:col-span-2">Name<input bind:value={categoryName} required maxlength="80" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder="Housing" /></label>
						{#if !categoryId}<label class="block text-sm font-medium text-slate-200">Type<select bind:value={categoryType} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"><option value="expense">Expense</option><option value="income">Income</option></select></label>{#if canManageShared}<label class="block text-sm font-medium text-slate-200">Visibility<select bind:value={categoryVisibility} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"><option value="private">Private</option><option value="household">Shared household</option></select></label>{/if}{/if}
						<div class="flex flex-wrap gap-2 sm:col-span-2"><button type="submit" disabled={saving} class="min-h-10 rounded-xl bg-cyan-300 px-4 text-sm font-semibold text-slate-950 disabled:opacity-60">{categoryId ? 'Save category' : 'Add category'}</button>{#if categoryId}<button type="button" class="min-h-10 rounded-xl border border-slate-700 px-4 text-sm text-slate-200" onclick={resetCategory}>Cancel</button>{/if}</div>
					</form>
				{/if}
				{#if categories.length}<ul class="mt-5 divide-y divide-slate-800">{#each categories as category (category.id)}<li class="flex items-center justify-between gap-3 py-3"><div class="min-w-0"><p class="truncate text-sm font-medium text-slate-100">{category.name}</p><p class="mt-0.5 text-xs text-slate-500">{category.type} · {category.visibility}</p></div>{#if canEditCategory(category)}<div class="flex gap-2"><button type="button" class="min-h-9 rounded-lg border border-slate-700 px-3 text-xs text-slate-200" onclick={() => editCategory(category)}>Edit</button><button type="button" class="min-h-9 rounded-lg border border-slate-700 px-3 text-xs text-rose-200" onclick={() => remove(`/api/finance/categories/${category.id}`, 'category')}>Delete</button></div>{/if}</li>{/each}</ul>{:else}<p class="mt-4 text-sm text-slate-400">No categories are visible yet.</p>{/if}
			</section>
		</div>

		<section class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/50 p-5 sm:p-6" aria-labelledby="recurring-title">
			<div><p class="text-sm font-medium text-cyan-200">On a schedule</p><h2 id="recurring-title" class="mt-1 text-xl font-semibold text-white">Recurring entries</h2><p class="mt-1 text-sm text-slate-400">A due occurrence is added to transactions only when you record it.</p></div>
			{#if canCreate || canManageShared}
				<form class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" onsubmit={saveRecurring}>
					<label class="block text-sm font-medium text-slate-200">Type<select bind:value={recurringType} disabled={recurringId !== null && recurringCategory === ''} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white disabled:opacity-50"><option value="expense">Expense</option><option value="income">Income</option></select></label>
					<label class="block text-sm font-medium text-slate-200">Category<select bind:value={recurringCategory} required={recurringId === null || recurringCategory !== ''} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white"><option value="">Choose a category</option>{#each recurringCategories as category (category.id)}<option value={String(category.id)}>{category.name}</option>{/each}</select></label>
					<label class="block text-sm font-medium text-slate-200">Amount<input bind:value={recurringAmount} required inputmode="decimal" pattern="[0-9]+(\.[0-9][0-9]?)?" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" placeholder="49.99" /></label>
					<label class="block text-sm font-medium text-slate-200">Currency<input bind:value={recurringCurrency} required minlength="3" maxlength="3" pattern="[A-Za-z]{3}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm uppercase text-white" /></label>
					<label class="block text-sm font-medium text-slate-200 sm:col-span-2">Description<input bind:value={recurringDescription} required maxlength="200" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" placeholder="Internet plan" /></label>
					<label class="block text-sm font-medium text-slate-200">Frequency<select bind:value={recurringFrequency} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white">{#each financeFrequencies as frequency}<option value={frequency.value}>{frequency.label}</option>{/each}</select></label>
					<label class="block text-sm font-medium text-slate-200">Starts on<input bind:value={recurringStartsOn} type="date" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" /></label>
					<label class="block text-sm font-medium text-slate-200">Next due<input bind:value={recurringNextDueOn} type="date" required={recurringActive} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" /></label>
					<label class="block text-sm font-medium text-slate-200">Ends on <span class="font-normal text-slate-500">(optional)</span><input bind:value={recurringEndsOn} type="date" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" /></label>
					{#if recurringId}<label class="flex min-h-11 items-center gap-2 text-sm text-slate-200"><input bind:checked={recurringActive} type="checkbox" class="size-4 accent-cyan-300" />Schedule active</label>{:else if canManageShared}<label class="block text-sm font-medium text-slate-200">Visibility<select bind:value={recurringVisibility} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white"><option value="private">Private</option><option value="household">Shared household</option></select></label>{/if}
					<div class="flex flex-wrap gap-2 border-t border-slate-800 pt-3 sm:col-span-2 xl:col-span-4"><button type="submit" disabled={saving} class="min-h-10 rounded-xl bg-cyan-300 px-4 text-sm font-semibold text-slate-950 disabled:opacity-60">{recurringId ? 'Save schedule' : 'Add schedule'}</button>{#if recurringId}<button type="button" class="min-h-10 rounded-xl border border-slate-700 px-4 text-sm text-slate-200" onclick={resetRecurring}>Cancel</button>{/if}</div>
				</form>
			{/if}
			{#if recurringEntries.length}<ul class="mt-5 grid gap-3 md:grid-cols-2">{#each recurringEntries as entry (entry.id)}<li class="rounded-xl border border-slate-800 bg-slate-950/50 p-4"><div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><p class="font-medium text-white">{entry.description}</p><p class="mt-1 text-sm text-slate-400">{entry.category?.name ?? 'Category'} · {entry.frequency} · {formatCurrency(entry.amount, entry.currency)}</p><p class="mt-1 text-xs text-slate-500">{entry.is_active ? `Next due ${entry.next_due_on ?? 'date unavailable'}` : 'Paused or complete'} · {entry.visibility}</p></div>{#if canCreate && (entry.visibility === 'private' || canManageShared) && entry.is_active && entry.next_due_on}<button type="button" class="min-h-10 rounded-lg bg-emerald-300 px-3 text-xs font-semibold text-slate-950 hover:bg-emerald-200" onclick={() => recordOccurrence(entry)}>Record due entry</button>{/if}</div>{#if canMutate(entry.visibility)}<div class="mt-3 flex gap-2 border-t border-slate-800 pt-3"><button type="button" class="min-h-9 rounded-lg border border-slate-700 px-3 text-xs text-slate-200" onclick={() => editRecurring(entry)}>Edit</button><button type="button" class="min-h-9 rounded-lg border border-slate-700 px-3 text-xs text-rose-200" onclick={() => remove(`/api/finance/recurring/${entry.id}`, 'recurring entry')}>Delete</button></div>{/if}</li>{/each}</ul>{:else}<p class="mt-5 text-sm text-slate-400">No recurring entries are visible.</p>{/if}
		</section>
	{/if}
</section>
