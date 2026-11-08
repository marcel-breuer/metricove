<script lang="ts">
	import { segmented, segmentedButton } from '#lib/ui/styles';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import { t, visibilityLabel } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';
	import Modal from '#lib/ui/Modal.svelte';
	import Button from '#lib/ui/Button.svelte';
	import { canonicalizeDecimalInput, formatDecimalInput, formatNumber, formatCurrency } from '#lib/number-format';
	import { defaultCurrency, formatDate, numberLocale } from '#lib/i18n';
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
	let transactionPayee = $state('');
	let transactionAmount = $state('');
	let transactionCurrency = $state($defaultCurrency);
	let transactionDate = $state(localDate());
	let transactionVisibility = $state<FinanceVisibility>('private');

	let budgetId = $state<number | null>(null);
	let budgetCategory = $state('');
	let budgetAmount = $state('');
	let budgetCurrency = $state($defaultCurrency);
	let budgetVisibility = $state<FinanceVisibility>('private');

	let recurringId = $state<number | null>(null);
	const deleteMessages = {
		transaction: 'Delete this transaction? This cannot be undone.',
		budget: 'Delete this budget? This cannot be undone.',
		category: 'Delete this category? This cannot be undone.',
		'recurring entry': 'Delete this recurring entry? This cannot be undone.'
	};
	const deletedMessages: Record<keyof typeof deleteMessages, string> = {
		transaction: 'Transaction deleted.',
		budget: 'Budget deleted.',
		category: 'Category deleted.',
		'recurring entry': 'Recurring entry deleted.'
	};
	type DeleteSubject = keyof typeof deleteMessages;
	let pendingDelete = $state<{ path: string; subject: DeleteSubject } | null>(null);
	let deleteOpen = $state(false);
	let transactionOpen = $state(false);
	let budgetOpen = $state(false);
	let categoryOpen = $state(false);
	let recurringOpen = $state(false);
	let recurringType = $state<FinanceType>('expense');
	let recurringCategory = $state('');
	let recurringDescription = $state('');
	let recurringAmount = $state('');
	let recurringCurrency = $state($defaultCurrency);
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
		categoryOpen = false;
		categoryId = null;
		categoryName = '';
		categoryType = 'expense';
		categoryVisibility = canCreate ? 'private' : 'household';
	}

	function resetTransaction(): void {
		transactionOpen = false;
		transactionId = null;
		transactionType = 'expense';
		transactionCategory = '';
		transactionDescription = '';
		transactionPayee = '';
		transactionAmount = '';
		transactionCurrency = $defaultCurrency;
		transactionDate = localDate();
		transactionVisibility = canCreate ? 'private' : 'household';
	}

	function resetBudget(): void {
		budgetOpen = false;
		budgetId = null;
		budgetCategory = '';
		budgetAmount = '';
		budgetCurrency = $defaultCurrency;
		budgetVisibility = canCreate ? 'private' : 'household';
	}

	function resetRecurring(): void {
		recurringOpen = false;
		recurringId = null;
		recurringType = 'expense';
		recurringCategory = '';
		recurringDescription = '';
		recurringAmount = '';
		recurringCurrency = $defaultCurrency;
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
		categoryOpen = true;
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
		const payload: Record<string, unknown> = { description: transactionDescription, payee: transactionPayee || null, amount: canonicalizeDecimalInput(transactionAmount, $numberLocale), currency: transactionCurrency, occurred_on: transactionDate };
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
		transactionOpen = true;
		clearFormMessages();
		transactionId = transaction.id;
		transactionType = transaction.type;
		transactionCategory = transaction.finance_category_id === null ? '' : String(transaction.finance_category_id);
		transactionDescription = transaction.description;
		transactionPayee = transaction.payee ?? '';
		transactionAmount = formatDecimalInput(transaction.amount, $numberLocale);
		transactionCurrency = transaction.currency;
		transactionDate = transaction.occurred_on;
		transactionVisibility = transaction.visibility;
	}

	async function saveBudget(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		clearFormMessages();
		saving = true;
		const payload: Record<string, unknown> = { amount: canonicalizeDecimalInput(budgetAmount, $numberLocale), currency: budgetCurrency, period_start: `${month}-01` };
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
		budgetOpen = true;
		clearFormMessages();
		budgetId = budget.id;
		budgetCategory = budget.finance_category_id === null ? '' : String(budget.finance_category_id);
		budgetAmount = formatDecimalInput(budget.amount, $numberLocale);
		budgetCurrency = budget.currency;
		budgetVisibility = budget.visibility;
	}

	async function saveRecurring(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		clearFormMessages();
		saving = true;
		const payload: Record<string, unknown> = { description: recurringDescription, amount: canonicalizeDecimalInput(recurringAmount, $numberLocale), currency: recurringCurrency, frequency: recurringFrequency, starts_on: recurringStartsOn, next_due_on: recurringNextDueOn || null, ends_on: recurringEndsOn || null, is_active: recurringActive };
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
		recurringOpen = true;
		clearFormMessages();
		recurringId = entry.id;
		recurringType = entry.type;
		recurringCategory = entry.finance_category_id === null ? '' : String(entry.finance_category_id);
		recurringDescription = entry.description;
		recurringAmount = formatDecimalInput(entry.amount, $numberLocale);
		recurringCurrency = entry.currency;
		recurringFrequency = entry.frequency;
		recurringStartsOn = entry.starts_on;
		recurringNextDueOn = entry.next_due_on ?? '';
		recurringEndsOn = entry.ends_on ?? '';
		recurringActive = entry.is_active;
		recurringVisibility = entry.visibility;
	}

	async function remove(path: string, subject: string): Promise<void> {
		clearFormMessages();
		try {
			await api<FinanceResponse>(path, 'DELETE');
			message = deletedMessages[subject as DeleteSubject];
			pendingDelete = null;
			deleteOpen = false;
			await loadFinance();
		} catch (cause) {
			formError = cause instanceof Error ? cause.message : `The ${subject} could not be deleted.`;
		}
	}

	function requestRemove(path: string, subject: DeleteSubject): void {
		pendingDelete = { path, subject };
		deleteOpen = true;
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
	<title>{$t("Finance")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Track private or shared income, spending, recurring entries and monthly budgets.")} />
</svelte:head>

<section class="mx-auto max-w-screen-xl">
	<PageHeading eyebrow="Finance module" title="A clearer view of your money" description="Record income and spending, set a monthly category budget and keep recurring charges ready to record when they are due.">
		{#snippet actions()}
			<div class="{segmented}" role="group" aria-label={$t("Budget month")}>
				<button type="button" class="{segmentedButton}" aria-label={$t("Previous month")} onclick={() => shiftMonth(-1)}><span aria-hidden="true">‹</span></button>
				<label class="sr-only" for="finance-month">{$t("Budget month")}</label><input id="finance-month" type="month" bind:value={month} class="min-h-10 w-40 min-w-0 flex-1 rounded-[10px] border-0 bg-transparent px-2 text-sm text-ink max-sm:text-base" />
				<button type="button" class="{segmentedButton}" aria-label={$t("Next month")} onclick={() => shiftMonth(1)}><span aria-hidden="true">›</span></button>
			</div>
		{/snippet}
	</PageHeading>
	{#if pendingDelete}<ConfirmModal bind:open={deleteOpen} title={$t('Delete record?')} message={$t(deleteMessages[pendingDelete.subject])} onConfirm={() => void remove(pendingDelete!.path, pendingDelete!.subject)} />{/if}

	{#if message}<Notice tone="success" message={message} class="mt-5" />{/if}
	{#if formError}<Notice tone="error" message={formError} class="mt-5" />{/if}
	{#if error}<Notice tone="error" message={error} class="mt-5" />{/if}

	{#if shell.loading || loading}
		<div role="status" class="mt-8 rounded-2xl border border-line bg-surface p-6 text-sm text-ink-secondary">{$t("Loading finance records…")}</div>
	{:else if !canView && !canCreate && !canManageShared}
		<div class="mt-8 rounded-2xl border border-warning/20 bg-warning/5 p-5 text-sm leading-6 text-warning"><p class="font-semibold">{$t("View access is not enabled.")}</p><p class="mt-1 text-warning/75">{$t("Financial records are private. Ask an administrator for Finance View access to browse your transactions and budget overview.")}</p></div>
	{:else}
		{#if !canView}<div class="mt-7 rounded-xl border border-warning/20 bg-warning/5 px-4 py-3 text-sm leading-6 text-warning">{$t("You can add private records, but browsing and budget summaries require an explicit Finance View grant.")}</div>{/if}
		{#if overview}
			<section class="mt-7 grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-label={$t("Monthly totals")}>
				{#each overview.totals as total (total.currency)}
					<article class="rounded-2xl border border-line bg-surface p-5"><p class="text-xs font-medium text-ink-muted">{total.currency} · {month}</p><div class="mt-4 grid grid-cols-3 gap-3"><div><p class="text-xs text-ink-subtle">{$t("Income")}</p><p class="mt-1 text-sm font-semibold text-positive">{formatNumber(total.income, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p></div><div><p class="text-xs text-ink-subtle">{$t("Spending")}</p><p class="mt-1 text-sm font-semibold text-danger">{formatNumber(total.expenses, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p></div><div><p class="text-xs text-ink-subtle">{$t("Net")}</p><p class="mt-1 text-sm font-semibold text-ink">{formatNumber(total.net, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p></div></div></article>
				{:else}
					<article class="rounded-2xl border border-line bg-surface p-5 sm:col-span-2 xl:col-span-3"><p class="text-sm font-medium text-ink-secondary">{$t("No transactions for")} {month}</p><p class="mt-1 text-sm text-ink-muted">{$t("Your totals will appear here once you add income or spending.")}</p></article>
				{/each}
			</section>
		{/if}

		{#if canCreate || canManageShared}
			<section class="mt-7 rounded-2xl border border-line bg-surface p-5 sm:p-7" aria-labelledby="transaction-form-title">
				<div><p class="text-sm font-medium text-accent-ink">{$t(transactionId ? 'Edit record' : 'Quick entry')}</p><h2 id="transaction-form-title" class="mt-1 text-xl font-semibold text-ink">{$t(transactionId ? 'Update a transaction.' : 'Add a transaction.')}</h2></div>
				{#if categories.length === 0}<p class="mt-4 rounded-xl border border-warning/20 bg-warning/5 px-4 py-3 text-sm text-warning">{$t("Create a category below before recording a transaction.")}</p>{/if}
				<button type="button" onclick={() => { resetTransaction(); transactionOpen = true; }} class="mt-4 min-h-10 rounded-lg bg-accent px-4 text-sm font-semibold text-on-accent">{$t('Add transaction')}</button>
				<Modal bind:open={transactionOpen} size="fullscreen" title={$t(transactionId ? 'Edit transaction' : 'Add transaction')}>{#if error}<Notice tone="error" message={error} class="mb-5" />{/if}
				<form id="finance-transaction-form" class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4" onsubmit={saveTransaction}>
					<label class="block text-sm font-medium text-ink-secondary">{$t("Type")}<select bind:value={transactionType} disabled={transactionId !== null && transactionCategory === ''} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 disabled:opacity-50 max-sm:text-base"><option value="expense">{$t("Expense")}</option><option value="income">{$t("Income")}</option></select></label>
					<label class="block text-sm font-medium text-ink-secondary">{$t("Category")}<select bind:value={transactionCategory} required={transactionId === null || transactionCategory !== ''} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base"><option value="">{$t("Choose a category")}</option>{#each typeCategories as category (category.id)}<option value={String(category.id)}>{category.name}{$t(category.visibility === 'household' ? ' · household' : '')}</option>{/each}</select></label>
					<label class="block text-sm font-medium text-ink-secondary">{$t("Amount")}<input bind:value={transactionAmount} type="text" inputmode="decimal" required maxlength="19" placeholder={formatDecimalInput('42.50', $numberLocale)} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink placeholder:text-ink-subtle focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /></label>
					<label class="block text-sm font-medium text-ink-secondary">{$t("Currency")}<input bind:value={transactionCurrency} required minlength="3" maxlength="3" pattern="[A-Za-z]{3}" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /></label>
					<label class="block text-sm font-medium text-ink-secondary sm:col-span-2">{$t("Description")}<input bind:value={transactionDescription} required maxlength="200" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("Groceries")} /></label>
					<label class="block text-sm font-medium text-ink-secondary sm:col-span-2">{$t("Payee")}<input bind:value={transactionPayee} maxlength="200" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /></label>
					<label class="block text-sm font-medium text-ink-secondary">{$t("Date")}<input bind:value={transactionDate} type="date" required class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /></label>
					{#if !transactionId && canManageShared}<label class="block text-sm font-medium text-ink-secondary">{$t("Visibility")}<select bind:value={transactionVisibility} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base"><option value="private">{$t("Private")}</option><option value="household">{$t("Shared household")}</option></select></label>{/if}
					<div class="flex flex-col-reverse gap-3 sm:col-span-2 lg:col-span-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-xs leading-5 text-ink-subtle">{$t("Private transactions stay visible only to their owner unless access is granted for that record.")}</p><div class="flex flex-wrap gap-2">{#if transactionId}<button type="button" class="min-h-11 rounded-xl border border-line-strong px-4 text-sm font-semibold text-ink-secondary hover:border-line-strong" onclick={resetTransaction}>{$t("Cancel")}</button>{/if}</div></div>
				</form>
					{#snippet footer()}
						<Button variant="secondary" type="button" onclick={() => (transactionOpen = false)}>{$t('Cancel')}</Button>
						<Button type="submit" form="finance-transaction-form" disabled={saving || categories.length === 0}>{$t(saving ? 'Saving…' : transactionId ? 'Save changes' : 'Add transaction')}</Button>
					{/snippet}
				</Modal>
			</section>
		{/if}

		<section class="mt-8" aria-labelledby="transactions-title">
			<div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-sm font-medium text-accent-ink">{$t("Activity")}</p><h2 id="transactions-title" class="mt-1 text-2xl font-semibold text-ink">{$t("Transactions")}</h2></div><p class="text-sm text-ink-muted">{$t("Showing up to 200 records for")} {month}.</p></div>
			{#if visibleTransactions.length === 0}<p class="mt-4 rounded-xl border border-line bg-surface p-5 text-sm text-ink-muted">{$t("No transactions are visible for this month.")}</p>{:else}
				<ul class="mt-4 divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface">
					{#each visibleTransactions as transaction (transaction.id)}
						<li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><p class="truncate font-medium text-ink">{transaction.description}</p><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {transaction.type === 'income' ? 'bg-positive/10 text-positive' : 'bg-danger/10 text-danger'}">{$t(transaction.type)}</span><span class="text-xs text-ink-subtle">{$t(transaction.visibility)}</span></div>{#if transaction.payee}<p class="mt-1 text-sm text-ink-secondary">{$t('Payee')}: {transaction.payee}</p>{/if}<p class="mt-1 text-sm text-ink-muted">{transaction.category?.name ?? $t('Uncategorized')} · {formatDate(transaction.occurred_on)}</p></div><div class="flex items-center justify-between gap-3 sm:justify-end"><p class="whitespace-nowrap text-sm font-semibold {transaction.type === 'income' ? 'text-positive' : 'text-ink'}">{$t(transaction.type === 'expense' ? '−' : '+')}{formatCurrency(transaction.amount, transaction.currency)}</p>{#if canMutate(transaction.visibility)}<div class="flex gap-2"><button type="button" class="min-h-10 rounded-lg border border-line-strong px-3 text-xs font-semibold text-ink-secondary hover:border-accent-ink" aria-label={`${$t("Edit")} ${transaction.description}`} onclick={() => editTransaction(transaction)}>{$t("Edit")}</button><button type="button" class="min-h-10 rounded-lg border border-line-strong px-3 text-xs font-semibold text-danger hover:border-danger" aria-label={`${$t("Delete")} ${transaction.description}`} onclick={() => requestRemove(`/api/finance/transactions/${transaction.id}`, 'transaction')}>{$t("Delete")}</button></div>{/if}</div></li>
					{/each}
				</ul>
			{/if}
		</section>

		<div class="mt-8 grid gap-6 xl:grid-cols-2">
			<section class="rounded-2xl border border-line bg-surface p-5 sm:p-6" aria-labelledby="budgets-title">
				<p class="text-sm font-medium text-accent-ink">{$t("Plan")}</p><h2 id="budgets-title" class="mt-1 text-xl font-semibold text-ink">{$t("Monthly budgets")}</h2>
				{#if (canCreate || canManageShared) && expenseCategories.length > 0}
					<button type="button" onclick={() => { resetBudget(); budgetOpen = true; }} class="mt-4 min-h-10 rounded-lg bg-accent px-4 text-sm font-semibold text-on-accent">{$t('Set budget')}</button>
					<Modal bind:open={budgetOpen} title={$t(budgetId ? 'Edit budget' : 'Set monthly budget')}>
					<form class="mt-4 grid gap-3 sm:grid-cols-2" onsubmit={saveBudget}>
						<label class="block text-sm font-medium text-ink-secondary sm:col-span-2">{$t("Expense category")}<select bind:value={budgetCategory} required={budgetId === null} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base"><option value="">{$t("Choose a category")}</option>{#each expenseCategories as category (category.id)}<option value={String(category.id)}>{category.name}</option>{/each}</select></label>
						<label class="block text-sm font-medium text-ink-secondary">{$t("Monthly limit")}<input bind:value={budgetAmount} required inputmode="decimal" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={formatDecimalInput('500.00', $numberLocale)} /></label>
						<label class="block text-sm font-medium text-ink-secondary">{$t("Currency")}<input bind:value={budgetCurrency} required minlength="3" maxlength="3" pattern="[A-Za-z]{3}" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /></label>
						{#if !budgetId && canManageShared}<label class="block text-sm font-medium text-ink-secondary">{$t("Visibility")}<select bind:value={budgetVisibility} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base"><option value="private">{$t("Private")}</option><option value="household">{$t("Shared household")}</option></select></label>{/if}
						<div class="flex flex-wrap gap-2 sm:col-span-2"><button type="submit" disabled={saving} class="min-h-10 rounded-xl bg-accent px-4 text-sm font-semibold text-on-accent disabled:opacity-60">{budgetId ? $t('Save budget') : $t('Set {month} budget', { month })}</button>{#if budgetId}<button type="button" class="min-h-10 rounded-xl border border-line-strong px-4 text-sm text-ink-secondary" onclick={resetBudget}>{$t("Cancel")}</button>{/if}</div>
					</form>
					</Modal>
				{/if}
				{#if overview?.budgets.length}
					<ul class="mt-5 space-y-3">{#each overview.budgets as budget (budget.id)}<li class="rounded-xl border border-line bg-page p-4"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="font-medium text-ink">{budget.category?.name ?? 'Category'} <span class="text-xs font-normal text-ink-subtle">{$t(visibilityLabel(budget.visibility))}</span></p><p class="mt-1 text-sm text-ink-muted">{formatCurrency(budget.spent, budget.currency)} of {formatCurrency(budget.amount, budget.currency)} {$t("· remaining")} {formatCurrency(budget.remaining, budget.currency)}</p></div>{#if canMutate(budget.visibility)}<div class="flex gap-2"><button type="button" class="min-h-9 rounded-lg border border-line-strong px-3 text-xs text-ink-secondary" onclick={() => editBudget(budget)}>{$t("Edit")}</button><button type="button" class="min-h-9 rounded-lg border border-line-strong px-3 text-xs text-danger" onclick={() => requestRemove(`/api/finance/budgets/${budget.id}`, 'budget')}>{$t("Delete")}</button></div>{/if}</div><div class="mt-3 h-2 overflow-hidden rounded-full bg-surface-subtle"><div class="h-full rounded-full bg-accent" style={`width: ${Math.min(100, (Number(budget.spent) / Number(budget.amount)) * 100)}%`}></div></div></li>{/each}</ul>
				{:else}<p class="mt-5 rounded-xl border border-dashed border-line-strong p-4 text-sm leading-6 text-ink-muted">{$t("No budget is set for this month. Add one to compare planned spending with visible expense transactions.")}</p>{/if}
			</section>

			<section class="rounded-2xl border border-line bg-surface p-5 sm:p-6" aria-labelledby="category-title">
				<p class="text-sm font-medium text-accent-ink">{$t("Organize")}</p><h2 id="category-title" class="mt-1 text-xl font-semibold text-ink">{$t("Categories")}</h2>
				{#if canCreate || canManageShared}
					<button type="button" onclick={() => { resetCategory(); categoryOpen = true; }} class="mt-4 min-h-10 rounded-lg bg-accent px-4 text-sm font-semibold text-on-accent">{$t('Add category')}</button>
					<Modal bind:open={categoryOpen} title={$t(categoryId ? 'Edit category' : 'Add category')}>
					<form class="mt-4 grid gap-3 sm:grid-cols-2" onsubmit={saveCategory}>
						<label class="block text-sm font-medium text-ink-secondary sm:col-span-2">{$t("Name")}<input bind:value={categoryName} required maxlength="80" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("Housing")} /></label>
						{#if !categoryId}<label class="block text-sm font-medium text-ink-secondary">{$t("Type")}<select bind:value={categoryType} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base"><option value="expense">{$t("Expense")}</option><option value="income">{$t("Income")}</option></select></label>{#if canManageShared}<label class="block text-sm font-medium text-ink-secondary">{$t("Visibility")}<select bind:value={categoryVisibility} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base"><option value="private">{$t("Private")}</option><option value="household">{$t("Shared household")}</option></select></label>{/if}{/if}
						<div class="flex flex-wrap gap-2 sm:col-span-2"><button type="submit" disabled={saving} class="min-h-10 rounded-xl bg-accent px-4 text-sm font-semibold text-on-accent disabled:opacity-60">{$t(categoryId ? 'Save category' : 'Add category')}</button>{#if categoryId}<button type="button" class="min-h-10 rounded-xl border border-line-strong px-4 text-sm text-ink-secondary" onclick={resetCategory}>{$t("Cancel")}</button>{/if}</div>
					</form>
					</Modal>
				{/if}
				{#if categories.length}<ul class="mt-5 divide-y divide-line">{#each categories as category (category.id)}<li class="flex items-center justify-between gap-3 py-3"><div class="min-w-0"><p class="truncate text-sm font-medium text-ink">{category.name}</p><p class="mt-0.5 text-xs text-ink-subtle">{category.type} · {$t(visibilityLabel(category.visibility))}</p></div>{#if canEditCategory(category)}<div class="flex gap-2"><button type="button" class="min-h-9 rounded-lg border border-line-strong px-3 text-xs text-ink-secondary" onclick={() => editCategory(category)}>{$t("Edit")}</button><button type="button" class="min-h-9 rounded-lg border border-line-strong px-3 text-xs text-danger" onclick={() => requestRemove(`/api/finance/categories/${category.id}`, 'category')}>{$t("Delete")}</button></div>{/if}</li>{/each}</ul>{:else}<p class="mt-4 text-sm text-ink-muted">{$t("No categories are visible yet.")}</p>{/if}
			</section>
		</div>

		<section class="mt-8 rounded-2xl border border-line bg-surface p-5 sm:p-6" aria-labelledby="recurring-title">
			<div><p class="text-sm font-medium text-accent-ink">{$t("On a schedule")}</p><h2 id="recurring-title" class="mt-1 text-xl font-semibold text-ink">{$t("Recurring entries")}</h2><p class="mt-1 text-sm text-ink-muted">{$t("A due occurrence is added to transactions only when you record it.")}</p></div>
			{#if canCreate || canManageShared}
				<button type="button" onclick={() => { resetRecurring(); recurringOpen = true; }} class="mt-4 min-h-10 rounded-lg bg-accent px-4 text-sm font-semibold text-on-accent">{$t('Add schedule')}</button>
				<Modal bind:open={recurringOpen} size="fullscreen" title={$t(recurringId ? 'Edit schedule' : 'Add recurring schedule')}>{#if error}<Notice tone="error" message={error} class="mb-5" />{/if}
				<form id="finance-recurring-form" class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4" onsubmit={saveRecurring}>
					<label class="block text-sm font-medium text-ink-secondary">{$t("Type")}<select bind:value={recurringType} disabled={recurringId !== null && recurringCategory === ''} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink disabled:opacity-50 max-sm:text-base"><option value="expense">{$t("Expense")}</option><option value="income">{$t("Income")}</option></select></label>
					<label class="block text-sm font-medium text-ink-secondary">{$t("Category")}<select bind:value={recurringCategory} required={recurringId === null || recurringCategory !== ''} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base"><option value="">{$t("Choose a category")}</option>{#each recurringCategories as category (category.id)}<option value={String(category.id)}>{category.name}</option>{/each}</select></label>
					<label class="block text-sm font-medium text-ink-secondary">{$t("Amount")}<input bind:value={recurringAmount} required inputmode="decimal" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" placeholder={formatDecimalInput('49.99', $numberLocale)} /></label>
					<label class="block text-sm font-medium text-ink-secondary">{$t("Currency")}<input bind:value={recurringCurrency} required minlength="3" maxlength="3" pattern="[A-Za-z]{3}" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" /></label>
					<label class="block text-sm font-medium text-ink-secondary sm:col-span-2">{$t("Description")}<input bind:value={recurringDescription} required maxlength="200" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" placeholder={$t("Internet plan")} /></label>
					<label class="block text-sm font-medium text-ink-secondary">{$t("Frequency")}<select bind:value={recurringFrequency} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base">{#each financeFrequencies as frequency}<option value={frequency.value}>{$t(frequency.label)}</option>{/each}</select></label>
					<label class="block text-sm font-medium text-ink-secondary">{$t("Starts on")}<input bind:value={recurringStartsOn} type="date" required class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" /></label>
					<label class="block text-sm font-medium text-ink-secondary">{$t("Next due")}<input bind:value={recurringNextDueOn} type="date" required={recurringActive} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" /></label>
					<label class="block text-sm font-medium text-ink-secondary">{$t("Ends on")} <span class="font-normal text-ink-subtle">{$t("(optional)")}</span><input bind:value={recurringEndsOn} type="date" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" /></label>
					{#if recurringId}<label class="flex min-h-11 items-center gap-2 text-sm text-ink-secondary"><input bind:checked={recurringActive} type="checkbox" class="size-4 accent-accent" />{$t("Schedule active")}</label>{:else if canManageShared}<label class="block text-sm font-medium text-ink-secondary">{$t("Visibility")}<select bind:value={recurringVisibility} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base"><option value="private">{$t("Private")}</option><option value="household">{$t("Shared household")}</option></select></label>{/if}
				</form>
					{#snippet footer()}
						<Button variant="secondary" type="button" onclick={() => (recurringOpen = false)}>{$t('Cancel')}</Button>
						<Button type="submit" form="finance-recurring-form" disabled={saving}>{$t(recurringId ? 'Save schedule' : 'Add schedule')}</Button>
					{/snippet}
				</Modal>
			{/if}
			{#if recurringEntries.length}<ul class="mt-5 grid gap-3 md:grid-cols-2">{#each recurringEntries as entry (entry.id)}<li class="rounded-xl border border-line bg-page p-4"><div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><p class="font-medium text-ink">{entry.description}</p><p class="mt-1 text-sm text-ink-muted">{entry.category?.name ?? 'Category'} · {entry.frequency} · {formatCurrency(entry.amount, entry.currency)}</p><p class="mt-1 text-xs text-ink-subtle">{entry.is_active ? `Next due ${entry.next_due_on ?? 'date unavailable'}` : 'Paused or complete'} · {$t(visibilityLabel(entry.visibility))}</p></div>{#if canCreate && (entry.visibility === 'private' || canManageShared) && entry.is_active && entry.next_due_on}<button type="button" class="min-h-10 rounded-lg bg-positive px-3 text-xs font-semibold text-on-accent hover:bg-positive/90" onclick={() => recordOccurrence(entry)}>{$t("Record due entry")}</button>{/if}</div>{#if canMutate(entry.visibility)}<div class="mt-3 flex gap-2 border-t border-line pt-3"><button type="button" class="min-h-9 rounded-lg border border-line-strong px-3 text-xs text-ink-secondary" onclick={() => editRecurring(entry)}>{$t("Edit")}</button><button type="button" class="min-h-9 rounded-lg border border-line-strong px-3 text-xs text-danger" onclick={() => requestRemove(`/api/finance/recurring/${entry.id}`, 'recurring entry')}>{$t("Delete")}</button></div>{/if}</li>{/each}</ul>{:else}<p class="mt-5 text-sm text-ink-muted">{$t("No recurring entries are visible.")}</p>{/if}
		</section>
	{/if}
</section>
