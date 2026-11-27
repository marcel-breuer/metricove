<script lang="ts">
	import { Alert } from '#lib/components/ui/alert/index.js';
	import { Card } from '#lib/components/ui/card/index.js';
	import Badge from '#lib/ui/Badge.svelte';
	import { Checkbox } from '#lib/components/ui/checkbox/index.js';
	import { Input } from '#lib/components/ui/input/index.js';
	import { Label } from '#lib/components/ui/label/index.js';
	import * as NativeSelect from '#lib/components/ui/native-select/index.js';
	import Button from '#lib/ui/Button.svelte';
	import Modal from '#lib/ui/Modal.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import Disclosure from '#lib/ui/Disclosure.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import { goto } from '$app/navigation';
	import { onDestroy } from 'svelte';
	import { getContext } from 'svelte';
	import { get } from 'svelte/store';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { defaultCurrency, formatDate, numberLocale, t } from '#lib/i18n';
	import { canonicalizeDecimalInput, formatCurrency, formatDecimalInput } from '#lib/number-format';
	import { calculateFuelTotal, calculateMissingFuelValue, currencyAmountsDiffer, type FuelCalculationField } from '#lib/vehicle-fuel';
	import { createReceiptPdf, receiptFileSizeLimit, receiptPageLimit } from '#lib/receipt-files';
	import { vehicleCostCategories } from '#lib/vehicles';
	import type { ReceiptDocument, ReceiptDuplicateMatch, ReceiptFieldStatus, ReceiptOptions, ReceiptPagination, ReceiptPreview, ReceiptResponse, ReceiptSuggestions } from '#lib/receipts';

	type TargetType = 'archive' | 'vehicle';
	type ReceiptField = keyof ReceiptSuggestions;

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'receipts'));
	const canView = $derived(access?.capabilities.view === true);
	const canCreate = $derived(access?.capabilities.create === true);
	const canEdit = $derived(access?.capabilities.edit === true);
	const canManageSharing = $derived(access?.capabilities.manage === true);

	let options = $state<ReceiptOptions>({
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
	let pdfInput = $state<HTMLInputElement | null>(null);
	let cameraInput = $state<HTMLInputElement | null>(null);
	let photosInput = $state<HTMLInputElement | null>(null);
	let photoPages = $state<File[]>([]);
	let preparingScan = $state(false);
	let filePreviewUrl = $state('');
	let preview = $state<ReceiptPreview | null>(null);
	let reviewOpen = $state(false);
	let fieldStatus = $state<Record<string, ReceiptFieldStatus>>({});
	let reviewRequired = $state<ReceiptField[]>([]);
	let reviewedFields = $state<ReceiptField[]>([]);
	let duplicateMatches = $state<ReceiptDuplicateMatch[]>([]);
	let duplicateAcknowledged = $state(false);
	let targetType = $state<TargetType>('archive');
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
	let fuelFullTank = $state(false);
	let calculatedFuelField = $state<FuelCalculationField | null>(null);
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
	let linkTargetType = $state<'vehicle'>('vehicle');
	let linkSearch = $state('');
	let targetMatches = $state<Array<{ id: number; label: string; date: string; amount: string; currency: string }>>([]);
	let targetCreateVehicles = $state<ReceiptOptions['vehicles']>([]);
	let targetRecordId = $state('');
	let targetVehicleId = $state('');
	let targetVehicleCategory = $state('fuel');
	let targetOptionsLoading = $state(false);
	let targetActionBusy = $state(false);

	const availableTargetOptions = $derived([
		{ value: 'archive' as const, label: 'Save to archive only' },
		...(options.vehicles_available ? [{ value: 'vehicle' as const, label: 'Vehicle cost' }] : [])
	]);
	const hasReceiptFilters = $derived(Boolean(receiptSearch.trim() || dateFrom || dateTo || minAmount || maxAmount || currencyFilter || targetFilter || categoryFilter || sharingStatusFilter));
	const showFuelFields = true;
	const canConfirm = $derived(!confirming && !preparingScan && reviewRequired.every((field) => reviewedFields.includes(field)) && (duplicateMatches.length === 0 || duplicateAcknowledged));
	const fuelCalculatedTotal = $derived.by(() => {
		return calculateFuelTotal(fuelLiters, fuelUnitPrice, $numberLocale);
	});

	function updateReceiptFuelCalculation(changed: FuelCalculationField): void {
		if (calculatedFuelField && calculatedFuelField !== changed) {
			if (calculatedFuelField === 'amount') amount = '';
			if (calculatedFuelField === 'fuel_liters') fuelLiters = '';
			if (calculatedFuelField === 'fuel_unit_price') fuelUnitPrice = '';
			calculatedFuelField = null;
		}
		const calculated = calculateMissingFuelValue({ amount, fuel_liters: fuelLiters, fuel_unit_price: fuelUnitPrice, locale: $numberLocale });
		if (calculated) {
			if (calculated.field === 'amount') amount = formatDecimalInput(calculated.value, $numberLocale);
			if (calculated.field === 'fuel_liters') fuelLiters = formatDecimalInput(calculated.value, $numberLocale);
			if (calculated.field === 'fuel_unit_price') fuelUnitPrice = formatDecimalInput(calculated.value, $numberLocale);
			calculatedFuelField = calculated.field;
		} else if (calculatedFuelField === changed) {
			calculatedFuelField = null;
		}
	}
	const currentReceiptTarget = (receipt: ReceiptDocument): string => {
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
				if (minAmount) query.set('min_amount', canonicalizeDecimalInput(minAmount, $numberLocale));
				if (maxAmount) query.set('max_amount', canonicalizeDecimalInput(maxAmount, $numberLocale));
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
						vehicles_available: result.vehicles_available === true,
						vehicles: result.vehicles ?? []
					};
					if (!availableTargetOptions.some((option) => option.value === targetType)) {
				targetType = availableTargetOptions[0]?.value ?? 'archive';
					}
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

	function needsReview(field: ReceiptField): boolean {
		return reviewRequired.includes(field);
	}

	function isFieldReviewed(field: ReceiptField): boolean {
		return reviewedFields.includes(field);
	}

	function setFieldReview(field: ReceiptField, checked: boolean): void {
		reviewedFields = checked
			? [...new Set([...reviewedFields, field])]
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

	async function openTargetAction(receipt: ReceiptDocument, mode: 'link' | 'create', type: 'vehicle'): Promise<void> {
		clearMessages();
		receiptError = '';
		targetLinkReceiptId = receipt.id;
		targetActionMode = mode;
		linkTargetType = type;
		linkSearch = '';
		targetRecordId = '';
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
			targetCreateVehicles = result.create_vehicles ?? [];
			if (!targetRecordId && targetMatches[0]) targetRecordId = String(targetMatches[0].id);
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
		payload.vehicle_id = targetVehicleId;
		payload.vehicle_category = targetVehicleCategory;
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
			reviewOpen = true;
			fieldStatus = result.field_status ?? {};
			reviewRequired = (result.review_required ?? []) as ReceiptField[];
			reviewedFields = [];
			duplicateMatches = result.duplicate_matches ?? [];
			duplicateAcknowledged = duplicateMatches.length === 0;
			merchant = result.suggestions?.merchant ?? '';
			invoiceNumber = result.suggestions?.invoice_number ?? '';
			invoiceDate = result.suggestions?.invoice_date ?? '';
			amount = formatDecimalInput(result.suggestions?.amount, $numberLocale);
			calculatedFuelField = null;
			fuelFullTank = false;
			currency = result.suggestions?.currency ?? result.default_currency ?? $defaultCurrency;
			taxAmount = formatDecimalInput(result.suggestions?.tax_amount, $numberLocale);
			fuelLiters = formatDecimalInput(result.suggestions?.fuel_liters, $numberLocale);
			fuelUnitPrice = formatDecimalInput(result.suggestions?.fuel_unit_price, $numberLocale);
			fuelOdometerValue = formatDecimalInput(result.suggestions?.fuel_odometer_value, $numberLocale);
			updateReceiptFuelCalculation('amount');
			description = merchant ? get(t)('Invoice from') + ' ' + merchant : get(t)('Invoice expense');
			idempotencyKey = makeIdempotencyKey();
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
		const vehicleFuelTarget = targetType === 'vehicle' && vehicleCategory === 'fuel';
		form.set('amount', vehicleFuelTarget && calculatedFuelField === 'amount' ? '' : canonicalizeDecimalInput(amount, $numberLocale));
		form.set('currency', currency);
		form.set('tax_amount', taxAmount ? canonicalizeDecimalInput(taxAmount, $numberLocale) : '');
		if (showFuelFields) {
			form.set('fuel_liters', vehicleFuelTarget && calculatedFuelField === 'fuel_liters' ? '' : fuelLiters ? canonicalizeDecimalInput(fuelLiters, $numberLocale) : '');
			form.set('fuel_unit_price', vehicleFuelTarget && calculatedFuelField === 'fuel_unit_price' ? '' : fuelUnitPrice ? canonicalizeDecimalInput(fuelUnitPrice, $numberLocale) : '');
			form.set('fuel_odometer_value', fuelOdometerValue ? canonicalizeDecimalInput(fuelOdometerValue, $numberLocale) : '');
			form.set('fuel_full_tank', targetType === 'vehicle' && vehicleCategory === 'fuel' && fuelFullTank ? '1' : '0');
		}
		for (const field of reviewedFields) form.append('reviewed_fields[]', field);
		if (duplicateMatches.length > 0) form.set('duplicate_acknowledged', duplicateAcknowledged ? '1' : '0');
		if (targetType === 'vehicle') {
			form.set('vehicle_id', vehicleId);
			form.set('vehicle_category', vehicleCategory);
		}
		confirming = true;
		try {
			const result = await request<ReceiptResponse>('/api/receipts/confirm', 'POST', form, idempotencyKey);
			statusMessage = get(t)(result.message ?? (targetType === 'archive' ? 'Receipt saved to archive.' : 'Receipt saved.'));
			reviewOpen = false;
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

{#snippet fieldReview(field: ReceiptField, value: string)}
	{#if needsReview(field)}
		<Alert variant="warning" role="none" class="mt-2 rounded-lg border-warning/30 bg-warning/5 p-2 text-xs">
			<span>{$t(fieldStatusLabel(field))}</span>
			<div class="mt-2 flex items-start gap-2">
				<Checkbox
					id="receipt-reviewed-{field}"
					checked={isFieldReviewed(field)}
					onCheckedChange={(checked) => setFieldReview(field, checked)}
					class="mt-0.5"
				/>
				<Label for="receipt-reviewed-{field}" variant="field" class="block text-xs! text-warning!">
					{$t(fieldReviewPrompt(value))}
				</Label>
			</div>
		</Alert>
	{/if}
{/snippet}

<section class="mx-auto max-w-6xl space-y-6">
	<PageHeading eyebrow="Private documents" title="Receipts" description="Upload a receipt or invoice, review the extracted details, then archive it or create a matching entry." />

	{#if error}<Alert variant="error" role="alert" class="border-danger/30 bg-danger/10 p-4">{error}</Alert>{/if}
	{#if statusMessage}<Alert variant="success" role="status" class="bg-positive/10 p-4">{statusMessage}</Alert>{/if}

	{#if canCreate}
		<Card as="section" variant="glass" class="p-5 sm:p-6" aria-labelledby="receipt-upload-heading">
			<div class="mb-5">
				<h2 id="receipt-upload-heading" class="text-xl font-semibold text-ink">{$t('Scan a new receipt')}</h2>
				<p class="mt-1 text-sm leading-6 text-ink-muted">{$t('Local OCR suggests invoice fields. Nothing is saved until you confirm the preview.')}</p>
			</div>
			<form class="space-y-5" onsubmit={previewFile}>
				<div class="grid gap-3 sm:grid-cols-3">
					<Label variant="field" class="block text-sm font-medium text-ink-secondary">{$t('Choose a PDF')}
						<Input variant="file" bind:ref={pdfInput} type="file" accept="application/pdf,.pdf" onchange={choosePdf} class="mt-2" />
					</Label>
					<Label variant="field" class="block text-sm font-medium text-ink-secondary">{$t('Take a photo page')}
						<Input variant="file" bind:ref={cameraInput} type="file" accept="image/jpeg,image/png,image/heic,image/heif,.jpg,.jpeg,.png,.heic,.heif" capture="environment" onchange={addPhotoPages} class="mt-2" />
					</Label>
					<Label variant="field" class="block text-sm font-medium text-ink-secondary">{$t('Add photo pages')}
						<Input variant="file" bind:ref={photosInput} type="file" accept="image/jpeg,image/png,image/heic,image/heif,.jpg,.jpeg,.png,.heic,.heif" multiple onchange={addPhotoPages} class="mt-2" />
					</Label>
				</div>
				<p class="mt-2 text-xs leading-5 text-ink-subtle">{$t('PDF up to 10 MB and 5 pages. Add up to 5 JPEG, PNG or iPhone HEIC photo pages; they are converted and combined on this device.')}</p>
				{#if photoPages.length > 0}
					<Card variant="inset" class="gap-0 p-3">
						<p class="text-sm font-medium text-ink-secondary">{$t('Scan pages')} ({photoPages.length}/{receiptPageLimit})</p>
						<ol class="mt-2 space-y-2">
							{#each photoPages as photo, index (photo.name + index)}
								<li class="flex items-center justify-between gap-3 text-sm text-ink-secondary"><span class="truncate">{index + 1}. {photo.name}</span><Button type="button" onclick={() => void removePhotoPage(index)} variant="secondary" size="small" class="rounded-lg border-line-strong bg-transparent text-xs shadow-none" aria-label={$t('Remove page')}>{$t('Remove')}</Button></li>
							{/each}
						</ol>
						{#if preparingScan}<Alert variant="plain" role="status" class="mt-2 text-xs text-accent-ink">{$t('Preparing scan locally…')}</Alert>{/if}
					</Card>
				{/if}
				{#if filePreviewUrl && selectedFile}
					<Card variant="inset" class="gap-0 p-3">
						<iframe title={$t('Invoice preview')} src={filePreviewUrl} class="h-[28rem] w-full rounded-lg bg-surface"></iframe>
					</Card>
				{/if}
				<Button type="submit" disabled={previewing || preparingScan || !selectedFile} class="rounded-xl px-5 disabled:cursor-wait disabled:opacity-60">
					{$t(previewing ? 'Reading invoice locally…' : 'Preview invoice')}
				</Button>
			</form>

			{#if preview && !reviewOpen}
				<div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-6">
					<p class="text-sm text-ink-muted">{$t('Check every suggestion. The original file stays private and is stored only after confirmation.')}</p>
					<Button onclick={() => (reviewOpen = true)}>{$t('Review invoice details')}</Button>
				</div>
			{/if}
			{#if preview}
				<Modal bind:open={reviewOpen} size="fullscreen" title={$t('Review invoice details')} description={$t('Check every suggestion. The original file stays private and is stored only after confirmation.')}>
					{#if error}<Notice tone="error" message={error} class="mb-5" />{/if}
					<div class="grid gap-x-8 gap-y-6 {filePreviewUrl ? 'lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]' : ''}">
						{#if filePreviewUrl}
							<Card variant="inset" class="gap-0 p-3 lg:sticky lg:top-0 lg:self-start">
								<iframe title={$t('Invoice preview')} src={filePreviewUrl} class="h-[24rem] w-full rounded-lg bg-surface lg:h-[calc(100dvh-16rem)]"></iframe>
							</Card>
						{/if}
						<form id="receipt-review-form" class="min-w-0 space-y-5" onsubmit={confirmReceipt}>
							<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
								<div class="block text-sm font-[550] text-ink-secondary"><Label variant="field" for="receipt-entry-type" class="block">{$t('Entry type')}</Label>
									<NativeSelect.Root variant="glass" id="receipt-entry-type" bind:value={targetType} class="mt-2">
										{#each availableTargetOptions as option}
											<NativeSelect.Option value={option.value}>{$t(option.label)}</NativeSelect.Option>
										{/each}
									</NativeSelect.Root>
								</div>
								{#if targetType === 'vehicle'}
									<div class="block text-sm font-[550] text-ink-secondary"><Label variant="field" for="receipt-entry-vehicle" class="block">{$t('Vehicle')}</Label>
										<NativeSelect.Root variant="glass" id="receipt-entry-vehicle" bind:value={vehicleId} required class="mt-2">
											{#each options.vehicles as vehicle (vehicle.id)}
												<NativeSelect.Option value={vehicle.id}>{vehicle.name} · {vehicle.make} {vehicle.model}</NativeSelect.Option>
											{/each}
										</NativeSelect.Root>
									</div>
									<div class="block text-sm font-[550] text-ink-secondary"><Label variant="field" for="receipt-entry-vehicle-category" class="block">{$t('Vehicle cost category')}</Label>
										<NativeSelect.Root variant="glass" id="receipt-entry-vehicle-category" bind:value={vehicleCategory} required class="mt-2">
											{#each vehicleCostCategories as category (category.value)}
												<NativeSelect.Option value={category.value}>{$t(category.label)}</NativeSelect.Option>
											{/each}
										</NativeSelect.Root>
									</div>
								{/if}
								<Label variant="field" class="block text-sm font-medium text-ink-secondary">{$t('Description')}
									<Input variant="glass" bind:value={description} required maxlength={200} class="mt-2" />
								</Label>
								<div class="min-w-0 font-[550]">
									<Label variant="field" for="receipt-merchant" class="block text-sm font-medium text-ink-secondary">{$t('Merchant')}</Label>
									<Input variant="glass" id="receipt-merchant" bind:value={merchant} oninput={() => clearFieldReview('merchant')} maxlength={200} class="mt-2" />
									{@render fieldReview('merchant', merchant)}
								</div>
								<div class="min-w-0 font-[550]">
									<Label variant="field" for="receipt-invoice-number" class="block text-sm font-medium text-ink-secondary">{$t('Invoice number')} <span class="font-normal text-ink-subtle">{$t('Optional')}</span></Label>
									<Input variant="glass" id="receipt-invoice-number" bind:value={invoiceNumber} oninput={() => clearFieldReview('invoice_number')} maxlength={100} class="mt-2" />
									{@render fieldReview('invoice_number', invoiceNumber)}
								</div>
								<div class="min-w-0 font-[550]">
									<Label variant="field" for="receipt-invoice-date" class="block text-sm font-medium text-ink-secondary">{$t('Invoice date')}</Label>
									<Input variant="glass" id="receipt-invoice-date" bind:value={invoiceDate} oninput={() => clearFieldReview('invoice_date')} type="date" required class="mt-2" />
									{@render fieldReview('invoice_date', invoiceDate)}
								</div>
								<div class="min-w-0 font-[550]">
									<Label variant="field" for="receipt-amount" class="block text-sm font-medium text-ink-secondary">{$t('Total amount')}</Label>
									<Input variant="glass" id="receipt-amount" bind:value={amount} oninput={() => { clearFieldReview('amount'); updateReceiptFuelCalculation('amount'); }} required inputmode="decimal" class="mt-2" placeholder={formatDecimalInput('125.00', $numberLocale)} />
									{@render fieldReview('amount', amount)}
								</div>
								<div class="min-w-0 font-[550]">
									<Label variant="field" for="receipt-currency" class="block text-sm font-medium text-ink-secondary">{$t('Currency')}</Label>
									<Input variant="glass" id="receipt-currency" bind:value={currency} oninput={() => clearFieldReview('currency')} required maxlength={3} class="mt-2" placeholder="EUR" />
									{@render fieldReview('currency', currency)}
								</div>
								<div class="min-w-0 font-[550]">
									<Label variant="field" for="receipt-tax-amount" class="block text-sm font-medium text-ink-secondary">{$t('Tax amount')} <span class="font-normal text-ink-subtle">{$t('Optional')}</span></Label>
									<Input variant="glass" id="receipt-tax-amount" bind:value={taxAmount} oninput={() => clearFieldReview('tax_amount')} inputmode="decimal" class="mt-2" placeholder={formatDecimalInput('19.00', $numberLocale)} />
									{@render fieldReview('tax_amount', taxAmount)}
								</div>
							</div>
							{#if showFuelFields}
								<fieldset class="rounded-xl border border-line bg-page p-4">
									<legend class="px-2 text-sm font-semibold text-ink-secondary">{$t('Fuel details')}</legend>
									<p class="mb-3 text-xs leading-5 text-ink-muted">{$t('Review liters and unit price before saving. Odometer is optional.')}</p>
									<div class="grid gap-4 sm:grid-cols-3">
										<div class="min-w-0">
										<Label variant="field" for="receipt-fuel-liters" class="block text-sm font-medium text-ink-secondary">{$t('Fuel quantity (liters)')} <span class="font-normal text-ink-subtle">{$t('Optional')}</span></Label>
										<Input variant="glass" id="receipt-fuel-liters" bind:value={fuelLiters} oninput={() => { clearFieldReview('fuel_liters'); updateReceiptFuelCalculation('fuel_liters'); }} type="text" inputmode="decimal" class="mt-2" />
										{@render fieldReview('fuel_liters', fuelLiters)}
										</div>
										<div class="min-w-0">
										<Label variant="field" for="receipt-fuel-unit-price" class="block text-sm font-medium text-ink-secondary">{$t('Price per liter')} <span class="font-normal text-ink-subtle">{$t('Optional')}</span></Label>
										<Input variant="glass" id="receipt-fuel-unit-price" bind:value={fuelUnitPrice} oninput={() => { clearFieldReview('fuel_unit_price'); updateReceiptFuelCalculation('fuel_unit_price'); }} type="text" inputmode="decimal" class="mt-2" />
										{@render fieldReview('fuel_unit_price', fuelUnitPrice)}
										</div>
										<div class="min-w-0">
										<Label variant="field" for="receipt-fuel-odometer-value" class="block text-sm font-medium text-ink-secondary">{$t('Odometer reading')} <span class="font-normal text-ink-subtle">{$t('Optional')}</span></Label>
										<Input variant="glass" id="receipt-fuel-odometer-value" bind:value={fuelOdometerValue} oninput={() => clearFieldReview('fuel_odometer_value')} type="text" inputmode="decimal" class="mt-2" />
										{@render fieldReview('fuel_odometer_value', fuelOdometerValue)}
										</div>
									</div>
									{#if targetType === 'vehicle' && vehicleCategory === 'fuel'}<div class="mt-3 flex items-start gap-2 text-sm text-ink-secondary"><Checkbox id="receipt-fuel-full-tank" bind:checked={fuelFullTank} class="mt-1" /><Label variant="field" for="receipt-fuel-full-tank" class="block"><span class="font-medium">{$t('Full tank')}</span><span class="mt-1 block text-xs leading-5 text-ink-muted">{$t('Mark only after filling the tank completely. Consumption compares consecutive full refuels with odometer readings.')}</span></Label></div>{/if}
									{#if calculatedFuelField}<Alert variant="plain" class="mt-3 text-xs text-ink-muted" role="status">{$t('Calculated from the other two values.')}</Alert>{/if}
									{#if fuelCalculatedTotal !== null && currency.length === 3}
										<p class="mt-3 text-sm text-ink-secondary">{$t('Quantity × unit price')}: {formatCurrency(fuelCalculatedTotal, currency)}</p>
										{#if amount && currencyAmountsDiffer(fuelCalculatedTotal, canonicalizeDecimalInput(amount, $numberLocale), $numberLocale)}<Alert variant="warning" role="status" class="mt-2 rounded-lg border-warning/30 bg-warning/5 p-3 text-xs">{$t('This calculation differs from the invoice total; check for fees or discounts.')}</Alert>{/if}
									{/if}
								</fieldset>
							{/if}
							{#if duplicateMatches.length > 0}
								<Alert variant="warning" class="block border-warning/40 p-4" role="status">
									<h4 class="font-semibold text-warning">{$t('Possible duplicate receipts')}</h4>
									<ul class="mt-2 space-y-1 text-sm text-warning/90">{#each duplicateMatches as duplicate (duplicate.id)}<li>{duplicate.merchant ?? $t('Invoice')} · {formatDate(duplicate.invoice_date)} · {formatCurrency(duplicate.amount, duplicate.currency)} ({$t(duplicate.reasons.includes('identical_file') ? 'Identical file' : duplicate.reasons.includes('merchant_invoice_number') ? 'Same merchant and invoice number' : 'Same merchant, date and total')})</li>{/each}</ul>
									<div class="mt-3 flex items-start gap-2 text-sm text-warning"><Checkbox id="receipt-duplicate-acknowledgment" bind:checked={duplicateAcknowledged} class="mt-0.5" /><Label variant="field" for="receipt-duplicate-acknowledgment" class="block font-normal! text-warning!">{$t('I reviewed the warning and want to save this receipt anyway.')}</Label></div>
								</Alert>
							{/if}
						</form>
					</div>
					{#snippet footer()}
						<p class="mr-auto hidden text-xs leading-5 text-ink-subtle md:block">{$t('OCR runs locally. Nothing is sent to an external provider.')}</p>
						<Button variant="secondary" type="button" onclick={() => (reviewOpen = false)}>{$t('Cancel')}</Button>
						<Button type="submit" form="receipt-review-form" disabled={!canConfirm}>{$t(confirming ? 'Saving receipt…' : targetType === 'archive' ? 'Confirm and save receipt' : 'Confirm and create entry')}</Button>
					{/snippet}
				</Modal>
			{/if}
		</Card>
	{:else}
		<Card as="section" variant="warning" class="p-5" aria-labelledby="receipt-access-heading">
			<h2 id="receipt-access-heading" class="font-semibold text-warning">{$t('Receipt creation access is required.')}</h2>
			<p class="mt-2 text-sm leading-6 text-warning/75">{$t('Ask a household administrator to enable Receipts and grant your account Create access.')}</p>
		</Card>
	{/if}

	{#if canView}
		<Card as="section" variant="glass" class="p-5 sm:p-6" aria-labelledby="saved-receipts-heading">
			<div class="flex flex-wrap items-end justify-between gap-3">
				<div>
					<h2 id="saved-receipts-heading" class="text-xl font-semibold text-ink">{$t('Receipt archive')}</h2>
					<p class="mt-1 text-sm text-ink-muted">{$t('Private and household-shared receipts you can view are listed here.')}</p>
				</div>
				{#if loading}<span class="text-sm text-ink-muted">{$t('Loading…')}</span>{/if}
			</div>
			{#if receiptError}<Alert variant="error" role="alert" class="mt-4 border-danger/30 bg-danger/10 p-4">{receiptError}</Alert>{/if}
			<Disclosure summary="Filter receipts" hint={$t('Receipts found') + ': ' + pagination.total} class="mt-5">
			<form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" onsubmit={applyReceiptFilters} aria-label={$t('Filter receipts')}>
				<Label variant="field" class="block gap-0 sm:col-span-2">{$t('Search merchant or invoice number')}
					<Input variant="glass" bind:value={receiptSearch} type="search" maxlength={100} class="mt-2 text-sm max-sm:text-base" />
				</Label>
				<Label variant="field" class="block gap-0">{$t('Invoice date from')}
					<Input variant="glass" bind:value={dateFrom} type="date" class="mt-2 text-sm max-sm:text-base" />
				</Label>
				<Label variant="field" class="block gap-0">{$t('Invoice date to')}
					<Input variant="glass" bind:value={dateTo} type="date" class="mt-2 text-sm max-sm:text-base" />
				</Label>
				<Label variant="field" class="block gap-0">{$t('Minimum amount')}
					<Input variant="glass" bind:value={minAmount} inputmode="decimal" class="mt-2 text-sm max-sm:text-base" />
				</Label>
				<Label variant="field" class="block gap-0">{$t('Maximum amount')}
					<Input variant="glass" bind:value={maxAmount} inputmode="decimal" class="mt-2 text-sm max-sm:text-base" />
				</Label>
				<Label variant="field" class="block gap-0">{$t('Currency')}
					<NativeSelect.Root variant="glass" bind:value={currencyFilter} required={Boolean(minAmount || maxAmount)} class="mt-2">
						<NativeSelect.Option value="">{$t('All currencies')}</NativeSelect.Option>
						{#each currencies as receiptCurrency (receiptCurrency)}
							<NativeSelect.Option value={receiptCurrency}>{receiptCurrency}</NativeSelect.Option>
						{/each}
					</NativeSelect.Root>
				</Label>
				<Label variant="field" class="block gap-0">{$t('Target')}
					<NativeSelect.Root variant="glass" bind:value={targetFilter} class="mt-2">
						<NativeSelect.Option value="">{$t('All targets')}</NativeSelect.Option>
						<NativeSelect.Option value="archive">{$t('Archived only')}</NativeSelect.Option>
						<NativeSelect.Option value="vehicle">{$t('Vehicle cost')}</NativeSelect.Option>
					</NativeSelect.Root>
				</Label>
				<Label variant="field" class="block gap-0">{$t('Category')}
					<NativeSelect.Root variant="glass" bind:value={categoryFilter} class="mt-2">
						<NativeSelect.Option value="">{$t('All categories')}</NativeSelect.Option>
						{#each categoryFilters as option (option.value)}<NativeSelect.Option value={option.value}>{categoryFilterLabel(option)}</NativeSelect.Option>{/each}
					</NativeSelect.Root>
				</Label>
				<Label variant="field" class="block gap-0">{$t('Sharing status')}
					<NativeSelect.Root variant="glass" bind:value={sharingStatusFilter} class="mt-2">
						<NativeSelect.Option value="">{$t('All sharing states')}</NativeSelect.Option>
						<NativeSelect.Option value="private">{$t('Private')}</NativeSelect.Option>
						<NativeSelect.Option value="shared">{$t('Shared with household')}</NativeSelect.Option>
					</NativeSelect.Root>
				</Label>
				<div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4">
					<Button type="submit" size="small" disabled={loading}>{$t('Apply filters')}</Button>
					<Button type="button" variant="secondary" size="small" onclick={clearReceiptFilters} disabled={loading}>{$t('Clear filters')}</Button>
					<span class="ml-auto text-sm text-ink-muted">{$t('Receipts found')}: {pagination.total}</span>
				</div>
			</form>
			</Disclosure>
			<ol class="mt-4 space-y-3">
				{#each receipts as receipt (receipt.id)}
					<Card as="li" variant="inset" class="p-4">
						<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
							<div class="min-w-0">
								<p class="truncate font-semibold text-ink">{receipt.merchant ?? $t('Invoice')}</p>
								<p class="mt-1 text-sm text-ink-muted">{formatDate(receipt.invoice_date)} · {formatCurrency(receipt.amount, receipt.currency)} · {$t(currentReceiptTarget(receipt))}</p>
								{#if receipt.invoice_number}<p class="mt-1 text-xs text-ink-subtle">{$t('Invoice number')}: {receipt.invoice_number}</p>{/if}
								{#if receipt.fuel_liters && receipt.fuel_unit_price}<p class="mt-1 text-xs text-ink-muted">{receipt.fuel_liters} {$t('liters')} × {formatCurrency(receipt.fuel_unit_price, receipt.currency)}/{$t('liter')}{#if receipt.fuel_odometer_value} · {$t('Odometer reading')}: {receipt.fuel_odometer_value}{/if}{#if receipt.fuel_full_tank} · {$t('Full tank')}{/if}</p>{/if}
								<Badge class="mt-2 px-2.5 py-1 font-medium {receipt.shared_with_household ? 'border-positive/30 bg-positive/10 text-positive' : 'border-line-strong text-ink-secondary'} leading-4 whitespace-normal">{$t(receipt.shared_with_household ? 'Shared with household' : 'Private')}</Badge>
							</div>
							<div class="flex shrink-0 flex-wrap gap-2">
								<a href={'/api/receipts/' + receipt.id + '/preview'} target="_blank" rel="noopener noreferrer" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-line-strong px-3 text-sm font-medium text-accent-ink hover:bg-surface-subtle">{$t('Preview document')}</a>
								<a href={receipt.file_url} download class="inline-flex min-h-10 items-center justify-center rounded-lg border border-line-strong px-3 text-sm font-medium text-accent-ink hover:bg-surface-subtle">{$t('Download original')}</a>
								{#if canManageSharing && receipt.is_owner}
									<Button type="button" variant="secondary" onclick={() => updateReceiptSharing(receipt)} disabled={sharingId === receipt.id} class="min-h-10 rounded-lg border-positive/30 bg-transparent px-3 py-0 text-positive hover:not-disabled:bg-positive/10">{$t(sharingId === receipt.id ? 'Updating sharing…' : receipt.shared_with_household ? 'Stop sharing' : 'Share with household')}</Button>
								{/if}
								{#if canEdit && receipt.is_owner}
									<Button type="button" variant="secondary" onclick={() => deleteReceipt(receipt)} disabled={deletingId === receipt.id} class="min-h-10 rounded-lg border-danger/30 bg-transparent px-3 py-0 text-danger hover:not-disabled:bg-danger/10">{$t(deletingId === receipt.id ? 'Deleting…' : 'Delete receipt')}</Button>
								{/if}
							</div>
						</div>
						{#if canEdit && receipt.is_owner && !receipt.target_linked}
							<div class="mt-3 flex flex-wrap gap-2 border-t border-line pt-3">
								{#if options.vehicles_available}<Button type="button" onclick={() => void openTargetAction(receipt, 'link', 'vehicle')} variant="ghost" size="small" class="rounded-lg border-accent-ink/30 text-xs font-medium text-accent-ink hover:not-disabled:bg-accent/10 hover:not-disabled:text-accent-ink">{$t('Link to existing entry')}</Button>
								<Button type="button" onclick={() => void openTargetAction(receipt, 'create', 'vehicle')} variant="secondary" size="small" class="rounded-lg border-line-strong bg-transparent text-xs shadow-none">{$t('Create linked entry')}</Button>{/if}
							</div>
						{/if}
						{#if targetLinkReceiptId === receipt.id}
							<Card as="form" variant="glass" class="mt-3 space-y-3 rounded-xl p-4" onsubmit={(event) => { event.preventDefault(); void (targetActionMode === 'link' ? saveReceiptTarget() : createReceiptTarget()); }}>
								<div class="flex flex-wrap items-end gap-3">
									<div class="block min-w-48 flex-1 text-sm font-[550] text-ink-secondary"><Label variant="field" for="receipt-link-type" class="block">{$t('Target type')}</Label>
									<NativeSelect.Root variant="glass" id="receipt-link-type" bind:value={linkTargetType} onchange={() => { targetRecordId = ''; void loadTargetOptions(); }} class="mt-2"><NativeSelect.Option value="vehicle">{$t('Vehicle cost')}</NativeSelect.Option></NativeSelect.Root>
									</div>
									{#if targetActionMode === 'link'}
										<Label variant="field" class="block min-w-48 flex-[2] text-sm font-medium text-ink-secondary">{$t('Search targets')}
											<Input variant="glass" bind:value={linkSearch} oninput={() => { targetRecordId = ''; }} class="mt-2" />
										</Label>
										<Button type="button" onclick={() => void loadTargetOptions()} disabled={targetOptionsLoading} variant="secondary" size="small" class="min-h-10 rounded-lg border-line-strong bg-transparent text-sm shadow-none">{$t('Search')}</Button>
									{/if}
								</div>
								{#if targetActionMode === 'link'}
									<div class="block text-sm font-[550] text-ink-secondary"><Label variant="field" for="receipt-link-record" class="block">{$t('Existing entry')}</Label>
										<NativeSelect.Root variant="glass" id="receipt-link-record" bind:value={targetRecordId} required disabled={targetOptionsLoading || targetMatches.length === 0} class="mt-2"><NativeSelect.Option value="">{$t(targetOptionsLoading ? 'Loading…' : 'No matching entries')}</NativeSelect.Option>{#each targetMatches as target (target.id)}<NativeSelect.Option value={target.id}>{target.label}</NativeSelect.Option>{/each}</NativeSelect.Root>
									</div>
								{:else}
									<div class="grid gap-3 sm:grid-cols-2">
										<div class="block text-sm font-[550] text-ink-secondary"><Label variant="field" for="receipt-link-vehicle" class="block">{$t('Vehicle')}</Label>
											<NativeSelect.Root variant="glass" id="receipt-link-vehicle" bind:value={targetVehicleId} required disabled={targetCreateVehicles.length === 0} class="mt-2">{#each targetCreateVehicles as vehicle (vehicle.id)}<NativeSelect.Option value={vehicle.id}>{vehicle.name} · {vehicle.make} {vehicle.model}</NativeSelect.Option>{/each}</NativeSelect.Root>
										</div>
										<div class="block text-sm font-[550] text-ink-secondary"><Label variant="field" for="receipt-link-vehicle-category" class="block">{$t('Vehicle cost category')}</Label>
											<NativeSelect.Root variant="glass" id="receipt-link-vehicle-category" bind:value={targetVehicleCategory} class="mt-2">{#each vehicleCostCategories as category (category.value)}<NativeSelect.Option value={category.value}>{$t(category.label)}</NativeSelect.Option>{/each}</NativeSelect.Root>
										</div>
									</div>
								{/if}
								<div class="flex flex-wrap gap-2">
									<Button type="submit" disabled={targetActionBusy || targetOptionsLoading || (targetActionMode === 'link' ? !targetRecordId : !targetVehicleId)} size="small" class="min-h-10 rounded-lg px-4 text-sm">{$t(targetActionBusy ? 'Saving…' : targetActionMode === 'link' ? 'Link receipt' : 'Create and link')}</Button>
									<Button type="button" onclick={() => { targetLinkReceiptId = null; targetActionMode = null; }} variant="secondary" size="small" class="min-h-10 rounded-lg border-line-strong bg-transparent px-4 text-sm shadow-none">{$t('Cancel')}</Button>
								</div>
							</Card>
						{/if}
					</Card>
				{:else}
					<Card as="li" variant="inset" class="p-5 text-sm text-ink-muted">{$t(loading ? 'Loading receipts…' : hasReceiptFilters ? 'No receipts match those filters.' : 'No receipts have been saved yet.')}</Card>
				{/each}
			</ol>
			{#if pagination.last_page > 1}
				<div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
					<p class="text-sm text-ink-muted">{$t('Page')} {pagination.current_page} {$t('of')} {pagination.last_page}</p>
					<div class="flex gap-2">
						<Button type="button" variant="secondary" onclick={() => changeReceiptPage(receiptPage - 1)} disabled={loading || receiptPage <= 1} class="min-h-10 rounded-lg border-line-strong bg-transparent px-3 py-0 text-ink-secondary hover:not-disabled:bg-surface-subtle">{$t('Previous')}</Button>
						<Button type="button" variant="secondary" onclick={() => changeReceiptPage(receiptPage + 1)} disabled={loading || receiptPage >= pagination.last_page} class="min-h-10 rounded-lg border-line-strong bg-transparent px-3 py-0 text-ink-secondary hover:not-disabled:bg-surface-subtle">{$t('Next')}</Button>
					</div>
				</div>
			{/if}
		</Card>
	{/if}
</section>
