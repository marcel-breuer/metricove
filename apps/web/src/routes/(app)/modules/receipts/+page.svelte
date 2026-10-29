<script lang="ts">
	import { goto } from '$app/navigation';
	import { onDestroy } from 'svelte';
	import { getContext } from 'svelte';
	import { get } from 'svelte/store';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { defaultCurrency, formatDate, t } from '#lib/i18n';
	import { formatCurrency } from '#lib/number-format';
	import { vehicleCostCategories } from '#lib/vehicles';
	import type { ReceiptDocument, ReceiptOptions, ReceiptPagination, ReceiptPreview, ReceiptResponse } from '#lib/receipts';

	type TargetType = 'finance' | 'vehicle';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'receipts'));
	const canView = $derived(access?.capabilities.view === true);
	const canCreate = $derived(access?.capabilities.create === true);
	const canEdit = $derived(access?.capabilities.edit === true);
	const canManageSharing = $derived(access?.capabilities.manage === true);

	let options = $state<ReceiptOptions>({
		finance_available: false,
		finance_categories: [],
		vehicles_available: false,
		vehicles: []
	});
	let receipts = $state<ReceiptDocument[]>([]);
	let currencies = $state<string[]>([]);
	let pagination = $state<ReceiptPagination>({ current_page: 1, last_page: 1, per_page: 30, total: 0 });
	let receiptPage = $state(1);
	let receiptSearch = $state('');
	let dateFrom = $state('');
	let dateTo = $state('');
	let minAmount = $state('');
	let maxAmount = $state('');
	let currencyFilter = $state('');
	let selectedFile = $state<File | null>(null);
	let fileInput = $state<HTMLInputElement>();
	let filePreviewUrl = $state('');
	let preview = $state<ReceiptPreview | null>(null);
	let targetType = $state<TargetType>('finance');
	let financeCategoryId = $state('');
	let vehicleId = $state('');
	let vehicleCategory = $state('fuel');
	let description = $state('');
	let merchant = $state('');
	let invoiceNumber = $state('');
	let invoiceDate = $state('');
	let amount = $state('');
	let currency = $state($defaultCurrency);
	let taxAmount = $state('');
	let loading = $state(true);
	let previewing = $state(false);
	let confirming = $state(false);
	let deletingId = $state<number | null>(null);
	let sharingId = $state<number | null>(null);
	let error = $state('');
	let statusMessage = $state('');
	let receiptError = $state('');
	let idempotencyKey = $state('');

	const availableTargetOptions = $derived([
		...(options.finance_available ? [{ value: 'finance' as const, label: 'Finance expense' }] : []),
		...(options.vehicles_available ? [{ value: 'vehicle' as const, label: 'Vehicle cost' }] : [])
	]);
	const expenseCategories = $derived(options.finance_categories.filter((category) => category.type === 'expense'));
	const hasReceiptFilters = $derived(Boolean(receiptSearch.trim() || dateFrom || dateTo || minAmount || maxAmount || currencyFilter));
	const currentReceiptTarget = (receipt: ReceiptDocument): string => {
		if (receipt.target_type === 'finance') return 'Finance expense';
		if (receipt.target_type === 'vehicle') return 'Vehicle cost';
		return receipt.target_linked ? 'Target entry removed' : 'Target entry unavailable';
	};

	$effect(() => {
		if (!shell.loading) void loadReceipts();
	});

	onDestroy(() => {
		if (filePreviewUrl) URL.revokeObjectURL(filePreviewUrl);
	});

	function clearMessages(): void {
		error = '';
		statusMessage = '';
	}

	function makeIdempotencyKey(): string {
		if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
		const bytes = crypto.getRandomValues(new Uint8Array(16));
		bytes[6] = (bytes[6] & 0x0f) | 0x40;
		bytes[8] = (bytes[8] & 0x3f) | 0x80;
		const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
		return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
	}

	async function request<T extends ReceiptResponse>(path: string, method = 'GET', body?: FormData | string, idempotency?: string): Promise<T> {
		const headers: Record<string, string> = { Accept: 'application/json' };
		if (method !== 'GET') {
			const token = csrfToken();
			if (!token) throw new Error('Reload this page and try again.');
			headers['X-XSRF-TOKEN'] = token;
		}
		if (typeof body === 'string') headers['Content-Type'] = 'application/json';
		if (idempotency) headers['Idempotency-Key'] = idempotency;
		const response = await fetch(path, {
			method,
			credentials: 'same-origin',
			headers,
			...(body ? { body } : {})
		});
		if (response.status === 401) {
			await goto('/login');
			throw new Error('Your session has expired.');
		}
		const result = (await response.json()) as T & ApiResponse;
		if (!response.ok) throw new Error(firstError(result) || result.message || 'The receipt request could not be completed.');
		return result as T;
	}

	async function loadReceipts(): Promise<void> {
		loading = true;
		error = '';
		try {
			const tasks: Array<Promise<unknown>> = [];
			if (canView) {
				const query = new URLSearchParams({ page: String(receiptPage) });
				if (receiptSearch.trim()) query.set('search', receiptSearch.trim());
				if (dateFrom) query.set('date_from', dateFrom);
				if (dateTo) query.set('date_to', dateTo);
				if (minAmount) query.set('min_amount', minAmount);
				if (maxAmount) query.set('max_amount', maxAmount);
				if (currencyFilter) query.set('currency', currencyFilter);
				tasks.push(request<ReceiptResponse>('/api/receipts?' + query.toString()).then((result) => {
					receipts = result.receipts ?? [];
					currencies = result.currencies ?? [];
					pagination = result.pagination ?? { current_page: 1, last_page: 1, per_page: 30, total: receipts.length };
				}));
			} else {
				receipts = [];
			}
			if (canCreate) {
				tasks.push(request<ReceiptOptions & ReceiptResponse>('/api/receipts/options').then((result) => {
					options = {
						finance_available: result.finance_available === true,
						finance_categories: result.finance_categories ?? [],
						vehicles_available: result.vehicles_available === true,
						vehicles: result.vehicles ?? []
					};
					if (!availableTargetOptions.some((option) => option.value === targetType)) {
						targetType = availableTargetOptions[0]?.value ?? 'finance';
					}
					if (!financeCategoryId && expenseCategories[0]) financeCategoryId = String(expenseCategories[0].id);
					if (!vehicleId && options.vehicles[0]) vehicleId = String(options.vehicles[0].id);
				}));
			}
			await Promise.all(tasks);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Receipts could not be loaded.';
		} finally {
			loading = false;
		}
	}

	function applyReceiptFilters(event: SubmitEvent): void {
		event.preventDefault();
		clearMessages();
		if ((minAmount || maxAmount) && !currencyFilter) {
			error = 'Choose a currency to filter by amount.';
			return;
		}
		receiptPage = 1;
		void loadReceipts();
	}

	function clearReceiptFilters(): void {
		receiptSearch = '';
		dateFrom = '';
		dateTo = '';
		minAmount = '';
		maxAmount = '';
		currencyFilter = '';
		receiptPage = 1;
		void loadReceipts();
	}

	function changeReceiptPage(page: number): void {
		if (page < 1 || page > pagination.last_page || page === receiptPage) return;
		receiptPage = page;
		void loadReceipts();
	}

	function chooseFile(event: Event): void {
		clearMessages();
		const input = event.currentTarget as HTMLInputElement;
		const file = input.files?.[0] ?? null;
		if (filePreviewUrl) URL.revokeObjectURL(filePreviewUrl);
		filePreviewUrl = '';
		selectedFile = file;
		preview = null;
		idempotencyKey = '';
		if (file) filePreviewUrl = URL.createObjectURL(file);
	}

	async function previewFile(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		clearMessages();
		preview = null;
		if (!canCreate || !selectedFile) {
			error = 'Choose a PDF, JPEG or PNG invoice first.';
			return;
		}
		if (selectedFile.size > 10 * 1024 * 1024) {
			error = 'The invoice must be 10 MB or smaller.';
			return;
		}
		const form = new FormData();
		form.set('file', selectedFile);
		previewing = true;
		try {
			const result = await request<ReceiptPreview & ReceiptResponse>('/api/receipts/preview', 'POST', form);
			preview = result;
			merchant = result.suggestions?.merchant ?? '';
			invoiceNumber = result.suggestions?.invoice_number ?? '';
			invoiceDate = result.suggestions?.invoice_date ?? '';
			amount = result.suggestions?.amount ?? '';
			currency = result.suggestions?.currency ?? result.default_currency ?? $defaultCurrency;
			taxAmount = result.suggestions?.tax_amount ?? '';
			description = merchant ? get(t)('Invoice from') + ' ' + merchant : get(t)('Invoice expense');
			idempotencyKey = makeIdempotencyKey();
			if (expenseCategories.length > 0) financeCategoryId = String(expenseCategories[0].id);
			if (options.vehicles.length > 0) vehicleId = String(options.vehicles[0].id);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The invoice could not be previewed.';
		} finally {
			previewing = false;
		}
	}

	async function confirmReceipt(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		clearMessages();
		if (!selectedFile || !preview || !idempotencyKey) {
			error = 'Preview the invoice again before saving it.';
			return;
		}
		if (availableTargetOptions.length === 0) {
			error = 'Enable Finance or Vehicles and grant the required access before creating an entry.';
			return;
		}

		const form = new FormData();
		form.set('file', selectedFile);
		form.set('preview_token', preview.preview_token);
		form.set('confirmed', '1');
		form.set('target_type', targetType);
		form.set('description', description);
		form.set('merchant', merchant);
		form.set('invoice_number', invoiceNumber);
		form.set('invoice_date', invoiceDate);
		form.set('amount', amount);
		form.set('currency', currency);
		form.set('tax_amount', taxAmount);
		if (targetType === 'finance') form.set('finance_category_id', financeCategoryId);
		if (targetType === 'vehicle') {
			form.set('vehicle_id', vehicleId);
			form.set('vehicle_category', vehicleCategory);
		}
		confirming = true;
		try {
			const result = await request<ReceiptResponse>('/api/receipts/confirm', 'POST', form, idempotencyKey);
			statusMessage = result.message ?? 'Receipt saved.';
			preview = null;
			selectedFile = null;
			if (filePreviewUrl) URL.revokeObjectURL(filePreviewUrl);
			filePreviewUrl = '';
			if (fileInput) fileInput.value = '';
			idempotencyKey = '';
			await loadReceipts();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The receipt could not be saved.';
		} finally {
			confirming = false;
		}
	}

	async function deleteReceipt(receipt: ReceiptDocument): Promise<void> {
		if (!canEdit || !receipt.is_owner || !window.confirm(get(t)('Delete this private receipt and its file?'))) return;
		clearMessages();
		receiptError = '';
		deletingId = receipt.id;
		try {
			await request<ReceiptResponse>('/api/receipts/' + receipt.id, 'DELETE');
			receipts = receipts.filter((candidate) => candidate.id !== receipt.id);
			statusMessage = 'Receipt deleted.';
		} catch (cause) {
			receiptError = cause instanceof Error ? cause.message : 'The receipt could not be deleted.';
		} finally {
			deletingId = null;
		}
	}

	async function updateReceiptSharing(receipt: ReceiptDocument): Promise<void> {
		if (!canManageSharing || !receipt.is_owner) return;
		const shared = !receipt.shared_with_household;
		const message = shared
			? get(t)('Share the original receipt and its details with household members who can view Receipts?')
			: get(t)('Stop sharing this receipt with the household?');
		if (!window.confirm(message)) return;

		clearMessages();
		receiptError = '';
		sharingId = receipt.id;
		try {
			const result = await request<ReceiptResponse>(`/api/receipts/${receipt.id}/sharing`, 'PATCH', JSON.stringify({ shared_with_household: shared }));
			if (result.receipt) {
				const updated = result.receipt;
				receipts = receipts.map((candidate) => candidate.id === receipt.id ? updated : candidate);
			}
			statusMessage = shared ? 'Receipt shared with the household.' : 'Receipt is private again.';
		} catch (cause) {
			receiptError = cause instanceof Error ? cause.message : 'Receipt sharing could not be updated.';
		} finally {
			sharingId = null;
		}
	}
</script>

<svelte:head>
	<title>{$t('Receipts')} — Metricove</title>
</svelte:head>

<section class="mx-auto max-w-6xl space-y-6">
	<header class="rounded-3xl border border-slate-800 bg-slate-900/70 p-6 sm:p-8">
		<p class="text-sm font-medium tracking-wide text-cyan-200">{$t('Private documents')}</p>
		<h1 class="mt-2 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{$t('Receipts')}</h1>
		<p class="mt-3 max-w-3xl text-sm leading-6 text-slate-400">{$t('Upload a receipt or invoice, review the extracted details, then confirm to create a matching entry.')}</p>
	</header>

	{#if error}<p role="alert" class="rounded-xl border border-rose-300/30 bg-rose-400/10 p-4 text-sm text-rose-100">{error}</p>{/if}
	{#if statusMessage}<p role="status" class="rounded-xl border border-emerald-300/30 bg-emerald-400/10 p-4 text-sm text-emerald-100">{statusMessage}</p>{/if}

	{#if canCreate}
		<section class="rounded-2xl border border-slate-800 bg-slate-900/50 p-5 sm:p-6" aria-labelledby="receipt-upload-heading">
			<div class="mb-5">
				<h2 id="receipt-upload-heading" class="text-xl font-semibold text-slate-100">{$t('Scan a new receipt')}</h2>
				<p class="mt-1 text-sm leading-6 text-slate-400">{$t('Local OCR suggests invoice fields. Nothing is saved until you confirm the preview.')}</p>
			</div>
			<form class="space-y-5" onsubmit={previewFile}>
				<label class="block text-sm font-medium text-slate-200">
					{$t('Invoice file')}
					<input bind:this={fileInput} type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" onchange={chooseFile} class="mt-2 min-h-12 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200 file:mr-3 file:min-h-8 file:rounded-lg file:border-0 file:bg-cyan-300 file:px-3 file:text-xs file:font-semibold file:text-slate-950" />
					<span class="mt-1 block text-xs font-normal text-slate-500">{$t('PDF, JPEG or PNG up to 10 MB; PDFs are limited to 5 pages.')}</span>
				</label>
				{#if filePreviewUrl && selectedFile}
					<div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
						{#if selectedFile.type === 'application/pdf'}
							<iframe title={$t('Invoice preview')} src={filePreviewUrl} class="h-[28rem] w-full rounded-lg bg-white"></iframe>
						{:else}
							<img src={filePreviewUrl} alt={$t('Uploaded invoice preview')} class="max-h-[28rem] w-full rounded-lg object-contain" />
						{/if}
					</div>
				{/if}
				<button type="submit" disabled={previewing || !selectedFile} class="min-h-11 rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-wait disabled:opacity-60">
					{$t(previewing ? 'Reading invoice locally…' : 'Preview invoice')}
				</button>
			</form>

			{#if preview}
				<form class="mt-6 space-y-5 border-t border-slate-800 pt-6" onsubmit={confirmReceipt} aria-labelledby="receipt-review-heading">
					<div>
						<h3 id="receipt-review-heading" class="text-lg font-semibold text-slate-100">{$t('Review invoice details')}</h3>
						<p class="mt-1 text-sm leading-6 text-slate-400">{$t('Check every suggestion. The original file stays private and is stored only after confirmation.')}</p>
					</div>
					<div class="grid gap-4 sm:grid-cols-2">
						<label class="block text-sm font-medium text-slate-200">{$t('Entry type')}
							<select bind:value={targetType} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white">
								{#each availableTargetOptions as option}
									<option value={option.value}>{$t(option.label)}</option>
								{/each}
							</select>
						</label>
						{#if targetType === 'finance'}
							<label class="block text-sm font-medium text-slate-200">{$t('Finance category')}
								<select bind:value={financeCategoryId} required class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white">
									{#each expenseCategories as category (category.id)}
										<option value={category.id}>{category.name}</option>
									{/each}
								</select>
							</label>
						{:else}
							<label class="block text-sm font-medium text-slate-200">{$t('Vehicle')}
								<select bind:value={vehicleId} required class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white">
									{#each options.vehicles as vehicle (vehicle.id)}
										<option value={vehicle.id}>{vehicle.name} · {vehicle.make} {vehicle.model}</option>
									{/each}
								</select>
							</label>
							<label class="block text-sm font-medium text-slate-200">{$t('Vehicle cost category')}
								<select bind:value={vehicleCategory} required class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white">
									{#each vehicleCostCategories as category (category.value)}
										<option value={category.value}>{$t(category.label)}</option>
									{/each}
								</select>
							</label>
						{/if}
						<label class="block text-sm font-medium text-slate-200">{$t('Description')}
							<input bind:value={description} required maxlength="200" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" />
						</label>
						<label class="block text-sm font-medium text-slate-200">{$t('Merchant')}
							<input bind:value={merchant} maxlength="200" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" />
						</label>
						<label class="block text-sm font-medium text-slate-200">{$t('Invoice number')} <span class="font-normal text-slate-500">{$t('Optional')}</span>
							<input bind:value={invoiceNumber} maxlength="100" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" />
						</label>
						<label class="block text-sm font-medium text-slate-200">{$t('Invoice date')}
							<input bind:value={invoiceDate} type="date" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" />
						</label>
						<label class="block text-sm font-medium text-slate-200">{$t('Total amount')}
							<input bind:value={amount} required inputmode="decimal" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" placeholder="125.00" />
						</label>
						<label class="block text-sm font-medium text-slate-200">{$t('Currency')}
							<input bind:value={currency} required maxlength="3" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm uppercase text-white" placeholder="EUR" />
						</label>
						<label class="block text-sm font-medium text-slate-200">{$t('Tax amount')} <span class="font-normal text-slate-500">{$t('Optional')}</span>
							<input bind:value={taxAmount} inputmode="decimal" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" placeholder="19.00" />
						</label>
					</div>
					{#if availableTargetOptions.length === 0}
						<p role="alert" class="rounded-xl border border-amber-300/30 bg-amber-400/10 p-4 text-sm text-amber-100">{$t('Enable Finance or Vehicles and grant the required access before creating an entry.')}</p>
					{/if}
					<div class="flex flex-col gap-3 border-t border-slate-800 pt-5 sm:flex-row sm:items-center sm:justify-between">
						<p class="text-xs leading-5 text-slate-500">{$t('OCR runs locally. Nothing is sent to an external provider.')}</p>
						<button type="submit" disabled={confirming || availableTargetOptions.length === 0} class="min-h-11 rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-wait disabled:opacity-60">
							{$t(confirming ? 'Saving receipt…' : 'Confirm and create entry')}
						</button>
					</div>
				</form>
			{/if}
		</section>
	{:else}
		<section class="rounded-2xl border border-amber-300/20 bg-amber-300/5 p-5" aria-labelledby="receipt-access-heading">
			<h2 id="receipt-access-heading" class="font-semibold text-amber-100">{$t('Receipt creation access is required.')}</h2>
			<p class="mt-2 text-sm leading-6 text-amber-100/75">{$t('Ask a household administrator to enable Receipts and grant your account Create access.')}</p>
		</section>
	{/if}

	{#if canView}
		<section class="rounded-2xl border border-slate-800 bg-slate-900/50 p-5 sm:p-6" aria-labelledby="saved-receipts-heading">
			<div class="flex flex-wrap items-end justify-between gap-3">
				<div>
					<h2 id="saved-receipts-heading" class="text-xl font-semibold text-slate-100">{$t('Receipt archive')}</h2>
					<p class="mt-1 text-sm text-slate-400">{$t('Private and household-shared receipts you can view are listed here.')}</p>
				</div>
				{#if loading}<span class="text-sm text-slate-400">{$t('Loading…')}</span>{/if}
			</div>
			{#if receiptError}<p role="alert" class="mt-4 rounded-xl border border-rose-300/30 bg-rose-400/10 p-4 text-sm text-rose-100">{receiptError}</p>{/if}
			<form class="mt-5 grid gap-3 rounded-xl border border-slate-800 bg-slate-950/50 p-4 sm:grid-cols-2 lg:grid-cols-4" onsubmit={applyReceiptFilters} aria-label={$t('Filter receipts')}>
				<label class="block text-sm font-medium text-slate-200 sm:col-span-2">{$t('Search merchant or invoice number')}
					<input bind:value={receiptSearch} type="search" maxlength="100" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 text-sm text-white" />
				</label>
				<label class="block text-sm font-medium text-slate-200">{$t('Invoice date from')}
					<input bind:value={dateFrom} type="date" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 text-sm text-white" />
				</label>
				<label class="block text-sm font-medium text-slate-200">{$t('Invoice date to')}
					<input bind:value={dateTo} type="date" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 text-sm text-white" />
				</label>
				<label class="block text-sm font-medium text-slate-200">{$t('Minimum amount')}
					<input bind:value={minAmount} type="number" min="0" step="0.01" inputmode="decimal" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 text-sm text-white" />
				</label>
				<label class="block text-sm font-medium text-slate-200">{$t('Maximum amount')}
					<input bind:value={maxAmount} type="number" min="0" step="0.01" inputmode="decimal" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 text-sm text-white" />
				</label>
				<label class="block text-sm font-medium text-slate-200">{$t('Currency')}
					<select bind:value={currencyFilter} required={Boolean(minAmount || maxAmount)} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 text-sm text-white">
						<option value="">{$t('All currencies')}</option>
						{#each currencies as receiptCurrency (receiptCurrency)}
							<option value={receiptCurrency}>{receiptCurrency}</option>
						{/each}
					</select>
				</label>
				<div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4">
					<button type="submit" disabled={loading} class="min-h-10 rounded-lg bg-cyan-300 px-4 text-sm font-semibold text-slate-950 disabled:opacity-60">{$t('Apply filters')}</button>
					<button type="button" onclick={clearReceiptFilters} disabled={loading} class="min-h-10 rounded-lg border border-slate-700 px-4 text-sm font-medium text-slate-200 disabled:opacity-60">{$t('Clear filters')}</button>
					<span class="ml-auto text-sm text-slate-400">{$t('Receipts found')}: {pagination.total}</span>
				</div>
			</form>
			<ol class="mt-4 space-y-3">
				{#each receipts as receipt (receipt.id)}
					<li class="flex flex-col gap-3 rounded-xl border border-slate-800 bg-slate-950/60 p-4 sm:flex-row sm:items-center sm:justify-between">
						<div class="min-w-0">
							<p class="truncate font-semibold text-slate-100">{receipt.merchant ?? $t('Invoice')}</p>
							<p class="mt-1 text-sm text-slate-400">{formatDate(receipt.invoice_date)} · {formatCurrency(receipt.amount, receipt.currency)} · {$t(currentReceiptTarget(receipt))}</p>
							{#if receipt.invoice_number}<p class="mt-1 text-xs text-slate-500">{$t('Invoice number')}: {receipt.invoice_number}</p>{/if}
							<span class="mt-2 inline-flex rounded-full border px-2.5 py-1 text-xs font-medium {receipt.shared_with_household ? 'border-emerald-300/30 bg-emerald-300/10 text-emerald-100' : 'border-slate-700 text-slate-300'}">{$t(receipt.shared_with_household ? 'Shared with household' : 'Private')}</span>
						</div>
						<div class="flex shrink-0 flex-wrap gap-2">
							<a href={'/api/receipts/' + receipt.id + '/preview'} target="_blank" rel="noopener noreferrer" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-700 px-3 text-sm font-medium text-cyan-100 hover:bg-slate-800">{$t('Preview document')}</a>
							<a href={receipt.file_url} download class="inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-700 px-3 text-sm font-medium text-cyan-100 hover:bg-slate-800">{$t('Download original')}</a>
							{#if canManageSharing && receipt.is_owner}
								<button type="button" onclick={() => updateReceiptSharing(receipt)} disabled={sharingId === receipt.id} class="min-h-10 rounded-lg border border-emerald-300/30 px-3 text-sm font-medium text-emerald-100 hover:bg-emerald-300/10 disabled:opacity-50">{$t(sharingId === receipt.id ? 'Updating sharing…' : receipt.shared_with_household ? 'Stop sharing' : 'Share with household')}</button>
							{/if}
							{#if canEdit && receipt.is_owner}
								<button type="button" onclick={() => deleteReceipt(receipt)} disabled={deletingId === receipt.id} class="min-h-10 rounded-lg border border-rose-300/30 px-3 text-sm font-medium text-rose-100 hover:bg-rose-300/10 disabled:opacity-50">{$t(deletingId === receipt.id ? 'Deleting…' : 'Delete receipt')}</button>
							{/if}
						</div>
					</li>
				{:else}
					<li class="rounded-xl border border-slate-800 bg-slate-950/40 p-5 text-sm text-slate-400">{$t(loading ? 'Loading receipts…' : hasReceiptFilters ? 'No receipts match those filters.' : 'No receipts have been saved yet.')}</li>
				{/each}
			</ol>
			{#if pagination.last_page > 1}
				<div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-slate-800 pt-4">
					<p class="text-sm text-slate-400">{$t('Page')} {pagination.current_page} {$t('of')} {pagination.last_page}</p>
					<div class="flex gap-2">
						<button type="button" onclick={() => changeReceiptPage(receiptPage - 1)} disabled={loading || receiptPage <= 1} class="min-h-10 rounded-lg border border-slate-700 px-3 text-sm text-slate-200 disabled:opacity-50">{$t('Previous')}</button>
						<button type="button" onclick={() => changeReceiptPage(receiptPage + 1)} disabled={loading || receiptPage >= pagination.last_page} class="min-h-10 rounded-lg border border-slate-700 px-3 text-sm text-slate-200 disabled:opacity-50">{$t('Next')}</button>
					</div>
				</div>
			{/if}
		</section>
	{/if}
</section>
