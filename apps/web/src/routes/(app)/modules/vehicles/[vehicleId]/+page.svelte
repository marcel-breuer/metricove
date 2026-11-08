<script lang="ts">
	import Notice from '#lib/ui/Notice.svelte';
	import { t, visibilityLabel } from '#lib/i18n';
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import Button from '#lib/ui/Button.svelte';
	import Modal from '#lib/ui/Modal.svelte';
import ConfirmModal from '#lib/ui/ConfirmModal.svelte';
	import { canonicalizeDecimalInput, formatDecimalInput, formatMeasurement, formatCurrency } from '#lib/number-format';
	import { defaultCurrency, formatDate, formatDateTime, numberLocale } from '#lib/i18n';
	import { vehicleCostCategories, vehicleDistanceChartPoints, vehiclePeriods, type Vehicle, type VehicleCost, type VehicleDistanceTrend, type VehicleInsurancePolicy, type VehicleMaintenanceRecord, type VehicleOdometerReading, type VehicleOdometerRevision, type VehicleResponse } from '#lib/vehicles';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const vehicleId = $derived(Number(page.params.vehicleId));
	const access = $derived(shell.modules.find((module) => module.key === 'vehicles'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreate = $derived(access?.capabilities.create ?? false);
	const canEdit = $derived(access?.capabilities.edit ?? false);
	const canManage = $derived(access?.capabilities.manage ?? false);

	let vehicle = $state<Vehicle | null>(null);
	let distanceTrend = $state<VehicleDistanceTrend | null>(null);
	let odometerReadings = $state<VehicleOdometerReading[]>([]);
	let hasMoreOdometerReadings = $state(false);
	let loadingMoreOdometerReadings = $state(false);
	let maintenanceRecords = $state<VehicleMaintenanceRecord[]>([]);
	let insurancePolicies = $state<VehicleInsurancePolicy[]>([]);
	let costs = $state<VehicleCost[]>([]);
	let moreHistory = $state({ maintenance: false, insurance: false, costs: false });
	let loadingHistory = $state({ maintenance: false, insurance: false, costs: false });
	let loading = $state(true);
	let saving = $state(false);
	let correcting = $state(false);
	let vehicleModalOpen = $state(false);
	let odometerModalOpen = $state(false);
	let correctionModalOpen = $state(false);
	let maintenanceModalOpen = $state(false);
	let insuranceModalOpen = $state(false);
	let costModalOpen = $state(false);
	let error = $state('');
	let message = $state('');
	let deleteTarget = $state<{ id: number; type: 'odometer' | 'maintenance' | 'insurance' | 'costs' } | null>(null);
	let deleteOpen = $state(false);
	let deleting = $state(false);
	let vehicleName = $state('');
	let make = $state('');
	let model = $state('');
	let modelYear = $state('');
	let fuelType = $state('petrol');
	let odometerUnit = $state<'km' | 'mi'>('km');
	let description = $state('');
	let readingDate = $state(localDate());
	let readingValue = $state('');
	let readingNote = $state('');
	let editingReadingId = $state<number | null>(null);
	let correctionDate = $state('');
	let correctionValue = $state('');
	let correctionNote = $state('');
	let correctionReason = $state('');
	let revisionsByReading = $state<Record<number, VehicleOdometerRevision[]>>({});
	let revisionsLoading = $state<number | null>(null);
	let maintenanceTitle = $state('');
	let maintenanceType = $state('service');
	let maintenanceDate = $state(localDate());
	let maintenanceNextDueDate = $state('');
	let maintenanceOdometer = $state('');
	let maintenanceNote = $state('');
	let insuranceProvider = $state('');
	let insuranceCoverage = $state('comprehensive');
	let insuranceFrom = $state(localDate());
	let insuranceUntil = $state('');
	let insuranceNote = $state('');
	let costCategory = $state('fuel');
	let costAmount = $state('');
	let costCurrency = $state($defaultCurrency);
	let costFuelLiters = $state('');
	let costFuelUnitPrice = $state('');
	let costFuelOdometer = $state('');
	let costPeriodStart = $state(localDate());
	let costPeriodEnd = $state('');
	let costPeriod = $state<'once' | 'monthly' | 'quarterly' | 'yearly'>('once');
	let costDescription = $state('');

	const chartPoints = $derived(distanceTrend ? vehicleDistanceChartPoints(distanceTrend, $numberLocale) : []);
	const chartMaximum = $derived(Math.max(1, ...chartPoints.map((point) => Number(point.distance))));
	const projectionLinePoints = $derived(chartPoints.slice(Math.max(0, (distanceTrend?.months.length ?? 0) - 1)).map((point, offset) => {
		const index = Math.max(0, (distanceTrend?.months.length ?? 0) - 1) + offset;
		return `${chartX(index)},${chartY(point.distance)}`;
	}).join(' '));
	const latestReading = $derived(odometerReadings.at(-1) ?? null);
	let activeInsuranceCount = $state(0);

	$effect(() => {
		if (!shell.loading) void loadVehicle();
	});

	function localDate(): string {
		const date = new Date();
		return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
	}

	function chartX(index: number): number {
		return 46 + index * (628 / 14);
	}

	function chartY(distance: string): number {
		return 154 - Number(distance) / chartMaximum * 112;
	}

	async function loadVehicle(): Promise<void> {
		loading = true; error = '';
		if (!canView || !Number.isInteger(vehicleId) || vehicleId < 1) {
			vehicle = null; distanceTrend = null; odometerReadings = []; hasMoreOdometerReadings = false; maintenanceRecords = []; insurancePolicies = []; costs = []; moreHistory = { maintenance: false, insurance: false, costs: false }; activeInsuranceCount = 0; loading = false; return;
		}
		try {
			const response = await fetch(`/api/vehicles/${vehicleId}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as VehicleResponse;
			if (!response.ok || !result.vehicle) throw new Error(result.message ?? 'This vehicle is unavailable or you do not have access.');
			vehicle = result.vehicle;
			distanceTrend = result.distance_trend ?? null;
			activeInsuranceCount = result.active_insurance_count ?? 0;
			odometerReadings = result.odometer_readings ?? [];
			hasMoreOdometerReadings = result.has_more_odometer_readings ?? false;
			maintenanceRecords = result.maintenance_records ?? [];
			insurancePolicies = result.insurance_policies ?? [];
			costs = result.costs ?? [];
			moreHistory = {
				maintenance: result.has_more_maintenance_records ?? false,
				insurance: result.has_more_insurance_policies ?? false,
				costs: result.has_more_costs ?? false
			};
			vehicleName = vehicle.name; make = vehicle.make; model = vehicle.model;
			modelYear = vehicle.model_year === null ? '' : String(vehicle.model_year);
			fuelType = vehicle.fuel_type; odometerUnit = vehicle.odometer_unit; description = vehicle.description ?? '';
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The vehicle could not be loaded. Check your connection and try again.';
		} finally { loading = false; }
	}

	async function loadMoreOdometerReadings(): Promise<void> {
		const oldestReading = odometerReadings[0];
		if (!oldestReading || !hasMoreOdometerReadings || loadingMoreOdometerReadings) return;
		loadingMoreOdometerReadings = true;
		try {
			const cursor = new URLSearchParams({
				limit: '5',
				before_reading_date: oldestReading.reading_date,
				before_id: String(oldestReading.id)
			});
			const response = await fetch(`/api/vehicles/${vehicleId}/odometer-readings?${cursor.toString()}`, {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' }
			});
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as VehicleResponse;
			if (!response.ok) throw new Error(result.message ?? 'Older odometer readings could not be loaded.');
			odometerReadings = [...(result.odometer_readings ?? []), ...odometerReadings];
			hasMoreOdometerReadings = result.has_more_odometer_readings ?? false;
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Older odometer readings could not be loaded. Check your connection and try again.';
		} finally {
			loadingMoreOdometerReadings = false;
		}
	}

	type VehicleHistoryType = 'maintenance' | 'insurance' | 'costs';

	async function deleteRecord(): Promise<void> {
		if (!deleteTarget) return;
		const target = deleteTarget;
		const token = tokenOrError(); if (!token) return;
		deleting = true; error = ''; message = '';
		const path = target.type === 'odometer'
			? `/api/vehicles/odometer-readings/${target.id}`
			: `/api/vehicles/${target.type === 'costs' ? 'costs' : target.type}/${target.id}`;
		try {
			const response = await fetch(path, { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token } });
			const result = (await response.json()) as VehicleResponse & ApiResponse;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok) { error = firstError(result) || result.message || 'The vehicle record could not be deleted.'; return; }
			deleteOpen = false; deleteTarget = null;
			message = target.type === 'odometer' ? 'Odometer reading deleted. The timeline was recalculated.' : 'Vehicle record deleted.';
			await loadVehicle();
		} catch { error = 'The vehicle record could not be deleted. Check your connection and try again.'; }
		finally { deleting = false; }
	}


	async function loadMoreHistory(type: VehicleHistoryType): Promise<void> {
		const records = type === 'maintenance' ? maintenanceRecords : type === 'insurance' ? insurancePolicies : costs;
		const oldest = records.at(-1);
		if (!oldest || !moreHistory[type] || loadingHistory[type]) return;

		const beforeDate = type === 'maintenance'
			? (oldest as VehicleMaintenanceRecord).occurred_on
			: type === 'insurance'
				? (oldest as VehicleInsurancePolicy).valid_from
				: (oldest as VehicleCost).period_start;
		loadingHistory = { ...loadingHistory, [type]: true };
		try {
			const cursor = new URLSearchParams({ limit: '5', before_date: beforeDate, before_id: String(oldest.id) });
			const response = await fetch(`/api/vehicles/${vehicleId}/history/${type}?${cursor.toString()}`, {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' }
			});
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as VehicleResponse;
			if (!response.ok) throw new Error(result.message ?? 'Older vehicle records could not be loaded.');
			if (type === 'maintenance') maintenanceRecords = [...maintenanceRecords, ...(result.maintenance_records ?? [])];
			if (type === 'insurance') insurancePolicies = [...insurancePolicies, ...(result.insurance_policies ?? [])];
			if (type === 'costs') costs = [...costs, ...(result.costs ?? [])];
			const hasMore = type === 'maintenance'
				? result.has_more_maintenance_records
				: type === 'insurance'
					? result.has_more_insurance_policies
					: result.has_more_costs;
			moreHistory = { ...moreHistory, [type]: hasMore ?? false };
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Older vehicle records could not be loaded.';
		} finally {
			loadingHistory = { ...loadingHistory, [type]: false };
		}
	}

	function tokenOrError(): string | null {
		const token = csrfToken();
		if (!token) error = 'Reload this page and try again.';
		return token;
	}

	async function saveVehicle(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!vehicle || !canManage) return;
		message = ''; error = '';
		const token = tokenOrError(); if (!token) return;
		saving = true;
		try {
			const response = await fetch(`/api/vehicles/${vehicle.id}`, {
				method: 'PATCH', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ name: vehicleName, make, model, model_year: modelYear === '' ? null : Number(modelYear), fuel_type: fuelType, odometer_unit: odometerUnit, description })
			});
			const result = (await response.json()) as VehicleResponse & ApiResponse;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok || !result.vehicle) { error = firstError(result) || result.message || 'Vehicle settings could not be saved.'; return; }
			message = 'Vehicle details saved.'; vehicleModalOpen = false; await loadVehicle();
		} catch { error = 'Vehicle details could not be saved. Check your connection and try again.'; }
		finally { saving = false; }
	}

	async function saveOdometer(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!vehicle || !canCreate) return;
		message = ''; error = '';
		const token = tokenOrError(); if (!token) return;
		saving = true;
		try {
			const response = await fetch(`/api/vehicles/${vehicle.id}/odometer-readings`, {
				method: 'POST', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ reading_date: readingDate, value: canonicalizeDecimalInput(readingValue, $numberLocale), note: readingNote })
			});
			const result = (await response.json()) as VehicleResponse & ApiResponse;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok) { error = firstError(result) || result.message || 'The odometer reading could not be saved.'; return; }
			readingValue = ''; readingNote = ''; readingDate = localDate(); message = 'Odometer reading saved.'; odometerModalOpen = false; await loadVehicle();
		} catch { error = 'The odometer reading could not be saved. Check your connection and try again.'; }
		finally { saving = false; }
	}

	function beginCorrection(reading: VehicleOdometerReading): void {
		correctionModalOpen = true;
		editingReadingId = reading.id; correctionDate = reading.reading_date; correctionValue = formatDecimalInput(reading.value, $numberLocale);
		correctionNote = reading.note ?? ''; correctionReason = ''; message = ''; error = '';
	}

	async function saveCorrection(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!editingReadingId || !canEdit) return;
		const token = tokenOrError(); if (!token) return;
		correcting = true; error = ''; message = '';
		try {
			const response = await fetch(`/api/vehicles/odometer-readings/${editingReadingId}`, {
				method: 'PATCH', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ reading_date: correctionDate, value: canonicalizeDecimalInput(correctionValue, $numberLocale), note: correctionNote, reason: correctionReason || null })
			});
			const result = (await response.json()) as VehicleResponse & ApiResponse;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok) { error = firstError(result) || result.message || 'The correction could not be saved.'; return; }
			editingReadingId = null; message = 'Odometer correction saved. The reading timeline was recalculated.'; correctionModalOpen = false; await loadVehicle();
		} catch { error = 'The correction could not be saved. Check your connection and try again.'; }
		finally { correcting = false; }
	}

	async function loadRevisions(readingId: number): Promise<void> {
		if (revisionsByReading[readingId]) { revisionsByReading = { ...revisionsByReading, [readingId]: [] }; return; }
		revisionsLoading = readingId;
		try {
			const response = await fetch(`/api/vehicles/odometer-readings/${readingId}/revisions`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as VehicleResponse;
			if (!response.ok) throw new Error(result.message ?? 'Correction history is unavailable.');
			revisionsByReading = { ...revisionsByReading, [readingId]: result.revisions ?? [] };
		} catch (cause) { error = cause instanceof Error ? cause.message : 'Correction history is unavailable.'; }
		finally { revisionsLoading = null; }
	}

	async function saveMaintenance(event: SubmitEvent): Promise<void> {
		event.preventDefault(); if (!vehicle || !canCreate) return;
		const payload = { title: maintenanceTitle, service_type: maintenanceType, occurred_on: maintenanceDate, next_due_on: maintenanceNextDueDate || null, odometer_value: maintenanceOdometer ? canonicalizeDecimalInput(maintenanceOdometer, $numberLocale) : null, note: maintenanceNote };
		await createRecord('maintenance', payload, () => { maintenanceTitle = ''; maintenanceOdometer = ''; maintenanceNote = ''; maintenanceDate = localDate(); maintenanceNextDueDate = ''; });
	}

	async function saveInsurance(event: SubmitEvent): Promise<void> {
		event.preventDefault(); if (!vehicle || !canCreate) return;
		const payload = { provider: insuranceProvider, coverage_type: insuranceCoverage, valid_from: insuranceFrom, valid_until: insuranceUntil, note: insuranceNote };
		await createRecord('insurance', payload, () => { insuranceProvider = ''; insuranceUntil = ''; insuranceNote = ''; insuranceFrom = localDate(); });
	}

	async function saveCost(event: SubmitEvent): Promise<void> {
		event.preventDefault(); if (!vehicle || !canCreate) return;
		const payload = { category: costCategory, amount: canonicalizeDecimalInput(costAmount, $numberLocale), currency: costCurrency, period_start: costPeriodStart, period_end: costPeriodEnd || null, period: costPeriod, description: costDescription, fuel_liters: costCategory === 'fuel' && costFuelLiters ? canonicalizeDecimalInput(costFuelLiters, $numberLocale) : null, fuel_unit_price: costCategory === 'fuel' && costFuelUnitPrice ? canonicalizeDecimalInput(costFuelUnitPrice, $numberLocale) : null, fuel_odometer_value: costCategory === 'fuel' && costFuelOdometer ? canonicalizeDecimalInput(costFuelOdometer, $numberLocale) : null };
		await createRecord('costs', payload, () => { costAmount = ''; costDescription = ''; costPeriodStart = localDate(); costPeriodEnd = ''; costPeriod = 'once'; costFuelLiters = ''; costFuelUnitPrice = ''; costFuelOdometer = ''; });
	}

	async function createRecord(kind: 'maintenance' | 'insurance' | 'costs', payload: Record<string, unknown>, reset: () => void): Promise<void> {
		if (!vehicle || !canCreate) return;
		message = ''; error = '';
		const token = tokenOrError(); if (!token) return;
		saving = true;
		try {
			const response = await fetch(`/api/vehicles/${vehicle.id}/${kind}`, {
				method: 'POST', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify(payload)
			});
			const result = (await response.json()) as VehicleResponse & ApiResponse;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok) { error = firstError(result) || result.message || 'The record could not be saved.'; return; }
			reset();
			if (kind === 'maintenance') maintenanceModalOpen = false;
			if (kind === 'insurance') insuranceModalOpen = false;
			if (kind === 'costs') costModalOpen = false;
			message = kind === 'costs' ? 'Vehicle cost saved.' : kind === 'insurance' ? 'Insurance policy saved.' : 'Maintenance record saved.'; await loadVehicle();
		} catch { error = 'The record could not be saved. Check your connection and try again.'; }
		finally { saving = false; }
	}
</script>

<svelte:head>
	<title>{vehicle ? `${vehicle.name} · Vehicles · Metricove` : 'Vehicles · Metricove'}</title>
	<meta name="description" content={$t("Vehicle details, odometer timeline, insurance, maintenance and costs.")} />
</svelte:head>

{#if loading}
	<div role="status" class="rounded-2xl border border-line bg-surface p-6 text-sm text-ink-secondary">{$t("Loading vehicle…")}</div>
{:else if !canView}
	<section class="mx-auto max-w-2xl rounded-2xl border border-warning/20 bg-warning/5 p-6 sm:p-8"><p class="text-sm font-medium text-warning">{$t("Vehicles View access is required.")}</p><h1 class="mt-2 text-2xl font-semibold text-ink">{$t("This vehicle is private to its authorized members.")}</h1><p class="mt-3 text-sm leading-6 text-ink-muted">{$t("An administrator can enable Vehicles and grant the exact module capability. Private vehicle records still require an explicit record grant.")}</p><a href="/modules/vehicles" class="mt-6 inline-flex rounded-xl border border-line-strong px-4 py-2.5 text-sm font-semibold text-ink-secondary hover:border-accent-ink">{$t("Back to Vehicles")}</a></section>
{:else if vehicle}
	<section class="mx-auto max-w-screen-xl">
		<a href="/modules/vehicles" class="inline-flex items-center gap-2 text-sm font-medium text-accent-ink hover:text-accent-ink focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent-ink"><span aria-hidden="true">←</span> {$t("All vehicles")}</a>
		<div class="mt-5 flex flex-wrap items-end justify-between gap-4 border-b border-line pb-6">
			<div><p class="text-sm font-medium tracking-wide text-accent-ink">{vehicle.make} {vehicle.model}{vehicle.model_year ? ` · ${vehicle.model_year}` : ''} · {$t(visibilityLabel(vehicle.visibility))}</p><h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{vehicle.name}</h1><p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted">{vehicle.description ?? `${vehicle.fuel_type} · ${vehicle.odometer_unit} odometer`}</p></div>
			<div class="grid grid-cols-2 gap-3 text-right"><div class="rounded-xl border border-line bg-surface px-4 py-3"><span class="block text-xs text-ink-subtle">{$t("Latest odometer")}</span><span class="mt-1 block text-lg font-semibold text-ink">{formatMeasurement(latestReading?.value, vehicle.odometer_unit)} <span class="text-sm font-medium text-ink-muted">{vehicle.odometer_unit}</span></span></div><div class="rounded-xl border border-line bg-surface px-4 py-3"><span class="block text-xs text-ink-subtle">{$t("Active cover")}</span><span class="mt-1 block text-lg font-semibold text-ink">{activeInsuranceCount}</span></div></div>
		</div>
		{#if message}<Notice tone="success" message={message} class="mt-5" />{/if}
		{#if error}<Notice tone="error" message={error} class="mt-5" />{/if}
			{#if canCreate}
				<nav aria-label={$t("Vehicle quick actions")} class="sticky top-2 z-30 mt-5 rounded-2xl border border-line-strong bg-page/95 p-2 shadow-xs backdrop-blur">
					<div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
						<Button type="button" onclick={() => (odometerModalOpen = true)} class="w-full text-center">{$t("Record mileage")}</Button>
						<Button type="button" variant="secondary" onclick={() => (maintenanceModalOpen = true)} class="w-full text-center">{$t("Add service record")}</Button>
						<Button type="button" variant="secondary" onclick={() => (insuranceModalOpen = true)} class="w-full text-center">{$t("Add insurance policy")}</Button>
						<Button type="button" variant="secondary" onclick={() => (costModalOpen = true)} class="w-full text-center">{$t("Add vehicle cost")}</Button>
					</div>
				</nav>
			{/if}

		{#if canManage}
			<button type="button" onclick={() => (vehicleModalOpen = true)} class="mt-6 min-h-10 rounded-lg border border-line-strong px-4 text-sm font-semibold text-ink-secondary">{$t('Edit vehicle')}</button>
			<Modal bind:open={vehicleModalOpen} size="fullscreen" title={$t('Edit vehicle')}>{#if error}<Notice tone="error" message={error} class="mb-5" />{/if}
			<form id="vehicle-form" class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4" onsubmit={saveVehicle}>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Display name")}<input bind:value={vehicleName} required maxlength="120" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /></label>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Make")}<input bind:value={make} required maxlength="80" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /></label>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Model")}<input bind:value={model} required maxlength="80" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /></label>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Model year")}<input bind:value={modelYear} type="number" min="1886" max="2200" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /></label>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Fuel type")}<select bind:value={fuelType} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base"><option value="petrol">{$t("Petrol")}</option><option value="diesel">{$t("Diesel")}</option><option value="hybrid">{$t("Hybrid")}</option><option value="electric">{$t("Electric")}</option><option value="lpg">{$t("LPG")}</option><option value="other">{$t("Other")}</option></select></label>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Odometer unit")}<select bind:value={odometerUnit} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base"><option value="km">km</option><option value="mi">mi</option></select></label>
				<label class="block text-sm font-medium text-ink-secondary lg:col-span-2">{$t("Description")}<input bind:value={description} maxlength="2000" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /></label>
				
			</form>
				{#snippet footer()}
					<Button variant="secondary" type="button" onclick={() => (vehicleModalOpen = false)}>{$t('Cancel')}</Button>
					<Button type="submit" form="vehicle-form" disabled={saving}>{$t(saving ? 'Saving…' : 'Save vehicle details')}</Button>
				{/snippet}
			</Modal>
		{/if}

		<section class="mt-8 rounded-2xl border border-line bg-surface p-5 sm:p-7" aria-labelledby="odometer-trend-heading">
			<div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-sm font-medium text-accent-ink">{$t("Mileage trend")}</p><h2 id="odometer-trend-heading" class="mt-1 text-xl font-semibold text-ink">{$t("Monthly distance")}</h2></div><p class="text-xs text-ink-subtle">{$t("Last 12 months · next 3 months projected")}</p></div>
			{#if !distanceTrend?.has_data}
				<p class="mt-5 rounded-xl border border-line bg-page px-4 py-5 text-sm leading-6 text-ink-muted">{$t("Add two visible odometer readings from the last 12 months to see monthly distance and an average projection for the next three months.")}</p>
			{:else if distanceTrend}
				<div role="group" class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-ink-secondary" aria-label={$t("Distance chart legend")}>
					<span class="inline-flex items-center gap-2"><span class="size-3 rounded-sm bg-accent" aria-hidden="true"></span>{$t("Recorded distance")}</span>
					<span class="inline-flex items-center gap-2"><span class="w-6 border-t-2 border-dashed border-warning" aria-hidden="true"></span>{$t("Average monthly projection")}: {formatMeasurement(distanceTrend.average_monthly_distance, distanceTrend.unit, $numberLocale)} {distanceTrend.unit}</span>
				</div>
				<p id="vehicle-distance-chart-scroll-hint" class="mt-2 text-xs leading-5 text-ink-muted sm:hidden">{$t("Swipe or scroll horizontally to view the full chart.")}</p>
				<!-- svelte-ignore a11y_no_noninteractive_tabindex -->
				<div class="mt-3 overflow-x-auto focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink" role="region" aria-label={$t("Monthly distance chart")} aria-describedby="vehicle-distance-chart-scroll-hint" tabindex="0">
					<svg class="h-52 min-w-[38rem] w-full" viewBox="0 0 720 205" role="img" aria-labelledby="vehicle-distance-chart-title" aria-describedby="vehicle-distance-chart-description vehicle-distance-chart-data">
						<title id="vehicle-distance-chart-title">{$t("Monthly distance chart")}</title>
						<desc id="vehicle-distance-chart-description">{$t("Monthly distance chart description", { unit: distanceTrend.unit })}</desc>
						<line x1="28" y1="154" x2="700" y2="154" stroke="#334155" stroke-width="1" />
						<polyline points={projectionLinePoints} fill="none" stroke="#fcd34d" stroke-width="2.5" stroke-dasharray="7 5" />
						{#each chartPoints as point, index (point.period)}
							{@const x = chartX(index)}
							{@const height = Number(point.distance) === 0 ? 0 : Math.max(2, Number(point.distance) / chartMaximum * 112)}
							{#if point.kind === 'actual'}
								<rect x={x - 11} y={154 - height} width="22" height={height} rx="4" fill="#67e8f9" opacity="0.9"><title>{point.accessibleLabel}: {formatMeasurement(point.distance, distanceTrend.unit, $numberLocale)} {distanceTrend.unit} — {$t("Recorded distance")}</title></rect>
							{:else}
								<circle cx={x} cy={chartY(point.distance)} r="4.5" fill="#0f172a" stroke="#fcd34d" stroke-width="2.5"><title>{point.accessibleLabel}: {formatMeasurement(point.distance, distanceTrend.unit, $numberLocale)} {distanceTrend.unit} — {$t("Projected distance")}</title></circle>
							{/if}
							<text x={x} y="176" text-anchor="middle" fill={point.kind === 'projection' ? '#fcd34d' : '#94a3b8'} font-size="10">{point.label}</text>
						{/each}
					</svg>
					<ul id="vehicle-distance-chart-data" class="sr-only" aria-label={$t("Distance chart data")}>
						{#each chartPoints as point (point.period)}<li>{point.accessibleLabel}: {$t(point.kind === 'actual' ? 'Recorded distance' : 'Projected distance')}, {formatMeasurement(point.distance, distanceTrend.unit, $numberLocale)} {distanceTrend.unit}</li>{/each}
					</ul>
				</div>
			{/if}
		</section>

		<div class="mt-8 grid gap-8 xl:grid-cols-[minmax(18rem,0.72fr)_minmax(0,1.28fr)]">
			{#if canCreate}
				<section class="h-fit rounded-2xl border border-line bg-surface p-5 shadow-xs sm:p-7" aria-labelledby="new-odometer-heading">
					<p class="text-sm font-medium text-accent-ink">{$t("Odometer")}</p><h2 id="new-odometer-heading" class="mt-1 text-xl font-semibold text-ink">{$t("Record mileage.")}</h2><p class="mt-2 text-sm leading-6 text-ink-muted">{$t("Out-of-order dates are accepted when the mileage stays between adjacent readings.")}</p>
					<Modal bind:open={odometerModalOpen} title={$t('Record mileage.')}>
					<form class="mt-5 space-y-4" onsubmit={saveOdometer}>
						<label class="block text-sm font-medium text-ink-secondary">{$t("Reading date")}<input bind:value={readingDate} type="date" required class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /></label>
						<label class="block text-sm font-medium text-ink-secondary">{$t("Odometer value")} <span class="font-normal text-ink-subtle">({vehicle.odometer_unit})</span><input bind:value={readingValue} required inputmode="decimal" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none placeholder:text-ink-subtle focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={formatDecimalInput('123456.7', $numberLocale)} /></label>
						<label class="block text-sm font-medium text-ink-secondary">{$t("Note")} <span class="font-normal text-ink-subtle">{$t("(optional)")}</span><textarea bind:value={readingNote} maxlength="2000" rows="2" class="mt-2 w-full rounded-[10px] border border-line-strong bg-page px-3 py-2.5 text-sm leading-6 text-ink outline-none placeholder:text-ink-subtle focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("Context for this reading")}></textarea></label>
						<button type="submit" disabled={saving} class="min-h-11 w-full rounded-xl bg-accent px-5 text-sm font-semibold text-on-accent hover:bg-accent/90 disabled:opacity-50">{$t(saving ? 'Saving…' : 'Save odometer reading')}</button>
					</form>
					</Modal>
				</section>
			{/if}

			<section aria-labelledby="odometer-history-heading">
				<div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-sm font-medium text-accent-ink">{$t("Odometer history")}</p><h2 id="odometer-history-heading" class="mt-1 text-xl font-semibold text-ink">{$t("Chronological timeline")}</h2></div><span class="text-xs text-ink-subtle">{odometerReadings.length} {$t("recent readings")}</span></div>
				{#if odometerReadings.length === 0}<div class="mt-4 rounded-2xl border border-line bg-surface p-6"><h3 class="font-semibold text-ink">{$t("No odometer readings yet.")}</h3><p class="mt-2 text-sm leading-6 text-ink-muted">{$t("Record the current odometer to begin the vehicle timeline.")}</p></div>
				{:else}<ol class="mt-4 space-y-3">{#each [...odometerReadings].reverse() as reading (reading.id)}<li class="rounded-2xl border border-line bg-surface p-4 sm:p-5"><div class="flex flex-wrap items-start justify-between gap-4"><div><time datetime={reading.reading_date} class="font-semibold text-ink">{formatDate(reading.reading_date)}</time><p class="mt-2 text-2xl font-semibold tracking-tight text-ink">{formatMeasurement(reading.value, reading.unit)} <span class="text-sm font-medium text-ink-muted">{reading.unit}</span></p>{#if reading.note}<p class="mt-2 text-sm text-ink-muted">{reading.note}</p>{/if}</div><div class="min-w-36 text-left sm:text-right"><span class="block text-xs text-ink-subtle">{$t("Since previous")}</span><span class="mt-1 block text-base font-semibold text-accent-ink">{formatMeasurement(reading.distance_since_previous, reading.unit)} {reading.distance_since_previous === null ? '' : reading.unit}</span>{#if reading.days_since_previous !== null}<span class="mt-1 block text-xs text-ink-subtle">{$t('{count} days since the previous reading', { count: reading.days_since_previous })}</span>{/if}</div></div>
							<div class="mt-4 flex flex-wrap gap-3 border-t border-line pt-3">{#if reading.can_edit}<button type="button" onclick={() => beginCorrection(reading)} class="rounded-lg px-2 py-1 text-sm font-medium text-accent-ink hover:bg-accent/10">{$t("Correct reading")}</button><button type="button" onclick={() => { deleteTarget = { id: reading.id, type: 'odometer' }; deleteOpen = true; }} class="rounded-lg px-2 py-1 text-sm font-medium text-danger hover:bg-danger/10">{$t("Delete")}</button>{/if}{#if reading.revision_count > 0}<button type="button" onclick={() => void loadRevisions(reading.id)} class="rounded-lg px-2 py-1 text-sm font-medium text-ink-secondary hover:bg-surface-subtle">{$t(revisionsByReading[reading.id]?.length ? 'Hide correction history' : 'Correction history')}</button>{/if}</div>
							{#if editingReadingId === reading.id}<Modal bind:open={correctionModalOpen} title={$t("Correct odometer reading")}><form class="mt-4 grid gap-3 rounded-xl border border-accent-ink/20 bg-page p-4 sm:grid-cols-2" onsubmit={saveCorrection}><label class="block text-sm font-medium text-ink-secondary">{$t("Corrected date")}<input bind:value={correctionDate} type="date" required class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-surface px-3 text-sm text-ink max-sm:text-base" /></label><label class="block text-sm font-medium text-ink-secondary">{$t("Corrected value (")}{reading.unit})<input bind:value={correctionValue} required inputmode="decimal" class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-surface px-3 text-sm text-ink max-sm:text-base" /></label><label class="block text-sm font-medium text-ink-secondary sm:col-span-2">{$t("Note")}<textarea bind:value={correctionNote} maxlength="2000" rows="2" class="mt-2 w-full rounded-[10px] border border-line-strong bg-surface px-3 py-2 text-sm text-ink max-sm:text-base"></textarea></label><label class="block text-sm font-medium text-ink-secondary sm:col-span-2">{$t("Reason for correction")} <span class="font-normal text-ink-subtle">{$t("(optional)")}</span><input bind:value={correctionReason} maxlength="500" class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-surface px-3 text-sm text-ink max-sm:text-base" placeholder={$t("Explain why the original reading changed")} /></label><div class="flex flex-wrap gap-2 sm:col-span-2"><button type="submit" disabled={correcting} class="min-h-10 rounded-lg bg-accent px-4 text-sm font-semibold text-on-accent disabled:opacity-50">{$t(correcting ? 'Saving…' : 'Save correction')}</button><button type="button" onclick={() => { editingReadingId = null; correctionModalOpen = false; }} class="min-h-10 rounded-lg border border-line-strong px-4 text-sm text-ink-secondary">{$t("Cancel")}</button></div></form></Modal>{/if}
							{#if revisionsByReading[reading.id]?.length}<ol class="mt-3 space-y-2 rounded-xl bg-page p-3" aria-label={$t("Odometer correction history")}>{#each revisionsByReading[reading.id] as revision (revision.id)}<li class="border-l-2 border-line-strong pl-3 text-sm"><p class="font-medium text-ink-secondary">{formatDate(revision.old_reading_date)}: {formatMeasurement(revision.old_value, reading.unit)} → {formatDate(revision.new_reading_date)}: {formatMeasurement(revision.new_value, reading.unit)} {reading.unit}</p>{#if revision.reason}<p class="mt-1 text-ink-muted">{revision.reason}</p>{/if}<time class="mt-1 block text-xs text-ink-subtle">{formatDateTime(revision.changed_at)}</time></li>{/each}</ol>{:else if revisionsLoading === reading.id}<p role="status" class="mt-3 text-xs text-ink-subtle">{$t("Loading correction history…")}</p>{/if}
				</li>{/each}</ol>{/if}
				{#if hasMoreOdometerReadings}<div class="mt-4 flex justify-center"><button type="button" onclick={() => void loadMoreOdometerReadings()} disabled={loadingMoreOdometerReadings} aria-live="polite" class="min-h-11 rounded-xl border border-line-strong px-5 text-sm font-semibold text-ink-secondary transition hover:border-accent-ink hover:text-accent-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink disabled:cursor-wait disabled:opacity-50">{$t(loadingMoreOdometerReadings ? 'Loading…' : 'Load older odometer readings')}</button></div>{/if}
			</section>
		</div>

		<div class="mt-8 grid gap-8 xl:grid-cols-3">
			<section aria-labelledby="maintenance-heading"><div><p class="text-sm font-medium text-accent-ink">{$t("Service log")}</p><h2 id="maintenance-heading" class="mt-1 text-xl font-semibold text-ink">{$t("Maintenance")}</h2></div>{#if canCreate}<Modal bind:open={maintenanceModalOpen} size="fullscreen" title={$t("Add service record")}>{#if error}<Notice tone="error" message={error} class="mb-5" />{/if}<form id="vehicle-service-form" class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4" onsubmit={saveMaintenance}><label class="block text-sm font-medium text-ink-secondary">{$t("Service name")}<input bind:value={maintenanceTitle} required maxlength="160" class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" placeholder={$t("Annual service")} /></label><div class="grid grid-cols-2 gap-3"><label class="block text-sm font-medium text-ink-secondary">{$t("Type")}<select bind:value={maintenanceType} class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base"><option value="service">{$t("Service")}</option><option value="repair">{$t("Repair")}</option><option value="inspection">{$t("Inspection")}</option><option value="tires">{$t("Tires")}</option><option value="registration">{$t("Registration")}</option><option value="other">{$t("Other")}</option></select></label><label class="block text-sm font-medium text-ink-secondary">{$t("Date")}<input bind:value={maintenanceDate} type="date" required class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-2 text-sm text-ink max-sm:text-base" /></label><label class="block text-sm font-medium text-ink-secondary">{$t("Next due")} <span class="font-normal text-ink-subtle">{$t("(optional)")}</span><input bind:value={maintenanceNextDueDate} type="date" min={maintenanceDate} class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-2 text-sm text-ink max-sm:text-base" /></label></div><label class="block text-sm font-medium text-ink-secondary">{$t("Odometer")} <span class="font-normal text-ink-subtle">{$t("(optional,")} {vehicle.odometer_unit})</span><input bind:value={maintenanceOdometer} inputmode="decimal" class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" /></label><label class="block text-sm font-medium text-ink-secondary">{$t("Notes")}<textarea bind:value={maintenanceNote} maxlength="2000" rows="2" class="mt-2 w-full rounded-[10px] border border-line-strong bg-page px-3 py-2 text-sm text-ink max-sm:text-base"></textarea></label></form>				{#snippet footer()}
					<Button variant="secondary" type="button" onclick={() => (maintenanceModalOpen = false)}>{$t('Cancel')}</Button>
					<Button type="submit" form="vehicle-service-form" disabled={saving}>{$t("Save service record")}</Button>
				{/snippet}
			</Modal>{/if}<ol class="mt-4 space-y-3">{#each maintenanceRecords as record (record.id)}<li class="rounded-xl border border-line bg-surface p-4"><p class="font-semibold text-ink">{record.title}</p><p class="mt-1 text-xs capitalize text-ink-subtle">{$t(record.service_type)} · {formatDate(record.occurred_on)}{record.next_due_on ? ` · ${$t("Next due")} ${formatDate(record.next_due_on)}` : ""}</p>{#if record.odometer_value}<p class="mt-2 text-sm text-ink-secondary">{formatMeasurement(record.odometer_value, record.odometer_unit)} {record.odometer_unit}</p>{/if}{#if record.note}<p class="mt-2 text-sm text-ink-muted">{record.note}</p>{/if}{#if record.can_edit}<button type="button" onclick={() => { deleteTarget = { id: record.id, type: 'maintenance' }; deleteOpen = true; }} class="mt-3 rounded-lg px-2 py-1 text-sm font-medium text-danger hover:bg-danger/10">{$t("Delete")}</button>{/if}</li>{:else}<li class="rounded-xl border border-line bg-surface p-4 text-sm text-ink-muted">{$t("No maintenance records yet.")}</li>{/each}</ol>{#if moreHistory.maintenance}<div class="mt-3 flex justify-center"><Button type="button" size="small" variant="secondary" onclick={() => void loadMoreHistory('maintenance')} disabled={loadingHistory.maintenance}>{$t(loadingHistory.maintenance ? 'Loading…' : 'Load older vehicle records')}</Button></div>{/if}</section>

			<section aria-labelledby="insurance-heading"><div><p class="text-sm font-medium text-accent-ink">{$t("Coverage")}</p><h2 id="insurance-heading" class="mt-1 text-xl font-semibold text-ink">{$t("Insurance")}</h2></div>{#if canCreate}<Modal bind:open={insuranceModalOpen} size="fullscreen" title={$t("Add insurance policy")}>{#if error}<Notice tone="error" message={error} class="mb-5" />{/if}<form id="vehicle-insurance-form" class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4" onsubmit={saveInsurance}><label class="block text-sm font-medium text-ink-secondary">{$t("Provider")}<input bind:value={insuranceProvider} required maxlength="120" class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" placeholder={$t("Insurance provider")} /></label><label class="block text-sm font-medium text-ink-secondary">{$t("Coverage")}<select bind:value={insuranceCoverage} class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base"><option value="liability">{$t("Liability")}</option><option value="partial">{$t("Partial cover")}</option><option value="comprehensive">{$t("Comprehensive")}</option><option value="other">{$t("Other")}</option></select></label><div class="grid grid-cols-2 gap-3"><label class="block text-sm font-medium text-ink-secondary">{$t("Valid from")}<input bind:value={insuranceFrom} type="date" required class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-2 text-sm text-ink max-sm:text-base" /></label><label class="block text-sm font-medium text-ink-secondary">{$t("Valid until")}<input bind:value={insuranceUntil} type="date" required class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-2 text-sm text-ink max-sm:text-base" /></label></div><label class="block text-sm font-medium text-ink-secondary">{$t("Notes")}<textarea bind:value={insuranceNote} maxlength="2000" rows="2" class="mt-2 w-full rounded-[10px] border border-line-strong bg-page px-3 py-2 text-sm text-ink max-sm:text-base"></textarea></label></form>				{#snippet footer()}
					<Button variant="secondary" type="button" onclick={() => (insuranceModalOpen = false)}>{$t('Cancel')}</Button>
					<Button type="submit" form="vehicle-insurance-form" disabled={saving}>{$t("Save policy dates")}</Button>
				{/snippet}
			</Modal>{/if}<ol class="mt-4 space-y-3">{#each insurancePolicies as policy (policy.id)}<li class="rounded-xl border border-line bg-surface p-4"><div class="flex flex-wrap items-start justify-between gap-2"><p class="font-semibold text-ink">{policy.provider}</p><span class="rounded-full border border-line-strong px-2 py-0.5 text-xs capitalize text-ink-muted">{policy.status}</span></div><p class="mt-1 text-xs capitalize text-ink-subtle">{policy.coverage_type} · {formatDate(policy.valid_from)} — {formatDate(policy.valid_until)}</p>{#if policy.note}<p class="mt-2 text-sm text-ink-muted">{policy.note}</p>{/if}{#if policy.can_edit}<button type="button" onclick={() => { deleteTarget = { id: policy.id, type: 'insurance' }; deleteOpen = true; }} class="mt-3 rounded-lg px-2 py-1 text-sm font-medium text-danger hover:bg-danger/10">{$t("Delete")}</button>{/if}</li>{:else}<li class="rounded-xl border border-line bg-surface p-4 text-sm text-ink-muted">{$t("No insurance records yet.")}</li>{/each}</ol>{#if moreHistory.insurance}<div class="mt-3 flex justify-center"><Button type="button" size="small" variant="secondary" onclick={() => void loadMoreHistory('insurance')} disabled={loadingHistory.insurance}>{$t(loadingHistory.insurance ? 'Loading…' : 'Load older vehicle records')}</Button></div>{/if}</section>

			<section aria-labelledby="costs-heading"><div><p class="text-sm font-medium text-accent-ink">{$t("Ownership")}</p><h2 id="costs-heading" class="mt-1 text-xl font-semibold text-ink">{$t("Costs")}</h2></div>{#if canCreate}<Modal bind:open={costModalOpen} size="fullscreen" title={$t("Add vehicle cost")}>{#if error}<Notice tone="error" message={error} class="mb-5" />{/if}<form id="vehicle-cost-form" class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4" onsubmit={saveCost}><label class="block text-sm font-medium text-ink-secondary">{$t("Category")}<select bind:value={costCategory} class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base">{#each vehicleCostCategories as category}<option value={category.value}>{$t(category.label)}</option>{/each}</select></label>{#if costCategory === 'fuel'}<fieldset class="rounded-lg border border-line bg-page p-3"><legend class="px-1 text-sm font-medium text-ink-secondary">{$t("Fuel details")}</legend><div class="grid gap-3 sm:grid-cols-3"><label class="block text-sm text-ink-secondary">{$t("Fuel quantity (liters)")} <span class="text-ink-subtle">{$t("Optional")}</span><input bind:value={costFuelLiters} type="text" inputmode="decimal" class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" /></label><label class="block text-sm text-ink-secondary">{$t("Price per liter")} <span class="text-ink-subtle">{$t("Optional")}</span><input bind:value={costFuelUnitPrice} type="text" inputmode="decimal" class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" /></label><label class="block text-sm text-ink-secondary">{$t("Odometer reading")} <span class="text-ink-subtle">{$t("Optional")}</span><input bind:value={costFuelOdometer} type="text" inputmode="decimal" class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" /></label></div></fieldset>{/if}<div class="grid grid-cols-[1fr_6rem] gap-3"><label class="block text-sm font-medium text-ink-secondary">{$t("Amount")}<input bind:value={costAmount} required inputmode="decimal" class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" placeholder={formatDecimalInput('125.00', $numberLocale)} /></label><label class="block text-sm font-medium text-ink-secondary">{$t("Currency")}<input bind:value={costCurrency} required maxlength="3" class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-2 text-sm text-ink max-sm:text-base" placeholder={$t("EUR")} /></label></div><div class="grid grid-cols-2 gap-3"><label class="block text-sm font-medium text-ink-secondary">{$t("Period starts")}<input bind:value={costPeriodStart} type="date" required class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-2 text-sm text-ink max-sm:text-base" /></label><label class="block text-sm font-medium text-ink-secondary">{$t("Period")}<select bind:value={costPeriod} class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-2 text-sm text-ink max-sm:text-base">{#each vehiclePeriods as period}<option value={period.value}>{$t(period.label)}</option>{/each}</select></label></div>{#if costPeriod !== 'once'}<label class="block text-sm font-medium text-ink-secondary">{$t("Period ends")} <span class="font-normal text-ink-subtle">{$t("(optional)")}</span><input bind:value={costPeriodEnd} type="date" class="mt-2 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink max-sm:text-base" /></label>{/if}<label class="block text-sm font-medium text-ink-secondary">{$t("Description")}<textarea bind:value={costDescription} maxlength="500" rows="2" class="mt-2 w-full rounded-[10px] border border-line-strong bg-page px-3 py-2 text-sm text-ink max-sm:text-base" placeholder={$t("Service invoice, fuel stop…")}></textarea></label></form>				{#snippet footer()}
					<Button variant="secondary" type="button" onclick={() => (costModalOpen = false)}>{$t('Cancel')}</Button>
					<Button type="submit" form="vehicle-cost-form" disabled={saving}>{$t("Save cost")}</Button>
				{/snippet}
			</Modal>{/if}<ol class="mt-4 space-y-3">{#each costs as cost (cost.id)}<li class="rounded-xl border border-line bg-surface p-4"><div class="flex flex-wrap items-start justify-between gap-2"><p class="font-semibold capitalize text-ink">{cost.category}</p><span class="font-semibold text-accent-ink">{formatCurrency(cost.amount, cost.currency)}</span></div><p class="mt-1 text-xs capitalize text-ink-subtle">{$t(cost.period)} · {formatDate(cost.period_start)}{cost.period_end ? ` — ${formatDate(cost.period_end)}` : ''}</p>{#if cost.fuel_liters && cost.fuel_unit_price}<p class="mt-2 text-xs text-ink-muted">{formatMeasurement(cost.fuel_liters, "L")} {$t("liters")} × {formatCurrency(cost.fuel_unit_price, cost.currency)}/{$t("liter")}{#if cost.fuel_odometer_value} · {$t("Odometer reading")}: {formatMeasurement(cost.fuel_odometer_value, vehicle.odometer_unit)} {vehicle.odometer_unit}{/if}</p>{/if}{#if cost.description}<p class="mt-2 text-sm text-ink-muted">{cost.description}</p>{/if}{#if cost.can_edit}<button type="button" onclick={() => { deleteTarget = { id: cost.id, type: 'costs' }; deleteOpen = true; }} class="mt-3 rounded-lg px-2 py-1 text-sm font-medium text-danger hover:bg-danger/10">{$t("Delete")}</button>{/if}</li>{:else}<li class="rounded-xl border border-line bg-surface p-4 text-sm text-ink-muted">{$t("No vehicle costs yet.")}</li>{/each}</ol>{#if moreHistory.costs}<div class="mt-3 flex justify-center"><Button type="button" size="small" variant="secondary" onclick={() => void loadMoreHistory('costs')} disabled={loadingHistory.costs}>{$t(loadingHistory.costs ? 'Loading…' : 'Load older vehicle records')}</Button></div>{/if}</section>		</div>
	</section>
	{#if deleteTarget}<ConfirmModal bind:open={deleteOpen} title={$t("Delete vehicle record?")} message={$t(deleteTarget.type === 'odometer' ? 'Delete this odometer reading and its correction history? The timeline will be recalculated.' : 'Delete this vehicle record?')} busy={deleting} onConfirm={() => void deleteRecord()} />{/if}
{:else}
	<section class="mx-auto max-w-2xl rounded-2xl border border-danger/20 bg-danger/5 p-6 sm:p-8"><p class="text-sm font-medium text-danger">{$t("Vehicle unavailable")}</p><h1 class="mt-2 text-2xl font-semibold tracking-tight text-ink">{$t("This vehicle could not be opened.")}</h1><p class="mt-3 text-sm leading-6 text-ink-muted">{$t("It may have been removed, Vehicles may be disabled, or you may not have a record-level grant.")}</p><a href="/modules/vehicles" class="mt-6 inline-flex rounded-xl border border-line-strong px-4 py-2.5 text-sm font-semibold text-ink-secondary hover:border-accent-ink">{$t("Back to Vehicles")}</a></section>
{/if}
