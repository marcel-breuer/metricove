<script lang="ts">
	import { goto } from '$app/navigation';
	import { onDestroy } from 'svelte';
	import { getContext } from 'svelte';
	import { get } from 'svelte/store';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { defaultCurrency, formatDate, t } from '#lib/i18n';
	import { formatCurrency } from '#lib/number-format';
	import { createReceiptPdf, receiptFileSizeLimit, receiptPageLimit } from '#lib/receipt-files';
	import { vehicleCostCategories } from '#lib/vehicles';
	import type { ReceiptDocument, ReceiptDuplicateMatch, ReceiptFieldStatus, ReceiptOptions, ReceiptPagination, ReceiptPreview, ReceiptResponse, ReceiptSuggestions } from '#lib/receipts';

	type TargetType = 'archive' | 'finance' | 'vehicle';
	type ReceiptField = keyof ReceiptSuggestions;

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
	let categoryFilters = $state<Array<{ value: string; label: string }>>([]);
	let pagination = $state<ReceiptPagination>({ current_page: 1, last_page: 1, per_page: 30, total: 0 });
	let receiptPage = $state(1);
	let receiptSearch = $state('');
	let dateFrom = $state('');
	let dateTo = $state('');
	let minAmount = $state('');
	let maxAmount = $state('');
	let currencyFilter = $state('');
	let targetFilter = $state('');
	let categoryFilter = $state('');
	let sharingStatusFilter = $state('');
	let selectedFile = $state<File | null>(null);
	let pdfInput = $state<HTMLInputElement>();
	let cameraInput = $state<HTMLInputElement>();
	let photosInput = $state<HTMLInputElement>();
	let photoPages = $state<File[]>([]);
	let preparingScan = $state(false);
	let filePreviewUrl = $state('');
	let preview = $state<ReceiptPreview | null>(null);
	let fieldStatus = $state<Record<string, ReceiptFieldStatus>>({});
	let reviewRequired = $state<ReceiptField[]>([]);
	let reviewedFields = $state<ReceiptField[]>([]);
	let duplicateMatches = $state<ReceiptDuplicateMatch[]>([]);
	let duplicateAcknowledged = $state(false);
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
	let fuelLiters = $state('');
	let fuelUnitPrice = $state('');
	let fuelOdometerValue = $state('');
	let loading = $state(true);
	let previewing = $state(false);
	let confirming = $state(false);
	let deletingId = $state<number | null>(null);
	let sharingId = $state<number | null>(null);
	let error = $state('');
	let statusMessage = $state('');
	let receiptError = $state('');
	let idempotencyKey = $state('');
	let targetLinkReceiptId = $state<number | null>(null);
	let targetActionMode = $state<'link' | 'create' | null>(null);
	let linkTargetType = $state<'finance' | 'vehicle'>('finance');
	let linkSearch = $state('');
	let targetMatches = $state<Array<{ id: number; label: string; date: string; amount: string; currency: string }>>([]);
	let targetCreateCategories = $state<ReceiptOptions['finance_categories']>([]);
	let targetCreateVehicles = $state<ReceiptOptions['vehicles']>([]);
	let targetRecordId = $state('');
	let targetFinanceCategoryId = $state('');
	let targetVehicleId = $state('');
	let targetVehicleCategory = $state('fuel');
	let targetOptionsLoading = $state(false);
	let targetActionBusy = $state(false);

	const availableTargetOptions = $derived([
		{ value: 'archive' as const, label: 'Save to archive only' },
		...(options.finance_available ? [{ value: 'finance' as const, label: 'Finance expense' }] : []),
		...(options.vehicles_available ? [{ value: 'vehicle' as const, label: 'Vehicle cost' }] : [])
	]);
	const expenseCategories = $derived(options.finance_categories.filter((category) => category.type === 'expense'));
	const hasReceiptFilters = $derived(Boolean(receiptSearch.trim() || dateFrom || dateTo || minAmount || maxAmount || currencyFilter || targetFilter || categoryFilter || sharingStatusFilter));
	const showFuelFields = true;
	const canConfirm = $derived(!confirming && !preparingScan && reviewRequired.every((field) => reviewedFields.includes(field)) && (duplicateMatches.length === 0 || duplicateAcknowledged));
	const fuelCalculatedTotal = $derived.by(() => {
		const liters = Number(fuelLiters);
		const unitPrice = Number(fuelUnitPrice);
		return fuelLiters.trim() !== '' && fuelUnitPrice.trim() !== '' && Number.isFinite(liters) && Number.isFinite(unitPrice)
			? liters * unitPrice
			: null;
	});
	const currentReceiptTarget = (receipt: ReceiptDocument): string => {
		if (receipt.target_type === 'finance') return 'Finance expense';
		if (receipt.target_type === 'vehicle') return 'Vehicle cost';
		return receipt.target_linked ? 'Target entry unavailable' : 'Archived only';
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
				if (targetFilter) query.set('target_type', targetFilter);
				if (categoryFilter) query.set('category', categoryFilter);
				if (sharingStatusFilter) query.set('sharing_status', sharingStatusFilter);
				tasks.push(request<ReceiptResponse>('/api/receipts?' + query.toString()).then((result) => {
					receipts = result.receipts ?? [];
					currencies = result.currencies ?? [];
					categoryFilters = result.category_filters ?? [];
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
		targetFilter = '';
		categoryFilter = '';
		sharingStatusFilter = '';
		receiptPage = 1;
		void loadReceipts();
	}

	function changeReceiptPage(page: number): void {
		if (page < 1 || page > pagination.last_page || page === receiptPage) return;
		receiptPage = page;
		void loadReceipts();
	}

	function setSelectedFile(file: File | null): void {
		clearMessages();
		if (filePreviewUrl) URL.revokeObjectURL(filePreviewUrl);
		filePreviewUrl = '';
		selectedFile = file;
		preview = null;
		fieldStatus = {};
		reviewRequired = [];
		reviewedFields = [];
		duplicateMatches = [];
		duplicateAcknowledged = false;
		idempotencyKey = '';
		if (file) filePreviewUrl = URL.createObjectURL(file);
	}

	function needsReview(field: string): boolean {
		return reviewRequired.includes(field as ReceiptField);
	}

	function isFieldReviewed(field: string): boolean {
		return reviewedFields.includes(field as ReceiptField);
	}

	function setFieldReview(field: string, event: Event): void {
		const checked = (event.currentTarget as HTMLInputElement).checked;
		reviewedFields = checked
			? [...new Set([...reviewedFields, field as ReceiptField])]
			: reviewedFields.filter((candidate) => candidate !== field);
	}

	function clearFieldReview(field: ReceiptField): void {
		if (reviewedFields.includes(field)) {
			reviewedFields = reviewedFields.filter((candidate) => candidate !== field);
		}
	}

	function fieldReviewPrompt(value: string): string {
		return value.trim() === '' ? 'I checked that this value is absent' : 'I checked or corrected this value';
	}

	function fieldStatusLabel(field: string): string {
		return fieldStatus[field] === 'missing' ? 'Not found in OCR' : 'Check this OCR suggestion';
	}

	function categoryFilterLabel(option: { value: string; label: string }): string {
		const [type, category] = option.value.split(':', 2);
		if (type === 'finance') {
			return get(t)('Finance') + ' · ' + (option.label.split(' · ').slice(1).join(' · ') || category);
		}
		const labels: Record<string, string> = {
			fuel: 'Fuel / charging',
			maintenance: 'Maintenance',
			insurance: 'Insurance',
			tax: 'Tax',
			registration: 'Registration',
			finance: 'Vehicle financing',
			other: 'Other'
		};
		return get(t)('Vehicle') + ' · ' + get(t)(labels[category] ?? category ?? 'Other');
	}

	async function openTargetAction(receipt: ReceiptDocument, mode: 'link' | 'create', type: 'finance' | 'vehicle'): Promise<void> {
		clearMessages();
		receiptError = '';
		targetLinkReceiptId = receipt.id;
		targetActionMode = mode;
		linkTargetType = type;
		linkSearch = '';
		targetRecordId = '';
		targetFinanceCategoryId = '';
		targetVehicleId = '';
		targetVehicleCategory = receipt.fuel_liters !== null ? 'fuel' : 'other';
		await loadTargetOptions();
	}

	async function loadTargetOptions(): Promise<void> {
		if (targetLinkReceiptId === null) return;
		targetOptionsLoading = true;
		try {
			const query = new URLSearchParams({ target_type: linkTargetType });
			if (linkSearch.trim()) query.set('search', linkSearch.trim());
			const result = await request<ReceiptResponse>('/api/receipts/targets?' + query.toString());
			targetMatches = result.targets ?? [];
			targetCreateCategories = result.create_categories ?? [];
			targetCreateVehicles = result.create_vehicles ?? [];
			if (!targetRecordId && targetMatches[0]) targetRecordId = String(targetMatches[0].id);
			if (!targetFinanceCategoryId && targetCreateCategories[0]) targetFinanceCategoryId = String(targetCreateCategories[0].id);
			if (!targetVehicleId && targetCreateVehicles[0]) targetVehicleId = String(targetCreateVehicles[0].id);
		} catch (cause) {
			receiptError = cause instanceof Error ? cause.message : 'Receipt targets could not be loaded.';
		} finally {
			targetOptionsLoading = false;
		}
	}

	async function saveReceiptTarget(): Promise<void> {
		if (targetLinkReceiptId === null || !targetRecordId) return;
		targetActionBusy = true;
		receiptError = '';
		try {
			const result = await request<ReceiptResponse>(`/api/receipts/${targetLinkReceiptId}/target`, 'PATCH', JSON.stringify({ target_type: linkTargetType, target_record_id: Number(targetRecordId) }));
			if (result.receipt) receipts = receipts.map((receipt) => receipt.id === result.receipt?.id ? result.receipt : receipt);
			statusMessage = get(t)('Receipt linked to the selected entry.');
			targetLinkReceiptId = null;
			targetActionMode = null;
		} catch (cause) {
			receiptError = cause instanceof Error ? cause.message : 'The receipt could not be linked.';
		} finally {
			targetActionBusy = false;
		}
	}

	async function createReceiptTarget(): Promise<void> {
		if (targetLinkReceiptId === null) return;
		targetActionBusy = true;
		receiptError = '';
		const payload: Record<string, string> = { target_type: linkTargetType };
		if (linkTargetType === 'finance') payload.finance_category_id = targetFinanceCategoryId;
		else {
			payload.vehicle_id = targetVehicleId;
			payload.vehicle_category = targetVehicleCategory;
		}
		try {
			const result = await request<ReceiptResponse>(`/api/receipts/${targetLinkReceiptId}/target`, 'POST', JSON.stringify(payload));
			if (result.receipt) receipts = receipts.map((receipt) => receipt.id === result.receipt?.id ? result.receipt : receipt);
			statusMessage = get(t)('Entry created and linked to the receipt.');
			targetLinkReceiptId = null;
			targetActionMode = null;
		} catch (cause) {
			receiptError = cause instanceof Error ? cause.message : 'The linked entry could not be created.';
		} finally {
			targetActionBusy = false;
		}
	}

	function choosePdf(event: Event): void {
		const input = event.currentTarget as HTMLInputElement;
		const file = input.files?.[0] ?? null;
		if (!file) return;
		photoPages = [];
		if (photosInput) photosInput.value = '';
		if (cameraInput) cameraInput.value = '';
		if (file && file.size > receiptFileSizeLimit) {
			setSelectedFile(null);
			error = 'The invoice must be 10 MB or smaller.';
			return;
		}
		setSelectedFile(file);
	}

	async function addPhotoPages(event: Event): Promise<void> {
		const input = event.currentTarget as HTMLInputElement;
		const added = Array.from(input.files ?? []);
		input.value = '';
		if (added.length === 0) return;
		if (photoPages.length + added.length > receiptPageLimit) {
			clearMessages();
			error = `A receipt can contain up to ${receiptPageLimit} photo pages.`;
			return;
		}
		photoPages = [...photoPages, ...added];
		if (pdfInput) pdfInput.value = '';
		await rebuildPhotoPdf();
	}

	async function removePhotoPage(index: number): Promise<void> {
		photoPages = photoPages.filter((_, pageIndex) => pageIndex !== index);
		await rebuildPhotoPdf();
	}

	async function rebuildPhotoPdf(): Promise<void> {
		setSelectedFile(null);
		if (photoPages.length === 0) return;
		preparingScan = true;
		try {
			setSelectedFile(await createReceiptPdf(photoPages));
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The photos could not be combined into a receipt.';
		} finally {
			preparingScan = false;
		}
	}

	async function previewFile(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		clearMessages();
		preview = null;
		if (!canCreate || !selectedFile) {
			error = 'Choose a PDF, JPEG or PNG invoice first.';
			return;
		}
		if (selectedFile.size > receiptFileSizeLimit) {
			error = 'The invoice must be 10 MB or smaller.';
			return;
		}
		const form = new FormData();
		form.set('file', selectedFile);
		previewing = true;
		try {
			const result = await request<ReceiptPreview & ReceiptResponse>('/api/receipts/preview', 'POST', form);
			preview = result;
			fieldStatus = result.field_status ?? {};
			reviewRequired = (result.review_required ?? []) as ReceiptField[];
			reviewedFields = [];
			duplicateMatches = result.duplicate_matches ?? [];
			duplicateAcknowledged = duplicateMatches.length === 0;
			merchant = result.suggestions?.merchant ?? '';
			invoiceNumber = result.suggestions?.invoice_number ?? '';
			invoiceDate = result.suggestions?.invoice_date ?? '';
			amount = result.suggestions?.amount ?? '';
			currency = result.suggestions?.currency ?? result.default_currency ?? $defaultCurrency;
			taxAmount = result.suggestions?.tax_amount ?? '';
			fuelLiters = result.suggestions?.fuel_liters ?? '';
			fuelUnitPrice = result.suggestions?.fuel_unit_price ?? '';
			fuelOdometerValue = result.suggestions?.fuel_odometer_value ?? '';
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
		const unreviewed = reviewRequired.filter((field) => !reviewedFields.includes(field));
		if (unreviewed.length > 0) {
			error = 'Review or correct each highlighted field before saving the receipt.';
			return;
		}
		if (duplicateMatches.length > 0 && !duplicateAcknowledged) {
			error = 'Review the possible duplicate warning before saving the receipt.';
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
		if (showFuelFields) {
			form.set('fuel_liters', fuelLiters);
			form.set('fuel_unit_price', fuelUnitPrice);
			form.set('fuel_odometer_value', fuelOdometerValue);
		}
		for (const field of reviewedFields) form.append('reviewed_fields[]', field);
		if (duplicateMatches.length > 0) form.set('duplicate_acknowledged', duplicateAcknowledged ? '1' : '0');
		if (targetType === 'finance') form.set('finance_category_id', financeCategoryId);
		if (targetType === 'vehicle') {
			form.set('vehicle_id', vehicleId);
			form.set('vehicle_category', vehicleCategory);
		}
		confirming = true;
		try {
			const result = await request<ReceiptResponse>('/api/receipts/confirm', 'POST', form, idempotencyKey);
			statusMessage = get(t)(result.message ?? (targetType === 'archive' ? 'Receipt saved to archive.' : 'Receipt saved.'));
			preview = null;
			selectedFile = null;
			photoPages = [];
			fieldStatus = {};
			reviewRequired = [];
			reviewedFields = [];
			duplicateMatches = [];
			duplicateAcknowledged = false;
			if (filePreviewUrl) URL.revokeObjectURL(filePreviewUrl);
			filePreviewUrl = '';
			if (pdfInput) pdfInput.value = '';
			if (cameraInput) cameraInput.value = '';
			if (photosInput) photosInput.value = '';
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
			statusMessage = get(t)('Receipt deleted.');
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
			statusMessage = get(t)(shared ? 'Receipt shared with the household.' : 'Receipt is private again.');
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
		<p class="mt-3 max-w-3xl text-sm leading-6 text-slate-400">{$t('Upload a receipt or invoice, review the extracted details, then archive it or create a matching entry.')}</p>
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
				<div class="grid gap-3 sm:grid-cols-3">
					<label class="block text-sm font-medium text-slate-200">{$t('Choose a PDF')}
						<input bind:this={pdfInput} type="file" accept="application/pdf,.pdf" onchange={choosePdf} class="mt-2 min-h-12 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200 file:mr-3 file:min-h-8 file:rounded-lg file:border-0 file:bg-cyan-300 file:px-3 file:text-xs file:font-semibold file:text-slate-950" />
					</label>
					<label class="block text-sm font-medium text-slate-200">{$t('Take a photo page')}
						<input bind:this={cameraInput} type="file" accept="image/jpeg,image/png,image/heic,image/heif,.jpg,.jpeg,.png,.heic,.heif" capture="environment" onchange={addPhotoPages} class="mt-2 min-h-12 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200 file:mr-3 file:min-h-8 file:rounded-lg file:border-0 file:bg-cyan-300 file:px-3 file:text-xs file:font-semibold file:text-slate-950" />
					</label>
					<label class="block text-sm font-medium text-slate-200">{$t('Add photo pages')}
						<input bind:this={photosInput} type="file" accept="image/jpeg,image/png,image/heic,image/heif,.jpg,.jpeg,.png,.heic,.heif" multiple onchange={addPhotoPages} class="mt-2 min-h-12 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200 file:mr-3 file:min-h-8 file:rounded-lg file:border-0 file:bg-cyan-300 file:px-3 file:text-xs file:font-semibold file:text-slate-950" />
					</label>
				</div>
				<p class="mt-2 text-xs leading-5 text-slate-500">{$t('PDF up to 10 MB and 5 pages. Add up to 5 JPEG, PNG or iPhone HEIC photo pages; they are converted and combined on this device.')}</p>
				{#if photoPages.length > 0}
					<div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
						<p class="text-sm font-medium text-slate-200">{$t('Scan pages')} ({photoPages.length}/{receiptPageLimit})</p>
						<ol class="mt-2 space-y-2">
							{#each photoPages as photo, index (photo.name + index)}
								<li class="flex items-center justify-between gap-3 text-sm text-slate-300"><span class="truncate">{index + 1}. {photo.name}</span><button type="button" onclick={() => void removePhotoPage(index)} class="min-h-9 rounded-lg border border-slate-700 px-3 text-xs font-medium text-slate-200 hover:border-slate-500" aria-label={$t('Remove page')}>{$t('Remove')}</button></li>
							{/each}
						</ol>
						{#if preparingScan}<p role="status" class="mt-2 text-xs text-cyan-100">{$t('Preparing scan locally…')}</p>{/if}
					</div>
				{/if}
				{#if filePreviewUrl && selectedFile}
					<div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
						<iframe title={$t('Invoice preview')} src={filePreviewUrl} class="h-[28rem] w-full rounded-lg bg-white"></iframe>
					</div>
				{/if}
				<button type="submit" disabled={previewing || preparingScan || !selectedFile} class="min-h-11 rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-wait disabled:opacity-60">
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
						{:else if targetType === 'vehicle'}
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
							<input bind:value={merchant} oninput={() => clearFieldReview('merchant')} maxlength="200" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" />
							{#if needsReview('merchant')}<div class="mt-2 rounded-lg border border-amber-300/30 bg-amber-300/5 p-2 text-xs text-amber-100"><span>{$t(fieldStatusLabel('merchant'))}</span><label class="mt-2 flex items-start gap-2"><input type="checkbox" checked={isFieldReviewed('merchant')} onchange={(event) => setFieldReview('merchant', event)} class="mt-0.5 accent-cyan-300" /><span>{$t(fieldReviewPrompt(merchant))}</span></label></div>{/if}
						</label>
						<label class="block text-sm font-medium text-slate-200">{$t('Invoice number')} <span class="font-normal text-slate-500">{$t('Optional')}</span>
							<input bind:value={invoiceNumber} oninput={() => clearFieldReview('invoice_number')} maxlength="100" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" />
							{#if needsReview('invoice_number')}<div class="mt-2 rounded-lg border border-amber-300/30 bg-amber-300/5 p-2 text-xs text-amber-100"><span>{$t(fieldStatusLabel('invoice_number'))}</span><label class="mt-2 flex items-start gap-2"><input type="checkbox" checked={isFieldReviewed('invoice_number')} onchange={(event) => setFieldReview('invoice_number', event)} class="mt-0.5 accent-cyan-300" /><span>{$t(fieldReviewPrompt(invoiceNumber))}</span></label></div>{/if}
						</label>
						<label class="block text-sm font-medium text-slate-200">{$t('Invoice date')}
							<input bind:value={invoiceDate} oninput={() => clearFieldReview('invoice_date')} type="date" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" />
							{#if needsReview('invoice_date')}<div class="mt-2 rounded-lg border border-amber-300/30 bg-amber-300/5 p-2 text-xs text-amber-100"><span>{$t(fieldStatusLabel('invoice_date'))}</span><label class="mt-2 flex items-start gap-2"><input type="checkbox" checked={isFieldReviewed('invoice_date')} onchange={(event) => setFieldReview('invoice_date', event)} class="mt-0.5 accent-cyan-300" /><span>{$t(fieldReviewPrompt(invoiceDate))}</span></label></div>{/if}
						</label>
						<label class="block text-sm font-medium text-slate-200">{$t('Total amount')}
							<input bind:value={amount} oninput={() => clearFieldReview('amount')} required inputmode="decimal" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" placeholder="125.00" />
							{#if needsReview('amount')}<div class="mt-2 rounded-lg border border-amber-300/30 bg-amber-300/5 p-2 text-xs text-amber-100"><span>{$t(fieldStatusLabel('amount'))}</span><label class="mt-2 flex items-start gap-2"><input type="checkbox" checked={isFieldReviewed('amount')} onchange={(event) => setFieldReview('amount', event)} class="mt-0.5 accent-cyan-300" /><span>{$t(fieldReviewPrompt(amount))}</span></label></div>{/if}
						</label>
						<label class="block text-sm font-medium text-slate-200">{$t('Currency')}
							<input bind:value={currency} oninput={() => clearFieldReview('currency')} required maxlength="3" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm uppercase text-white" placeholder="EUR" />
							{#if needsReview('currency')}<div class="mt-2 rounded-lg border border-amber-300/30 bg-amber-300/5 p-2 text-xs text-amber-100"><span>{$t(fieldStatusLabel('currency'))}</span><label class="mt-2 flex items-start gap-2"><input type="checkbox" checked={isFieldReviewed('currency')} onchange={(event) => setFieldReview('currency', event)} class="mt-0.5 accent-cyan-300" /><span>{$t(fieldReviewPrompt(currency))}</span></label></div>{/if}
						</label>
						<label class="block text-sm font-medium text-slate-200">{$t('Tax amount')} <span class="font-normal text-slate-500">{$t('Optional')}</span>
							<input bind:value={taxAmount} oninput={() => clearFieldReview('tax_amount')} inputmode="decimal" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" placeholder="19.00" />
							{#if needsReview('tax_amount')}<div class="mt-2 rounded-lg border border-amber-300/30 bg-amber-300/5 p-2 text-xs text-amber-100"><span>{$t(fieldStatusLabel('tax_amount'))}</span><label class="mt-2 flex items-start gap-2"><input type="checkbox" checked={isFieldReviewed('tax_amount')} onchange={(event) => setFieldReview('tax_amount', event)} class="mt-0.5 accent-cyan-300" /><span>{$t(fieldReviewPrompt(taxAmount))}</span></label></div>{/if}
						</label>
					</div>
					{#if showFuelFields}
						<fieldset class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
							<legend class="px-2 text-sm font-semibold text-slate-200">{$t('Fuel details')}</legend>
							<p class="mb-3 text-xs leading-5 text-slate-400">{$t('Review liters and unit price before saving. Odometer is optional.')}</p>
							<div class="grid gap-4 sm:grid-cols-3">
								<label class="block text-sm font-medium text-slate-200">{$t('Fuel quantity (liters)')} <span class="font-normal text-slate-500">{$t('Optional')}</span>
								<input bind:value={fuelLiters} oninput={() => clearFieldReview('fuel_liters')} type="number" min="0.001" step="0.001" inputmode="decimal" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" />
								{#if needsReview('fuel_liters')}<div class="mt-2 rounded-lg border border-amber-300/30 bg-amber-300/5 p-2 text-xs text-amber-100"><span>{$t(fieldStatusLabel('fuel_liters'))}</span><label class="mt-2 flex items-start gap-2"><input type="checkbox" checked={isFieldReviewed('fuel_liters')} onchange={(event) => setFieldReview('fuel_liters', event)} class="mt-0.5 accent-cyan-300" /><span>{$t(fieldReviewPrompt(fuelLiters))}</span></label></div>{/if}
								</label>
								<label class="block text-sm font-medium text-slate-200">{$t('Price per liter')} <span class="font-normal text-slate-500">{$t('Optional')}</span>
								<input bind:value={fuelUnitPrice} oninput={() => clearFieldReview('fuel_unit_price')} type="number" min="0.0001" step="0.0001" inputmode="decimal" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" />
								{#if needsReview('fuel_unit_price')}<div class="mt-2 rounded-lg border border-amber-300/30 bg-amber-300/5 p-2 text-xs text-amber-100"><span>{$t(fieldStatusLabel('fuel_unit_price'))}</span><label class="mt-2 flex items-start gap-2"><input type="checkbox" checked={isFieldReviewed('fuel_unit_price')} onchange={(event) => setFieldReview('fuel_unit_price', event)} class="mt-0.5 accent-cyan-300" /><span>{$t(fieldReviewPrompt(fuelUnitPrice))}</span></label></div>{/if}
								</label>
								<label class="block text-sm font-medium text-slate-200">{$t('Odometer reading')} <span class="font-normal text-slate-500">{$t('Optional')}</span>
								<input bind:value={fuelOdometerValue} oninput={() => clearFieldReview('fuel_odometer_value')} type="number" min="0" step="0.001" inputmode="decimal" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white" />
								{#if needsReview('fuel_odometer_value')}<div class="mt-2 rounded-lg border border-amber-300/30 bg-amber-300/5 p-2 text-xs text-amber-100"><span>{$t(fieldStatusLabel('fuel_odometer_value'))}</span><label class="mt-2 flex items-start gap-2"><input type="checkbox" checked={isFieldReviewed('fuel_odometer_value')} onchange={(event) => setFieldReview('fuel_odometer_value', event)} class="mt-0.5 accent-cyan-300" /><span>{$t(fieldReviewPrompt(fuelOdometerValue))}</span></label></div>{/if}
								</label>
							</div>
							{#if fuelCalculatedTotal !== null && currency.length === 3}
								<p class="mt-3 text-sm text-slate-300">{$t('Quantity × unit price')}: {formatCurrency(fuelCalculatedTotal.toFixed(2), currency)}</p>
								{#if amount && Math.abs(fuelCalculatedTotal - Number(amount)) > 0.01}<p role="status" class="mt-2 rounded-lg border border-amber-300/30 bg-amber-300/5 p-3 text-xs text-amber-100">{$t('This calculation differs from the invoice total; check for fees or discounts.')}</p>{/if}
							{/if}
						</fieldset>
					{/if}
					{#if duplicateMatches.length > 0}
						<div class="rounded-xl border border-amber-300/40 bg-amber-300/10 p-4" role="status">
							<h4 class="font-semibold text-amber-100">{$t('Possible duplicate receipts')}</h4>
							<ul class="mt-2 space-y-1 text-sm text-amber-100/90">{#each duplicateMatches as duplicate (duplicate.id)}<li>{duplicate.merchant ?? $t('Invoice')} · {formatDate(duplicate.invoice_date)} · {formatCurrency(duplicate.amount, duplicate.currency)} ({$t(duplicate.reasons.includes('identical_file') ? 'Identical file' : duplicate.reasons.includes('merchant_invoice_number') ? 'Same merchant and invoice number' : 'Same merchant, date and total')})</li>{/each}</ul>
							<label class="mt-3 flex items-start gap-2 text-sm text-amber-50"><input type="checkbox" bind:checked={duplicateAcknowledged} class="mt-0.5 accent-cyan-300" /><span>{$t('I reviewed the warning and want to save this receipt anyway.')}</span></label>
						</div>
					{/if}
					<div class="flex flex-col gap-3 border-t border-slate-800 pt-5 sm:flex-row sm:items-center sm:justify-between">
						<p class="text-xs leading-5 text-slate-500">{$t('OCR runs locally. Nothing is sent to an external provider.')}</p>
						<button type="submit" disabled={!canConfirm} class="min-h-11 rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-wait disabled:opacity-60">
							{$t(confirming ? 'Saving receipt…' : targetType === 'archive' ? 'Confirm and save receipt' : 'Confirm and create entry')}
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
				<label class="block text-sm font-medium text-slate-200">{$t('Target')}
					<select bind:value={targetFilter} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 text-sm text-white">
						<option value="">{$t('All targets')}</option>
						<option value="archive">{$t('Archived only')}</option>
						<option value="finance">{$t('Finance expense')}</option>
						<option value="vehicle">{$t('Vehicle cost')}</option>
					</select>
				</label>
				<label class="block text-sm font-medium text-slate-200">{$t('Category')}
					<select bind:value={categoryFilter} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 text-sm text-white">
						<option value="">{$t('All categories')}</option>
						{#each categoryFilters as option (option.value)}<option value={option.value}>{categoryFilterLabel(option)}</option>{/each}
					</select>
				</label>
				<label class="block text-sm font-medium text-slate-200">{$t('Sharing status')}
					<select bind:value={sharingStatusFilter} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 text-sm text-white">
						<option value="">{$t('All sharing states')}</option>
						<option value="private">{$t('Private')}</option>
						<option value="shared">{$t('Shared with household')}</option>
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
					<li class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
						<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
							<div class="min-w-0">
								<p class="truncate font-semibold text-slate-100">{receipt.merchant ?? $t('Invoice')}</p>
								<p class="mt-1 text-sm text-slate-400">{formatDate(receipt.invoice_date)} · {formatCurrency(receipt.amount, receipt.currency)} · {$t(currentReceiptTarget(receipt))}</p>
								{#if receipt.invoice_number}<p class="mt-1 text-xs text-slate-500">{$t('Invoice number')}: {receipt.invoice_number}</p>{/if}
								{#if receipt.fuel_liters && receipt.fuel_unit_price}<p class="mt-1 text-xs text-slate-400">{receipt.fuel_liters} {$t('liters')} × {formatCurrency(receipt.fuel_unit_price, receipt.currency)}/{$t('liter')}{#if receipt.fuel_odometer_value} · {$t('Odometer reading')}: {receipt.fuel_odometer_value}{/if}</p>{/if}
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
						</div>
						{#if canEdit && receipt.is_owner && !receipt.target_linked}
							<div class="mt-3 flex flex-wrap gap-2 border-t border-slate-800 pt-3">
								<button type="button" onclick={() => void openTargetAction(receipt, 'link', 'finance')} class="min-h-9 rounded-lg border border-cyan-300/30 px-3 text-xs font-medium text-cyan-100 hover:bg-cyan-300/10">{$t('Link to existing entry')}</button>
								<button type="button" onclick={() => void openTargetAction(receipt, 'create', 'finance')} class="min-h-9 rounded-lg border border-slate-700 px-3 text-xs font-medium text-slate-200 hover:bg-slate-800">{$t('Create linked entry')}</button>
							</div>
						{/if}
						{#if targetLinkReceiptId === receipt.id}
							<form class="mt-3 space-y-3 rounded-xl border border-slate-800 bg-slate-900/70 p-4" onsubmit={(event) => { event.preventDefault(); void (targetActionMode === 'link' ? saveReceiptTarget() : createReceiptTarget()); }}>
								<div class="flex flex-wrap items-end gap-3">
									<label class="block min-w-48 flex-1 text-sm font-medium text-slate-200">{$t('Target type')}
										<select bind:value={linkTargetType} onchange={() => { targetRecordId = ''; void loadTargetOptions(); }} class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white"><option value="finance">{$t('Finance expense')}</option><option value="vehicle">{$t('Vehicle cost')}</option></select>
									</label>
									{#if targetActionMode === 'link'}
										<label class="block min-w-48 flex-[2] text-sm font-medium text-slate-200">{$t('Search targets')}
											<input bind:value={linkSearch} oninput={() => { targetRecordId = ''; }} class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white" />
										</label>
										<button type="button" onclick={() => void loadTargetOptions()} disabled={targetOptionsLoading} class="min-h-10 rounded-lg border border-slate-700 px-3 text-sm text-slate-200 disabled:opacity-50">{$t('Search')}</button>
									{/if}
								</div>
								{#if targetActionMode === 'link'}
									<label class="block text-sm font-medium text-slate-200">{$t('Existing entry')}
										<select bind:value={targetRecordId} required disabled={targetOptionsLoading || targetMatches.length === 0} class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white"><option value="">{$t(targetOptionsLoading ? 'Loading…' : 'No matching entries')}</option>{#each targetMatches as target (target.id)}<option value={target.id}>{target.label}</option>{/each}</select>
									</label>
								{:else if linkTargetType === 'finance'}
									<label class="block text-sm font-medium text-slate-200">{$t('Finance category')}
										<select bind:value={targetFinanceCategoryId} required disabled={targetCreateCategories.length === 0} class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white">{#each targetCreateCategories as category (category.id)}<option value={category.id}>{category.name}</option>{/each}</select>
									</label>
								{:else}
									<div class="grid gap-3 sm:grid-cols-2">
										<label class="block text-sm font-medium text-slate-200">{$t('Vehicle')}
											<select bind:value={targetVehicleId} required disabled={targetCreateVehicles.length === 0} class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white">{#each targetCreateVehicles as vehicle (vehicle.id)}<option value={vehicle.id}>{vehicle.name} · {vehicle.make} {vehicle.model}</option>{/each}</select>
										</label>
										<label class="block text-sm font-medium text-slate-200">{$t('Vehicle cost category')}
											<select bind:value={targetVehicleCategory} class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white">{#each vehicleCostCategories as category (category.value)}<option value={category.value}>{$t(category.label)}</option>{/each}</select>
										</label>
									</div>
								{/if}
								<div class="flex flex-wrap gap-2">
									<button type="submit" disabled={targetActionBusy || targetOptionsLoading || (targetActionMode === 'link' ? !targetRecordId : linkTargetType === 'finance' ? !targetFinanceCategoryId : !targetVehicleId)} class="min-h-10 rounded-lg bg-cyan-300 px-4 text-sm font-semibold text-slate-950 disabled:opacity-50">{$t(targetActionBusy ? 'Saving…' : targetActionMode === 'link' ? 'Link receipt' : 'Create and link')}</button>
									<button type="button" onclick={() => { targetLinkReceiptId = null; targetActionMode = null; }} class="min-h-10 rounded-lg border border-slate-700 px-4 text-sm text-slate-200">{$t('Cancel')}</button>
								</div>
							</form>
						{/if}
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
