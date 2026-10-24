<script lang="ts">
	import { t } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { formatMeasurement } from '#lib/number-format';
	import type { UtilityMeter, UtilitiesResponse } from '#lib/utilities';

	type ImportPreviewRow = {
		row: number;
		reading_date: string;
		value: string;
		note: string | null;
		action: 'import' | 'skip' | 'invalid' | 'omit';
	};
	type ImportPreview = {
		message?: string;
		mapping_required?: boolean;
		columns?: string[];
		suggested_mapping?: { reading_date: number | null; value: number | null; note: number | null };
		preview_token?: string;
		meter?: { id: number; name: string; unit: string };
		total_rows?: number;
		ready_count?: number;
		skipped_count?: number;
		rows?: ImportPreviewRow[];
		errors?: Array<{ row: number; message: string }>;
		errorsByField?: Record<string, string[]>;
	};
	type ImportResult = { message?: string; created_count?: number; skipped_count?: number };
	type ImportColumnMapping = { reading_date: string; value: string; note: string };
	type FinanceCategory = { id: number; type: 'expense' | 'income'; name: string; visibility: string };
	type FinanceImportRow = {
		row: number;
		occurred_on: string;
		description: string;
		amount: string;
		currency: string;
		type: 'expense' | 'income';
		payee: string | null;
		category: { id: number; name: string } | null;
		action: 'import' | 'skip' | 'invalid' | 'omit';
		selected: boolean;
	};
	type FinanceImportPreview = {
		format?: 'csv' | 'camt.053';
		mapping_required?: boolean;
		columns?: string[];
		suggested_mapping?: { occurred_on: number | null; description: number | null; amount: number | null; currency: number | null; payee: number | null; category: number | null };
		mapping?: Record<string, number | null>;
		preview_token?: string;
		selected_rows?: number[];
		ready_count?: number;
		skipped_count?: number;
		total_rows?: number;
		rows?: FinanceImportRow[];
		errors?: Array<{ row: number; message: string }>;
		categories?: FinanceCategory[];
		message?: string;
	};
	type FinanceImportColumnMapping = { occurred_on: string; description: string; amount: string; currency: string; payee: string; category: string };

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const importAccess = $derived(shell.modules.find((module) => module.key === 'import'));
	const utilityAccess = $derived(shell.modules.find((module) => module.key === 'utilities'));
	const financeAccess = $derived(shell.modules.find((module) => module.key === 'finance'));
	const hasImportView = $derived(importAccess?.capabilities.view === true);
	const canView = $derived(importAccess?.capabilities.view === true && utilityAccess?.capabilities.view === true);
	const canImport = $derived(importAccess?.capabilities.create === true && utilityAccess?.capabilities.create === true);
	const canViewFinance = $derived(importAccess?.capabilities.view === true && financeAccess?.capabilities.view === true);
	const canImportFinance = $derived(importAccess?.capabilities.create === true && financeAccess?.capabilities.view === true && financeAccess?.capabilities.create === true);

	let meters = $state<UtilityMeter[]>([]);
	let meterId = $state('');
	let file = $state<File | null>(null);
	let preview = $state<ImportPreview | null>(null);
	let columns = $state<string[]>([]);
	let columnMapping = $state<ImportColumnMapping>({ reading_date: '', value: '', note: '' });
	let loading = $state(false);
	let confirming = $state(false);
	let error = $state('');
	let message = $state('');
	let idempotencyKey = $state('');
	let financeFile = $state<File | null>(null);
	let financePreview = $state<FinanceImportPreview | null>(null);
	let financeColumns = $state<string[]>([]);
	let financeMapping = $state<FinanceImportColumnMapping>({ occurred_on: '', description: '', amount: '', currency: '', payee: '', category: '' });
	let financeCategories = $state<FinanceCategory[]>([]);
	let expenseCategoryId = $state('');
	let incomeCategoryId = $state('');
	let financeLoading = $state(false);
	let financeConfirming = $state(false);
	let financeError = $state('');
	let financeMessage = $state('');
	let financeIdempotencyKey = $state('');
	let financeSelectedRows = $state<number[]>([]);
	let financeSelectionInitialized = $state(false);
	const mappingReady = $derived(
		columnMapping.reading_date !== ''
			&& columnMapping.value !== ''
			&& columnMapping.reading_date !== columnMapping.value
			&& (columnMapping.note === '' || (columnMapping.note !== columnMapping.reading_date && columnMapping.note !== columnMapping.value))
	);
	const financeMappingReady = $derived(
		financeMapping.occurred_on !== ''
			&& financeMapping.description !== ''
			&& financeMapping.amount !== ''
			&& financeMapping.currency !== ''
			&& new Set([financeMapping.occurred_on, financeMapping.description, financeMapping.amount, financeMapping.currency, financeMapping.payee, financeMapping.category].filter(Boolean)).size === [financeMapping.occurred_on, financeMapping.description, financeMapping.amount, financeMapping.currency, financeMapping.payee, financeMapping.category].filter(Boolean).length
	);

	$effect(() => {
		if (!shell.loading && canView) void loadMeters();
	});

	$effect(() => {
		if (shell.loading || !canViewFinance) financeCategories = [];
	});

	async function loadMeters(): Promise<void> {
		loading = true;
		error = '';
		try {
			const response = await fetch('/api/utilities/meters', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as UtilitiesResponse;
			if (!response.ok) throw new Error(result.message ?? 'Accessible meters could not be loaded.');
			meters = result.meters ?? [];
			if (!meters.some((meter) => String(meter.id) === meterId)) meterId = meters[0] ? String(meters[0].id) : '';
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Accessible meters could not be loaded.';
		} finally {
			loading = false;
		}
	}

	function resetPreview(): void {
		preview = null;
		idempotencyKey = '';
		message = '';
	}

	function resetFinancePreview(): void {
		financePreview = null;
		financeIdempotencyKey = '';
		financeMessage = '';
		financeSelectedRows = [];
		financeSelectionInitialized = false;
	}

	function toggleFinanceRow(row: FinanceImportRow, checked: boolean): void {
		financeSelectedRows = checked
			? [...financeSelectedRows, row.row].sort((left, right) => left - right)
			: financeSelectedRows.filter((selected) => selected !== row.row);
		if (financePreview) {
			financePreview = {
				...financePreview,
				preview_token: undefined,
				selected_rows: financeSelectedRows,
				ready_count: financeSelectedRows.length,
				rows: financePreview.rows?.map((candidate) => candidate.row === row.row
					? { ...candidate, selected: checked, action: checked ? 'import' : 'omit' }
					: candidate),
			};
		}
	}

	function createIdempotencyKey(): string {
		if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
		const bytes = crypto.getRandomValues(new Uint8Array(16));
		bytes[6] = (bytes[6] & 0x0f) | 0x40;
		bytes[8] = (bytes[8] & 0x3f) | 0x80;
		const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
		return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
	}

	async function previewFinanceFile(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		await submitFinancePreview(false);
	}

	async function previewMappedFinanceFile(): Promise<void> {
		await submitFinancePreview(true);
	}

	async function updateFinanceSelection(): Promise<void> {
		await submitFinancePreview(financeColumns.length > 0);
	}

	async function submitFinancePreview(useMapping: boolean): Promise<void> {
		financeError = '';
		financeMessage = '';
		if (!canImportFinance || !financeFile) {
			financeError = 'Import Create and Finance View and Create access are required to import transactions.';
			return;
		}
		const token = csrfToken();
		if (!token) { financeError = 'Reload this page and try again.'; return; }
		if (useMapping && !financeMappingReady) {
			financeError = 'Map distinct date, description, amount and currency columns. Payee and category are optional.';
			return;
		}
		const form = new FormData();
		form.set('file', financeFile);
		if (useMapping) {
			for (const [key, value] of Object.entries(financeMapping)) if (value !== '') form.set(`mapping[${key}]`, value);
		}
		if (expenseCategoryId) form.set('category_defaults[expense]', expenseCategoryId);
		if (incomeCategoryId) form.set('category_defaults[income]', incomeCategoryId);
		if (financeSelectionInitialized) {
			form.set('selection_submitted', '1');
			for (const row of financeSelectedRows) form.append('selected_rows[]', String(row));
		}
		financeLoading = true;
		try {
			const response = await fetch('/api/import/finance/transactions/preview', {
				method: 'POST', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token }, body: form
			});
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as FinanceImportPreview & ApiResponse;
			if (!response.ok) throw new Error(firstError(result) || result.message || 'The finance file could not be previewed.');
			financeCategories = result.categories ?? financeCategories;
			if (result.mapping_required) {
				financeColumns = result.columns ?? [];
				financeSelectionInitialized = false;
				const suggested = result.suggested_mapping;
				financeMapping = {
					occurred_on: suggested?.occurred_on == null ? '' : String(suggested.occurred_on),
					description: suggested?.description == null ? '' : String(suggested.description),
					amount: suggested?.amount == null ? '' : String(suggested.amount),
					currency: suggested?.currency == null ? '' : String(suggested.currency),
					payee: suggested?.payee == null ? '' : String(suggested.payee),
					category: suggested?.category == null ? '' : String(suggested.category),
				};
				financePreview = null;
			} else {
				financePreview = result;
				financeColumns = result.columns ?? financeColumns;
				financeSelectedRows = result.selected_rows ?? [];
				financeSelectionInitialized = true;
			}
			financeIdempotencyKey = '';
		} catch (cause) {
			financeError = cause instanceof Error ? cause.message : 'The finance file could not be previewed.';
		} finally {
			financeLoading = false;
		}
	}

	async function confirmFinanceImport(): Promise<void> {
		if (!canImportFinance || !financePreview?.preview_token || !financeFile || (financePreview.errors?.length ?? 0) > 0 || financeConfirming) return;
		const token = csrfToken();
		if (!token) { financeError = 'Reload this page and try again.'; return; }
		if (!financeIdempotencyKey) financeIdempotencyKey = createIdempotencyKey();
		const form = new FormData();
		form.set('file', financeFile);
		form.set('preview_token', financePreview.preview_token);
		form.set('confirmed', '1');
		if (financeColumns.length > 0) {
			for (const [key, value] of Object.entries(financeMapping)) if (value !== '') form.set(`mapping[${key}]`, value);
		}
		if (expenseCategoryId) form.set('category_defaults[expense]', expenseCategoryId);
		if (incomeCategoryId) form.set('category_defaults[income]', incomeCategoryId);
		if (financeSelectionInitialized) {
			form.set('selection_submitted', '1');
			for (const row of financeSelectedRows) form.append('selected_rows[]', String(row));
		}
		financeConfirming = true;
		financeError = '';
		financeMessage = '';
		try {
			const response = await fetch('/api/import/finance/transactions/confirm', {
				method: 'POST', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token, 'Idempotency-Key': financeIdempotencyKey }, body: form
			});
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as ImportResult & ApiResponse;
			if (!response.ok) throw new Error(firstError(result) || result.message || 'The finance import could not be completed.');
			resetFinancePreview();
			financeMessage = `${result.created_count ?? 0} transactions imported; ${result.skipped_count ?? 0} duplicates skipped.`;
		} catch (cause) {
			financeError = cause instanceof Error ? cause.message : 'The finance import could not be completed.';
		} finally {
			financeConfirming = false;
		}
	}

	async function previewFile(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		await submitPreview(false);
	}

	async function previewMappedFile(): Promise<void> {
		await submitPreview(true);
	}

	async function submitPreview(useMapping: boolean): Promise<void> {
		error = '';
		message = '';
		if (!canImport || !file || !meterId) {
			error = 'Choose an accessible meter and CSV file. Import Create and Utilities Create access are required.';
			return;
		}
		const token = csrfToken();
		if (!token) { error = 'Reload this page and try again.'; return; }
		const form = new FormData();
		form.set('file', file);
		if (useMapping) {
			if (!mappingReady) { error = 'Choose distinct columns for the date and value, and optionally the note.'; return; }
			form.set('mapping[reading_date]', columnMapping.reading_date);
			form.set('mapping[value]', columnMapping.value);
			form.set('mapping[note]', columnMapping.note);
		}
		loading = true;
		try {
			const response = await fetch(`/api/import/utilities/meters/${meterId}/readings/preview`, {
				method: 'POST', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token }, body: form
			});
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as ImportPreview;
			if (!response.ok) throw new Error(firstError(result as unknown as ApiResponse) || result.message || 'The CSV could not be previewed.');
			if (result.mapping_required) {
				columns = result.columns ?? [];
				const suggested = result.suggested_mapping;
				columnMapping = {
					reading_date: suggested?.reading_date === null || suggested?.reading_date === undefined ? '' : String(suggested.reading_date),
					value: suggested?.value === null || suggested?.value === undefined ? '' : String(suggested.value),
					note: suggested?.note === null || suggested?.note === undefined ? '' : String(suggested.note),
				};
				preview = null;
			} else {
				columns = result.columns ?? columns;
				preview = result;
			}
			idempotencyKey = '';
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The CSV could not be previewed.';
		} finally {
			loading = false;
		}
	}

	async function confirmImport(): Promise<void> {
		if (!canImport || !preview?.preview_token || !file || !meterId || (preview.errors?.length ?? 0) > 0 || confirming) return;
		const token = csrfToken();
		if (!token) { error = 'Reload this page and try again.'; return; }
		if (!idempotencyKey) idempotencyKey = createIdempotencyKey();
		const form = new FormData();
		form.set('file', file);
		form.set('preview_token', preview.preview_token);
		form.set('confirmed', '1');
		form.set('mapping[reading_date]', columnMapping.reading_date);
		form.set('mapping[value]', columnMapping.value);
		form.set('mapping[note]', columnMapping.note);
		confirming = true;
		error = '';
		message = '';
		try {
			const response = await fetch(`/api/import/utilities/meters/${meterId}/readings/confirm`, {
				method: 'POST', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token, 'Idempotency-Key': idempotencyKey }, body: form
			});
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as ImportResult & ApiResponse;
			if (!response.ok) throw new Error(firstError(result) || result.message || 'The import could not be completed.');
			resetPreview();
			message = `${result.created_count ?? 0} readings imported; ${result.skipped_count ?? 0} unchanged rows skipped.`;
			await loadMeters();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The import could not be completed.';
		} finally {
			confirming = false;
		}
	}
</script>

<svelte:head>
	<title>{$t("Import")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Preview and confirm utility reading CSV imports.")} />
</svelte:head>

<section class="mx-auto max-w-screen-xl">
	<div class="border-b border-slate-800 pb-6">
		<p class="text-sm font-medium tracking-wide text-cyan-200">{$t("Import module")}</p>
		<h1 class="mt-2 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{$t("Bring your meter history with you.")}</h1>
		<p class="mt-3 max-w-2xl text-sm leading-6 text-slate-400">{$t("Import utility readings from a CSV file or export the records you can access. Imports are validated in full before you confirm; repeating a confirmed upload never creates duplicate readings.")}</p>
	</div>

	{#if error}<p role="alert" class="mt-5 rounded-xl border border-rose-300/30 bg-rose-300/10 px-4 py-3 text-sm text-rose-100">{$t(error)}</p>{/if}
	{#if message}<p role="status" class="mt-5 rounded-xl border border-emerald-300/30 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">{$t(message)}</p>{/if}

	{#if shell.loading}
		<p role="status" class="mt-6 rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-5 text-sm text-slate-300">{$t("Loading module access…")}</p>
	{:else if !hasImportView}
		<div class="mt-6 rounded-2xl border border-amber-300/20 bg-amber-300/5 p-5 text-sm leading-6 text-amber-100"><p class="font-semibold">{$t("Import View access is required.")}</p><p class="mt-1 text-amber-100/75">{$t("Grant access to an enabled source module before importing or exporting its records.")}</p></div>
	{:else}
		{#if canView}
		<div class="mt-6 grid gap-5 xl:grid-cols-[minmax(18rem,0.75fr)_minmax(0,1.25fr)]">
			<section class="h-fit rounded-2xl border border-slate-800 bg-slate-900/50 p-5 sm:p-6" aria-labelledby="csv-import-heading">
				<h2 id="csv-import-heading" class="text-lg font-semibold text-white">{$t("Utility reading CSV")}</h2>
				<p class="mt-2 text-sm leading-6 text-slate-400">{$t("Upload a UTF-8 CSV with a header row, then choose which columns contain the date, value and optional note. Dates use YYYY-MM-DD and values use a decimal point.")}</p>
				<label class="mt-5 block text-sm font-medium text-slate-200">{$t("Meter")}
					<select bind:value={meterId} onchange={resetPreview} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20">
						{#each meters as meter (meter.id)}<option value={String(meter.id)}>{meter.name} · {meter.unit}</option>{/each}
					</select>
				</label>
				{#if canImport}
					<form class="mt-4 space-y-3" onsubmit={previewFile}>
						<label class="block text-sm font-medium text-slate-200">{$t("CSV file")} <span class="font-normal text-slate-500">{$t("(max 10 MB, 1,000 rows)")}</span>
							<input type="file" accept=".csv,text/csv,text/plain" onchange={(event) => { file = event.currentTarget.files?.[0] ?? null; columns = []; columnMapping = { reading_date: '', value: '', note: '' }; resetPreview(); }} class="mt-2 block min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-800 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-200 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" />
						</label>
						<button type="submit" disabled={loading || !file} class="min-h-11 w-full rounded-xl border border-slate-700 px-4 text-sm font-semibold text-slate-100 transition hover:border-cyan-300 disabled:cursor-not-allowed disabled:opacity-50">{$t(loading ? 'Validating…' : 'Preview CSV')}</button>
					</form>
					{#if columns.length > 0}
						<section class="mt-5 border-t border-slate-800 pt-4" aria-labelledby="column-mapping-heading">
							<h3 id="column-mapping-heading" class="text-sm font-semibold text-slate-100">{$t("Map CSV columns")}</h3>
							<p class="mt-1 text-xs leading-5 text-slate-400">{$t("Check the suggested columns. A note column is optional.")}</p>
							<div class="mt-3 space-y-3">
								{#each [{ key: 'reading_date', label: 'Reading date' }, { key: 'value', label: 'Cumulative value' }, { key: 'note', label: 'Note (optional)' }] as field (field.key)}
									<label class="block text-sm font-medium text-slate-200">{field.label}
										<select bind:value={columnMapping[field.key as keyof ImportColumnMapping]} onchange={resetPreview} class="mt-1.5 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20">
											<option value="">{$t("Do not map")}</option>
											{#each columns as column, index (index)}<option value={String(index)}>{column || `Column ${index + 1}`} · {index + 1}</option>{/each}
										</select>
									</label>
								{/each}
								<button type="button" onclick={previewMappedFile} disabled={loading || !mappingReady} class="min-h-11 w-full rounded-xl border border-cyan-300/40 px-4 text-sm font-semibold text-cyan-100 transition hover:bg-cyan-300/10 disabled:cursor-not-allowed disabled:opacity-50">{$t(loading ? 'Validating…' : 'Preview mapped rows')}</button>
							</div>
						</section>
					{/if}
				{:else}
					<p class="mt-4 rounded-xl border border-slate-800 bg-slate-950/60 p-3 text-sm leading-6 text-slate-400">{$t("Import Create and Utilities Create access are required to upload readings.")}</p>
				{/if}
				{#if canView}
					<a href={`/api/import/utilities/meters/${meterId}/readings.csv`} class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-cyan-300 px-4 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{$t("Export accessible readings")}</a>
				{/if}
				<p class="mt-4 text-xs leading-5 text-slate-500">{$t("Exports contain only readings visible to your account. Notes that could be interpreted as spreadsheet formulas are escaped.")}</p>
			</section>

			<section class="rounded-2xl border border-slate-800 bg-slate-900/50 p-5 sm:p-6" aria-labelledby="preview-heading">
				<div class="flex flex-wrap items-start justify-between gap-3"><div><h2 id="preview-heading" class="text-lg font-semibold text-white">{$t("Review before saving")}</h2><p class="mt-1 text-sm text-slate-400">{$t("Nothing is written until you confirm this preview.")}</p></div>
					{#if preview}<span class="rounded-full border border-slate-700 px-3 py-1 text-xs text-slate-300">{preview.ready_count ?? 0} {$t("new ·")} {preview.skipped_count ?? 0} unchanged</span>{/if}
				</div>
				{#if !preview}
					<p class="mt-5 rounded-xl border border-dashed border-slate-700 bg-slate-950/40 p-5 text-sm leading-6 text-slate-400">{$t("Choose a meter and CSV file, then preview the rows and validation results here.")}</p>
				{:else}
						<p class="mt-4 text-sm text-slate-300">{preview.total_rows ?? 0} {$t("rows checked for")} {preview.meter?.name ?? 'the selected meter'}.</p>
						{#if (preview.errors?.length ?? 0) > 0}
							<div class="mt-4 rounded-xl border border-rose-300/25 bg-rose-300/5 p-4"><h3 class="text-sm font-semibold text-rose-100">{$t("Fix these rows and preview again")}</h3><ul class="mt-2 space-y-1 text-sm text-rose-100/80">{#each preview.errors ?? [] as rowError, index (`${rowError.row}-${index}`)}<li>{$t("Row")} {rowError.row}: {rowError.message}</li>{/each}</ul></div>
					{/if}
						{#if (preview.rows?.length ?? 0) > 0}
							<div class="mt-4 overflow-x-auto rounded-xl border border-slate-800"><table class="w-full min-w-[35rem] text-left text-sm"><thead class="bg-slate-950/70 text-xs uppercase tracking-wide text-slate-400"><tr><th class="px-3 py-2.5">{$t("Row")}</th><th class="px-3 py-2.5">{$t("Date")}</th><th class="px-3 py-2.5">{$t("Value")}</th><th class="px-3 py-2.5">{$t("Note")}</th><th class="px-3 py-2.5">{$t("Result")}</th></tr></thead><tbody>{#each preview.rows?.slice(0, 25) ?? [] as row (row.row)}<tr class="border-t border-slate-800 text-slate-200"><td class="px-3 py-2.5 tabular-nums">{row.row}</td><td class="px-3 py-2.5 tabular-nums">{row.reading_date}</td><td class="px-3 py-2.5 tabular-nums">{formatMeasurement(row.value, preview.meter?.unit)} {preview.meter?.unit}</td><td class="max-w-48 truncate px-3 py-2.5">{row.note || '—'}</td><td class="px-3 py-2.5 text-xs {row.action === 'import' ? 'text-cyan-200' : row.action === 'skip' ? 'text-slate-400' : 'text-rose-200'}">{row.action === 'import' ? 'New' : row.action === 'skip' ? 'Skip' : 'Invalid'}</td></tr>{/each}</tbody></table></div>
							{#if (preview.rows?.length ?? 0) > 25}<p class="mt-2 text-xs text-slate-500">{$t("Showing the first 25 rows of")} {preview.rows?.length}.</p>{/if}
					{/if}
						{#if canImport && preview.preview_token && (preview.errors?.length ?? 0) === 0}<button type="button" onclick={confirmImport} disabled={confirming || loading} class="mt-5 min-h-11 rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:opacity-50">{confirming ? 'Importing…' : `Confirm import · ${preview.ready_count ?? 0} new readings`}</button>{/if}
				{/if}
			</section>
		</div>
		{/if}

		{#if canViewFinance}
			<section class="mt-6 rounded-2xl border border-slate-800 bg-slate-900/50 p-5 sm:p-6" aria-labelledby="finance-import-heading">
				<div class="max-w-3xl">
					<p class="text-sm font-medium tracking-wide text-cyan-200">{$t("Finance import")}</p>
					<h2 id="finance-import-heading" class="mt-1 text-xl font-semibold text-white">{$t("Bring bank transactions into Finance")}</h2>
					<p class="mt-2 text-sm leading-6 text-slate-400">{$t("Preview CSV or CAMT.053 statements before saving. CSV amounts use a decimal point or comma without thousands separators: positive values are expenses and negative values are income. New transactions remain private to your account.")}</p>
				</div>
				{#if financeError}<p role="alert" class="mt-4 rounded-xl border border-rose-300/30 bg-rose-300/10 px-4 py-3 text-sm text-rose-100">{$t(financeError)}</p>{/if}
				{#if financeMessage}<p role="status" class="mt-4 rounded-xl border border-emerald-300/30 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">{$t(financeMessage)}</p>{/if}
				{#if canImportFinance}
					<div class="mt-5 grid gap-5 lg:grid-cols-[minmax(18rem,0.75fr)_minmax(0,1.25fr)]">
						<div class="space-y-4">
							<form class="space-y-3" onsubmit={previewFinanceFile}>
								<label class="block text-sm font-medium text-slate-200">{$t("Finance statement file")} <span class="font-normal text-slate-500">{$t("(CSV or CAMT.053 XML · max 10 MB / 1,000 transactions)")}</span>
									<input type="file" accept=".csv,.xml,.camt,.053,text/csv,text/plain,application/xml,text/xml" onchange={(event) => { financeFile = event.currentTarget.files?.[0] ?? null; financeColumns = []; financeMapping = { occurred_on: '', description: '', amount: '', currency: '', payee: '', category: '' }; resetFinancePreview(); }} class="mt-2 block min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-800 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-200 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" />
								</label>
								<button type="submit" disabled={financeLoading || !financeFile} class="min-h-11 w-full rounded-xl border border-slate-700 px-4 text-sm font-semibold text-slate-100 transition hover:border-cyan-300 disabled:cursor-not-allowed disabled:opacity-50">{$t(financeLoading ? 'Validating…' : 'Inspect or preview statement')}</button>
							</form>
							{#if financeCategories.length > 0}
								<div class="space-y-3 border-t border-slate-800 pt-4">
									<p class="text-sm font-semibold text-slate-100">{$t("Default categories when a row has no category")}</p>
									<label class="block text-sm font-medium text-slate-200">{$t("Expense category")}
										<select bind:value={expenseCategoryId} onchange={resetFinancePreview} class="mt-1.5 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20"><option value="">{$t("Choose an expense category")}</option>{#each financeCategories.filter((category) => category.type === 'expense') as category (category.id)}<option value={String(category.id)}>{category.name}</option>{/each}</select>
									</label>
									<label class="block text-sm font-medium text-slate-200">{$t("Income category")}
										<select bind:value={incomeCategoryId} onchange={resetFinancePreview} class="mt-1.5 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20"><option value="">{$t("Choose an income category")}</option>{#each financeCategories.filter((category) => category.type === 'income') as category (category.id)}<option value={String(category.id)}>{category.name}</option>{/each}</select>
									</label>
								</div>
							{/if}
							{#if financeColumns.length > 0}
								<section class="border-t border-slate-800 pt-4" aria-labelledby="finance-column-mapping-heading">
									<h3 id="finance-column-mapping-heading" class="text-sm font-semibold text-slate-100">{$t("Map CSV columns")}</h3>
									<p class="mt-1 text-xs leading-5 text-slate-400">{$t("Required: date, description, amount and currency. Payee and category are optional.")}</p>
									<div class="mt-3 space-y-3">
										{#each [{ key: 'occurred_on', label: 'Transaction date', required: true }, { key: 'description', label: 'Description', required: true }, { key: 'amount', label: 'Signed amount', required: true }, { key: 'currency', label: 'Currency', required: true }, { key: 'payee', label: 'Payee (optional)', required: false }, { key: 'category', label: 'Category (optional)', required: false }] as field (field.key)}
											<label class="block text-sm font-medium text-slate-200">{$t(field.label)}
												<select bind:value={financeMapping[field.key as keyof FinanceImportColumnMapping]} onchange={resetFinancePreview} class="mt-1.5 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20"><option value="">{$t(field.required ? 'Choose a column' : 'Do not map')}</option>{#each financeColumns as column, index (index)}<option value={String(index)}>{column || `Column ${index + 1}`} · {index + 1}</option>{/each}</select>
											</label>
										{/each}
										<button type="button" onclick={previewMappedFinanceFile} disabled={financeLoading || !financeMappingReady} class="min-h-11 w-full rounded-xl border border-cyan-300/40 px-4 text-sm font-semibold text-cyan-100 transition hover:bg-cyan-300/10 disabled:cursor-not-allowed disabled:opacity-50">{$t(financeLoading ? 'Validating…' : 'Preview mapped transactions')}</button>
									</div>
								</section>
							{/if}
						</div>
						<div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4" aria-live="polite">
							{#if !financePreview}
								<p class="text-sm leading-6 text-slate-400">{$t("Choose a statement file, inspect its columns if it is CSV, then review the rows here before saving.")}</p>
							{:else}
								<div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="text-base font-semibold text-white">{$t("Review before saving")}</h3><p class="mt-1 text-sm text-slate-400">{financePreview.total_rows ?? 0} {$t("transactions checked")} · {financePreview.format === 'camt.053' ? 'CAMT.053' : 'CSV'}</p></div><span class="rounded-full border border-slate-700 px-3 py-1 text-xs text-slate-300">{financePreview.ready_count ?? 0} {$t("new ·")} {financePreview.skipped_count ?? 0} {$t("duplicates")}</span></div>
								{#if (financePreview.errors?.length ?? 0) > 0}<div class="mt-4 rounded-xl border border-rose-300/25 bg-rose-300/5 p-4"><h4 class="text-sm font-semibold text-rose-100">{$t("Review these rows before importing")}</h4><ul class="mt-2 space-y-1 text-sm text-rose-100/80">{#each financePreview.errors ?? [] as rowError, index (`${rowError.row}-${index}`)}<li>{$t("Row")} {rowError.row}: {rowError.message}</li>{/each}</ul></div>{/if}
								{#if (financePreview.rows?.length ?? 0) > 0}<div class="mt-4 max-h-[32rem] overflow-auto rounded-xl border border-slate-800"><table class="w-full min-w-[54rem] text-left text-sm"><thead class="bg-slate-950/70 text-xs uppercase tracking-wide text-slate-400"><tr><th class="px-3 py-2.5">{$t("Select")}</th><th class="px-3 py-2.5">{$t("Date")}</th><th class="px-3 py-2.5">{$t("Description")}</th><th class="px-3 py-2.5">{$t("Payee")}</th><th class="px-3 py-2.5">{$t("Type")}</th><th class="px-3 py-2.5">{$t("Amount")}</th><th class="px-3 py-2.5">{$t("Category")}</th><th class="px-3 py-2.5">{$t("Result")}</th></tr></thead><tbody>{#each financePreview.rows ?? [] as row (row.row)}<tr class="border-t border-slate-800 text-slate-200"><td class="px-3 py-2.5">{#if row.action === 'import' || row.action === 'omit'}<input type="checkbox" checked={financeSelectedRows.includes(row.row)} onchange={(event) => toggleFinanceRow(row, event.currentTarget.checked)} aria-label={$t('Select transaction row {row}', { row: row.row })} class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-cyan-300 focus:ring-cyan-300" />{:else}<span aria-label={$t('Not selectable')}>—</span>{/if}</td><td class="whitespace-nowrap px-3 py-2.5 tabular-nums">{row.occurred_on}</td><td class="max-w-56 truncate px-3 py-2.5" title={row.description}>{row.description}</td><td class="max-w-48 truncate px-3 py-2.5" title={row.payee ?? ''}>{row.payee ?? '—'}</td><td class="px-3 py-2.5">{$t(row.type === 'expense' ? 'Expense' : 'Income')}</td><td class="whitespace-nowrap px-3 py-2.5 tabular-nums">{row.amount} {row.currency}</td><td class="px-3 py-2.5">{row.category?.name ?? '—'}</td><td class="px-3 py-2.5 text-xs {row.action === 'import' ? 'text-cyan-200' : row.action === 'skip' || row.action === 'omit' ? 'text-slate-400' : 'text-rose-200'}">{$t(row.action === 'import' ? 'Selected' : row.action === 'omit' ? 'Not selected' : row.action === 'skip' ? 'Duplicate' : 'Invalid')}</td></tr>{/each}</tbody></table></div>{/if}
								{#if !financePreview.preview_token && financeSelectionInitialized}<button type="button" onclick={updateFinanceSelection} disabled={financeLoading || financeConfirming} class="mt-5 min-h-11 rounded-xl border border-cyan-300/40 px-5 text-sm font-semibold text-cyan-100 transition hover:bg-cyan-300/10 disabled:cursor-not-allowed disabled:opacity-50">{$t(financeLoading ? 'Updating preview…' : 'Update selected rows')}</button>{/if}
								{#if financePreview.preview_token && (financePreview.ready_count ?? 0) > 0}<button type="button" onclick={confirmFinanceImport} disabled={financeConfirming || financeLoading} class="mt-5 min-h-11 rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:opacity-50">{financeConfirming ? 'Importing…' : $t('Confirm import · {count} selected transactions', { count: financePreview.ready_count ?? 0 })}</button>{/if}
							{/if}
						</div>
					</div>
				{:else}
					<p class="mt-4 rounded-xl border border-slate-800 bg-slate-950/60 p-3 text-sm leading-6 text-slate-400">{$t("Import Create and Finance View and Create access are required to upload bank statements.")}</p>
				{/if}
			</section>
		{/if}
	{/if}
</section>
