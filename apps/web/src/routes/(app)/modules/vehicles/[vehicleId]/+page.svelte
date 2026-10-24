<script lang="ts">
	import { t } from '#lib/i18n';
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import Modal from '#lib/ui/Modal.svelte';
	import { formatMeasurement, formatCurrency } from '#lib/number-format';
	import { defaultCurrency, formatDate, formatDateTime } from '#lib/i18n';
	import { vehicleCostCategories, vehiclePeriods, type Vehicle, type VehicleCost, type VehicleInsurancePolicy, type VehicleMaintenanceRecord, type VehicleOdometerReading, type VehicleOdometerRevision, type VehicleResponse } from '#lib/vehicles';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const vehicleId = $derived(Number(page.params.vehicleId));
	const access = $derived(shell.modules.find((module) => module.key === 'vehicles'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreate = $derived(access?.capabilities.create ?? false);
	const canEdit = $derived(access?.capabilities.edit ?? false);
	const canManage = $derived(access?.capabilities.manage ?? false);

	let vehicle = $state<Vehicle | null>(null);
	let odometerReadings = $state<VehicleOdometerReading[]>([]);
	let maintenanceRecords = $state<VehicleMaintenanceRecord[]>([]);
	let insurancePolicies = $state<VehicleInsurancePolicy[]>([]);
	let costs = $state<VehicleCost[]>([]);
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
	let costPeriodStart = $state(localDate());
	let costPeriodEnd = $state('');
	let costPeriod = $state<'once' | 'monthly' | 'quarterly' | 'yearly'>('once');
	let costDescription = $state('');

	const chartData = $derived(odometerReadings.filter((reading) => reading.distance_since_previous !== null).slice(-12));
	const chartMaximum = $derived(Math.max(1, ...chartData.map((reading) => Number(reading.distance_since_previous ?? 0))));
	const latestReading = $derived(odometerReadings.at(-1) ?? null);
	const activeInsuranceCount = $derived(insurancePolicies.filter((policy) => policy.status === 'active').length);

	$effect(() => {
		if (!shell.loading) void loadVehicle();
	});

	function localDate(): string {
		const date = new Date();
		return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
	}

	async function loadVehicle(): Promise<void> {
		loading = true; error = '';
		if (!canView || !Number.isInteger(vehicleId) || vehicleId < 1) {
			vehicle = null; odometerReadings = []; maintenanceRecords = []; insurancePolicies = []; costs = []; loading = false; return;
		}
		try {
			const response = await fetch(`/api/vehicles/${vehicleId}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as VehicleResponse;
			if (!response.ok || !result.vehicle) throw new Error(result.message ?? 'This vehicle is unavailable or you do not have access.');
			vehicle = result.vehicle;
			odometerReadings = result.odometer_readings ?? [];
			maintenanceRecords = result.maintenance_records ?? [];
			insurancePolicies = result.insurance_policies ?? [];
			costs = result.costs ?? [];
			vehicleName = vehicle.name; make = vehicle.make; model = vehicle.model;
			modelYear = vehicle.model_year === null ? '' : String(vehicle.model_year);
			fuelType = vehicle.fuel_type; odometerUnit = vehicle.odometer_unit; description = vehicle.description ?? '';
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The vehicle could not be loaded. Check your connection and try again.';
		} finally { loading = false; }
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
				body: JSON.stringify({ reading_date: readingDate, value: readingValue, note: readingNote })
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
		editingReadingId = reading.id; correctionDate = reading.reading_date; correctionValue = reading.value;
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
				body: JSON.stringify({ reading_date: correctionDate, value: correctionValue, note: correctionNote, reason: correctionReason || null })
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
		const payload = { title: maintenanceTitle, service_type: maintenanceType, occurred_on: maintenanceDate, next_due_on: maintenanceNextDueDate || null, odometer_value: maintenanceOdometer || null, note: maintenanceNote };
		await createRecord('maintenance', payload, () => { maintenanceTitle = ''; maintenanceOdometer = ''; maintenanceNote = ''; maintenanceDate = localDate(); maintenanceNextDueDate = ''; });
	}

	async function saveInsurance(event: SubmitEvent): Promise<void> {
		event.preventDefault(); if (!vehicle || !canCreate) return;
		const payload = { provider: insuranceProvider, coverage_type: insuranceCoverage, valid_from: insuranceFrom, valid_until: insuranceUntil, note: insuranceNote };
		await createRecord('insurance', payload, () => { insuranceProvider = ''; insuranceUntil = ''; insuranceNote = ''; insuranceFrom = localDate(); });
	}

	async function saveCost(event: SubmitEvent): Promise<void> {
		event.preventDefault(); if (!vehicle || !canCreate) return;
		const payload = { category: costCategory, amount: costAmount, currency: costCurrency, period_start: costPeriodStart, period_end: costPeriodEnd || null, period: costPeriod, description: costDescription };
		await createRecord('costs', payload, () => { costAmount = ''; costDescription = ''; costPeriodStart = localDate(); costPeriodEnd = ''; costPeriod = 'once'; });
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
	<div role="status" class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 text-sm text-slate-300">{$t("Loading vehicle…")}</div>
{:else if !canView}
	<section class="mx-auto max-w-2xl rounded-2xl border border-amber-300/20 bg-amber-300/5 p-6 sm:p-8"><p class="text-sm font-medium text-amber-200">{$t("Vehicles View access is required.")}</p><h1 class="mt-2 text-2xl font-semibold text-white">{$t("This vehicle is private to its authorized members.")}</h1><p class="mt-3 text-sm leading-6 text-slate-400">{$t("An administrator can enable Vehicles and grant the exact module capability. Private vehicle records still require an explicit record grant.")}</p><a href="/modules/vehicles" class="mt-6 inline-flex rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-200 hover:border-cyan-300">{$t("Back to Vehicles")}</a></section>
{:else if vehicle}
	<section class="mx-auto max-w-screen-xl">
		<a href="/modules/vehicles" class="inline-flex items-center gap-2 text-sm font-medium text-cyan-200 hover:text-cyan-100 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-cyan-300"><span aria-hidden="true">←</span> {$t("All vehicles")}</a>
		<div class="mt-5 flex flex-wrap items-end justify-between gap-4 border-b border-slate-800 pb-6">
			<div><p class="text-sm font-medium tracking-wide text-cyan-200">{vehicle.make} {vehicle.model}{vehicle.model_year ? ` · ${vehicle.model_year}` : ''} · {vehicle.visibility}</p><h1 class="mt-2 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{vehicle.name}</h1><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">{vehicle.description ?? `${vehicle.fuel_type} · ${vehicle.odometer_unit} odometer`}</p></div>
			<div class="grid grid-cols-2 gap-3 text-right"><div class="rounded-xl border border-slate-800 bg-slate-900/60 px-4 py-3"><span class="block text-xs uppercase tracking-wide text-slate-500">{$t("Latest odometer")}</span><span class="mt-1 block text-lg font-semibold text-white">{formatMeasurement(latestReading?.value, vehicle.odometer_unit)} <span class="text-sm font-medium text-slate-400">{vehicle.odometer_unit}</span></span></div><div class="rounded-xl border border-slate-800 bg-slate-900/60 px-4 py-3"><span class="block text-xs uppercase tracking-wide text-slate-500">{$t("Active cover")}</span><span class="mt-1 block text-lg font-semibold text-white">{activeInsuranceCount}</span></div></div>
		</div>
		{#if message}<p role="status" class="mt-5 rounded-xl border border-emerald-300/30 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">{$t(message)}</p>{/if}
		{#if error}<p role="alert" class="mt-5 rounded-xl border border-rose-300/30 bg-rose-300/10 px-4 py-3 text-sm text-rose-100">{$t(error)}</p>{/if}

		{#if canManage}
			<button type="button" onclick={() => (vehicleModalOpen = true)} class="mt-6 min-h-10 rounded-lg border border-slate-700 px-4 text-sm font-semibold text-slate-200">{$t('Edit vehicle')}</button>
			<Modal bind:open={vehicleModalOpen} title={$t('Edit vehicle')}>
			<form class="mt-6 grid gap-4 rounded-2xl border border-slate-800 bg-slate-900/50 p-5 sm:grid-cols-2 xl:grid-cols-4" onsubmit={saveVehicle}>
				<label class="block text-sm font-medium text-slate-200">{$t("Display name")}<input bind:value={vehicleName} required maxlength="120" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
				<label class="block text-sm font-medium text-slate-200">{$t("Make")}<input bind:value={make} required maxlength="80" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
				<label class="block text-sm font-medium text-slate-200">{$t("Model")}<input bind:value={model} required maxlength="80" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
				<label class="block text-sm font-medium text-slate-200">{$t("Model year")}<input bind:value={modelYear} type="number" min="1886" max="2200" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
				<label class="block text-sm font-medium text-slate-200">{$t("Fuel type")}<select bind:value={fuelType} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"><option value="petrol">{$t("Petrol")}</option><option value="diesel">{$t("Diesel")}</option><option value="hybrid">{$t("Hybrid")}</option><option value="electric">{$t("Electric")}</option><option value="lpg">{$t("LPG")}</option><option value="other">{$t("Other")}</option></select></label>
				<label class="block text-sm font-medium text-slate-200">{$t("Odometer unit")}<select bind:value={odometerUnit} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"><option value="km">km</option><option value="mi">mi</option></select></label>
				<label class="block text-sm font-medium text-slate-200 xl:col-span-2">{$t("Description")}<input bind:value={description} maxlength="2000" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
				<div class="flex justify-end xl:col-span-4"><button type="submit" disabled={saving} class="min-h-11 rounded-xl border border-slate-700 px-4 text-sm font-semibold text-slate-200 hover:border-cyan-300 disabled:opacity-50">{$t(saving ? 'Saving…' : 'Save vehicle details')}</button></div>
			</form>
			</Modal>
		{/if}

		<section class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/50 p-5 sm:p-7" aria-labelledby="odometer-trend-heading">
			<div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-sm font-medium text-cyan-200">{$t("Mileage trend")}</p><h2 id="odometer-trend-heading" class="mt-1 text-xl font-semibold text-slate-100">{$t("Distance between readings")}</h2></div><p class="text-xs text-slate-500">{$t("Latest")} {chartData.length} {$t("intervals ·")} {vehicle.odometer_unit}</p></div>
			{#if chartData.length === 0}
				<p class="mt-5 rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-5 text-sm text-slate-400">{$t("Add a second dated odometer reading to see the distance trend.")}</p>
			{:else}
				<div class="mt-5 overflow-x-auto"><svg class="h-48 min-w-[34rem] w-full" viewBox="0 0 720 190" role="img" aria-label={$t("Bar chart of odometer distance between readings")}><line x1="30" y1="155" x2="700" y2="155" stroke="#334155" stroke-width="1" />{#each chartData as reading, index (reading.id)}{@const barWidth = Math.min(36, 560 / chartData.length)}{@const x = 48 + index * (620 / chartData.length)}{@const height = Math.max(3, Number(reading.distance_since_previous ?? 0) / chartMaximum * 118)}<rect x={x} y={155 - height} width={barWidth} height={height} rx="5" fill="#67e8f9" opacity="0.9"><title>{formatDate(reading.reading_date)}: {formatMeasurement(reading.distance_since_previous, vehicle.odometer_unit)} {vehicle.odometer_unit}</title></rect><text x={x + barWidth / 2} y="174" text-anchor="middle" fill="#94a3b8" font-size="10">{formatDate(reading.reading_date, { month: '2-digit', day: '2-digit' })}</text>{/each}</svg></div>
			{/if}
		</section>

		<div class="mt-8 grid gap-8 xl:grid-cols-[minmax(18rem,0.72fr)_minmax(0,1.28fr)]">
			{#if canCreate}
				<section class="h-fit rounded-2xl border border-slate-800 bg-slate-900/60 p-5 shadow-xl shadow-black/10 sm:p-7" aria-labelledby="new-odometer-heading">
					<p class="text-sm font-medium text-cyan-200">{$t("Odometer")}</p><h2 id="new-odometer-heading" class="mt-1 text-xl font-semibold text-slate-100">{$t("Record mileage.")}</h2><p class="mt-2 text-sm leading-6 text-slate-400">{$t("Out-of-order dates are accepted when the mileage stays between adjacent readings.")}</p>
					<button type="button" onclick={() => (odometerModalOpen = true)} class="mt-4 min-h-10 rounded-lg bg-cyan-300 px-4 text-sm font-semibold text-slate-950">{$t('Record mileage')}</button>
					<Modal bind:open={odometerModalOpen} title={$t('Record mileage.')}>
					<form class="mt-5 space-y-4" onsubmit={saveOdometer}>
						<label class="block text-sm font-medium text-slate-200">{$t("Reading date")}<input bind:value={readingDate} type="date" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
						<label class="block text-sm font-medium text-slate-200">{$t("Odometer value")} <span class="font-normal text-slate-500">({vehicle.odometer_unit})</span><input bind:value={readingValue} required inputmode="decimal" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder="123456.7" /></label>
						<label class="block text-sm font-medium text-slate-200">{$t("Note")} <span class="font-normal text-slate-500">{$t("(optional)")}</span><textarea bind:value={readingNote} maxlength="2000" rows="2" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm leading-6 text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("Context for this reading")}></textarea></label>
						<button type="submit" disabled={saving} class="min-h-11 w-full rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 hover:bg-cyan-200 disabled:opacity-50">{$t(saving ? 'Saving…' : 'Save odometer reading')}</button>
					</form>
					</Modal>
				</section>
			{/if}

			<section aria-labelledby="odometer-history-heading">
				<div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-sm font-medium text-cyan-200">{$t("Odometer history")}</p><h2 id="odometer-history-heading" class="mt-1 text-xl font-semibold text-slate-100">{$t("Chronological timeline")}</h2></div><span class="text-xs text-slate-500">{odometerReadings.length} {$t("recent readings")}</span></div>
				{#if odometerReadings.length === 0}<div class="mt-4 rounded-2xl border border-slate-800 bg-slate-900/50 p-6"><h3 class="font-semibold text-slate-100">{$t("No odometer readings yet.")}</h3><p class="mt-2 text-sm leading-6 text-slate-400">{$t("Record the current odometer to begin the vehicle timeline.")}</p></div>
				{:else}<ol class="mt-4 space-y-3">{#each [...odometerReadings].reverse() as reading (reading.id)}<li class="rounded-2xl border border-slate-800 bg-slate-900/50 p-4 sm:p-5"><div class="flex flex-wrap items-start justify-between gap-4"><div><time datetime={reading.reading_date} class="font-semibold text-slate-100">{formatDate(reading.reading_date)}</time><p class="mt-2 text-2xl font-semibold tracking-tight text-white">{formatMeasurement(reading.value, reading.unit)} <span class="text-sm font-medium text-slate-400">{reading.unit}</span></p>{#if reading.note}<p class="mt-2 text-sm text-slate-400">{reading.note}</p>{/if}</div><div class="min-w-36 text-left sm:text-right"><span class="block text-xs uppercase tracking-wide text-slate-500">{$t("Since previous")}</span><span class="mt-1 block text-base font-semibold text-cyan-100">{formatMeasurement(reading.distance_since_previous, reading.unit)} {reading.distance_since_previous === null ? '' : reading.unit}</span>{#if reading.days_since_previous !== null}<span class="mt-1 block text-xs text-slate-500">{$t('{count} days since the previous reading', { count: reading.days_since_previous })}</span>{/if}</div></div>
							<div class="mt-4 flex flex-wrap gap-3 border-t border-slate-800 pt-3">{#if canEdit}<button type="button" onclick={() => beginCorrection(reading)} class="rounded-lg px-2 py-1 text-sm font-medium text-cyan-200 hover:bg-cyan-300/10">{$t("Correct reading")}</button>{/if}{#if reading.revision_count > 0}<button type="button" onclick={() => void loadRevisions(reading.id)} class="rounded-lg px-2 py-1 text-sm font-medium text-slate-300 hover:bg-slate-800">{$t(revisionsByReading[reading.id]?.length ? 'Hide correction history' : 'Correction history')}</button>{/if}</div>
							{#if editingReadingId === reading.id}<Modal bind:open={correctionModalOpen} title={$t("Correct odometer reading")}><form class="mt-4 grid gap-3 rounded-xl border border-cyan-300/20 bg-slate-950/60 p-4 sm:grid-cols-2" onsubmit={saveCorrection}><label class="block text-sm font-medium text-slate-200">{$t("Corrected date")}<input bind:value={correctionDate} type="date" required class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white" /></label><label class="block text-sm font-medium text-slate-200">{$t("Corrected value (")}{reading.unit})<input bind:value={correctionValue} required inputmode="decimal" class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white" /></label><label class="block text-sm font-medium text-slate-200 sm:col-span-2">{$t("Note")}<textarea bind:value={correctionNote} maxlength="2000" rows="2" class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white"></textarea></label><label class="block text-sm font-medium text-slate-200 sm:col-span-2">{$t("Reason for correction")} <span class="font-normal text-slate-500">{$t("(optional)")}</span><input bind:value={correctionReason} maxlength="500" class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white" placeholder={$t("Explain why the original reading changed")} /></label><div class="flex flex-wrap gap-2 sm:col-span-2"><button type="submit" disabled={correcting} class="min-h-10 rounded-lg bg-cyan-300 px-4 text-sm font-semibold text-slate-950 disabled:opacity-50">{$t(correcting ? 'Saving…' : 'Save correction')}</button><button type="button" onclick={() => { editingReadingId = null; correctionModalOpen = false; }} class="min-h-10 rounded-lg border border-slate-700 px-4 text-sm text-slate-300">{$t("Cancel")}</button></div></form></Modal>{/if}
							{#if revisionsByReading[reading.id]?.length}<ol class="mt-3 space-y-2 rounded-xl bg-slate-950/60 p-3" aria-label={$t("Odometer correction history")}>{#each revisionsByReading[reading.id] as revision (revision.id)}<li class="border-l-2 border-slate-700 pl-3 text-sm"><p class="font-medium text-slate-200">{revision.old_reading_date}: {formatMeasurement(revision.old_value, reading.unit)} → {revision.new_reading_date}: {formatMeasurement(revision.new_value, reading.unit)} {reading.unit}</p>{#if revision.reason}<p class="mt-1 text-slate-400">{revision.reason}</p>{/if}<time class="mt-1 block text-xs text-slate-500">{formatDateTime(revision.changed_at)}</time></li>{/each}</ol>{:else if revisionsLoading === reading.id}<p role="status" class="mt-3 text-xs text-slate-500">{$t("Loading correction history…")}</p>{/if}
						</li>{/each}</ol>{/if}
			</section>
		</div>

		<div class="mt-8 grid gap-8 xl:grid-cols-3">
			<section aria-labelledby="maintenance-heading"><div><p class="text-sm font-medium text-cyan-200">{$t("Service log")}</p><h2 id="maintenance-heading" class="mt-1 text-xl font-semibold text-slate-100">{$t("Maintenance")}</h2></div>{#if canCreate}<button type="button" onclick={() => (maintenanceModalOpen = true)} class="mt-4 min-h-10 rounded-lg bg-cyan-300 px-4 text-sm font-semibold text-slate-950">{$t("Add service record")}</button><Modal bind:open={maintenanceModalOpen} title={$t("Add service record")}><form class="mt-4 space-y-3 rounded-2xl border border-slate-800 bg-slate-900/50 p-4" onsubmit={saveMaintenance}><label class="block text-sm font-medium text-slate-200">{$t("Service name")}<input bind:value={maintenanceTitle} required maxlength="160" class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white" placeholder={$t("Annual service")} /></label><div class="grid grid-cols-2 gap-3"><label class="block text-sm font-medium text-slate-200">{$t("Type")}<select bind:value={maintenanceType} class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white"><option value="service">{$t("Service")}</option><option value="repair">{$t("Repair")}</option><option value="inspection">{$t("Inspection")}</option><option value="tires">{$t("Tires")}</option><option value="registration">{$t("Registration")}</option><option value="other">{$t("Other")}</option></select></label><label class="block text-sm font-medium text-slate-200">{$t("Date")}<input bind:value={maintenanceDate} type="date" required class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-2 text-sm text-white" /></label><label class="block text-sm font-medium text-slate-200">{$t("Next due")} <span class="font-normal text-slate-500">{$t("(optional)")}</span><input bind:value={maintenanceNextDueDate} type="date" min={maintenanceDate} class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-2 text-sm text-white" /></label></div><label class="block text-sm font-medium text-slate-200">{$t("Odometer")} <span class="font-normal text-slate-500">{$t("(optional,")} {vehicle.odometer_unit})</span><input bind:value={maintenanceOdometer} inputmode="decimal" class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white" /></label><label class="block text-sm font-medium text-slate-200">{$t("Notes")}<textarea bind:value={maintenanceNote} maxlength="2000" rows="2" class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"></textarea></label><button type="submit" disabled={saving} class="min-h-10 w-full rounded-lg bg-cyan-300 px-4 text-sm font-semibold text-slate-950 disabled:opacity-50">{$t("Save service record")}</button></form></Modal>{/if}<ol class="mt-4 space-y-3">{#each maintenanceRecords as record (record.id)}<li class="rounded-xl border border-slate-800 bg-slate-900/40 p-4"><p class="font-semibold text-slate-100">{record.title}</p><p class="mt-1 text-xs capitalize text-slate-500">{$t(record.service_type)} · {formatDate(record.occurred_on)}{record.next_due_on ? ` · ${$t("Next due")} ${formatDate(record.next_due_on)}` : ""}</p>{#if record.odometer_value}<p class="mt-2 text-sm text-slate-300">{formatMeasurement(record.odometer_value, record.odometer_unit)} {record.odometer_unit}</p>{/if}{#if record.note}<p class="mt-2 text-sm text-slate-400">{record.note}</p>{/if}</li>{:else}<li class="rounded-xl border border-slate-800 bg-slate-900/40 p-4 text-sm text-slate-400">{$t("No maintenance records yet.")}</li>{/each}</ol></section>

			<section aria-labelledby="insurance-heading"><div><p class="text-sm font-medium text-cyan-200">{$t("Coverage")}</p><h2 id="insurance-heading" class="mt-1 text-xl font-semibold text-slate-100">{$t("Insurance")}</h2></div>{#if canCreate}<button type="button" onclick={() => (insuranceModalOpen = true)} class="mt-4 min-h-10 rounded-lg bg-cyan-300 px-4 text-sm font-semibold text-slate-950">{$t("Add insurance policy")}</button><Modal bind:open={insuranceModalOpen} title={$t("Add insurance policy")}><form class="mt-4 space-y-3 rounded-2xl border border-slate-800 bg-slate-900/50 p-4" onsubmit={saveInsurance}><label class="block text-sm font-medium text-slate-200">{$t("Provider")}<input bind:value={insuranceProvider} required maxlength="120" class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white" placeholder={$t("Insurance provider")} /></label><label class="block text-sm font-medium text-slate-200">{$t("Coverage")}<select bind:value={insuranceCoverage} class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white"><option value="liability">{$t("Liability")}</option><option value="partial">{$t("Partial cover")}</option><option value="comprehensive">{$t("Comprehensive")}</option><option value="other">{$t("Other")}</option></select></label><div class="grid grid-cols-2 gap-3"><label class="block text-sm font-medium text-slate-200">{$t("Valid from")}<input bind:value={insuranceFrom} type="date" required class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-2 text-sm text-white" /></label><label class="block text-sm font-medium text-slate-200">{$t("Valid until")}<input bind:value={insuranceUntil} type="date" required class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-2 text-sm text-white" /></label></div><label class="block text-sm font-medium text-slate-200">{$t("Notes")}<textarea bind:value={insuranceNote} maxlength="2000" rows="2" class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"></textarea></label><button type="submit" disabled={saving} class="min-h-10 w-full rounded-lg bg-cyan-300 px-4 text-sm font-semibold text-slate-950 disabled:opacity-50">{$t("Save policy dates")}</button></form></Modal>{/if}<ol class="mt-4 space-y-3">{#each insurancePolicies as policy (policy.id)}<li class="rounded-xl border border-slate-800 bg-slate-900/40 p-4"><div class="flex flex-wrap items-start justify-between gap-2"><p class="font-semibold text-slate-100">{policy.provider}</p><span class="rounded-full border border-slate-700 px-2 py-0.5 text-xs capitalize text-slate-400">{policy.status}</span></div><p class="mt-1 text-xs capitalize text-slate-500">{policy.coverage_type} · {policy.valid_from} — {policy.valid_until}</p>{#if policy.note}<p class="mt-2 text-sm text-slate-400">{policy.note}</p>{/if}</li>{:else}<li class="rounded-xl border border-slate-800 bg-slate-900/40 p-4 text-sm text-slate-400">{$t("No insurance records yet.")}</li>{/each}</ol></section>

			<section aria-labelledby="costs-heading"><div><p class="text-sm font-medium text-cyan-200">{$t("Ownership")}</p><h2 id="costs-heading" class="mt-1 text-xl font-semibold text-slate-100">{$t("Costs")}</h2></div>{#if canCreate}<button type="button" onclick={() => (costModalOpen = true)} class="mt-4 min-h-10 rounded-lg bg-cyan-300 px-4 text-sm font-semibold text-slate-950">{$t("Add vehicle cost")}</button><Modal bind:open={costModalOpen} title={$t("Add vehicle cost")}><form class="mt-4 space-y-3 rounded-2xl border border-slate-800 bg-slate-900/50 p-4" onsubmit={saveCost}><label class="block text-sm font-medium text-slate-200">{$t("Category")}<select bind:value={costCategory} class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white">{#each vehicleCostCategories as category}<option value={category.value}>{category.label}</option>{/each}</select></label><div class="grid grid-cols-[1fr_6rem] gap-3"><label class="block text-sm font-medium text-slate-200">{$t("Amount")}<input bind:value={costAmount} required inputmode="decimal" class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white" placeholder="125.00" /></label><label class="block text-sm font-medium text-slate-200">{$t("Currency")}<input bind:value={costCurrency} required maxlength="3" class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-2 text-sm uppercase text-white" placeholder={$t("EUR")} /></label></div><div class="grid grid-cols-2 gap-3"><label class="block text-sm font-medium text-slate-200">{$t("Period starts")}<input bind:value={costPeriodStart} type="date" required class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-2 text-sm text-white" /></label><label class="block text-sm font-medium text-slate-200">{$t("Period")}<select bind:value={costPeriod} class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-2 text-sm text-white">{#each vehiclePeriods as period}<option value={period.value}>{period.label}</option>{/each}</select></label></div>{#if costPeriod !== 'once'}<label class="block text-sm font-medium text-slate-200">{$t("Period ends")} <span class="font-normal text-slate-500">{$t("(optional)")}</span><input bind:value={costPeriodEnd} type="date" class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white" /></label>{/if}<label class="block text-sm font-medium text-slate-200">{$t("Description")}<textarea bind:value={costDescription} maxlength="500" rows="2" class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white" placeholder={$t("Service invoice, fuel stop…")}></textarea></label><button type="submit" disabled={saving} class="min-h-10 w-full rounded-lg bg-cyan-300 px-4 text-sm font-semibold text-slate-950 disabled:opacity-50">{$t("Save cost")}</button></form></Modal>{/if}<ol class="mt-4 space-y-3">{#each costs as cost (cost.id)}<li class="rounded-xl border border-slate-800 bg-slate-900/40 p-4"><div class="flex flex-wrap items-start justify-between gap-2"><p class="font-semibold capitalize text-slate-100">{cost.category}</p><span class="font-semibold text-cyan-100">{formatCurrency(cost.amount, cost.currency)}</span></div><p class="mt-1 text-xs capitalize text-slate-500">{$t(cost.period)} · {formatDate(cost.period_start)}{cost.period_end ? ` — ${formatDate(cost.period_end)}` : ''}</p>{#if cost.description}<p class="mt-2 text-sm text-slate-400">{cost.description}</p>{/if}</li>{:else}<li class="rounded-xl border border-slate-800 bg-slate-900/40 p-4 text-sm text-slate-400">{$t("No vehicle costs yet.")}</li>{/each}</ol></section>
		</div>
	</section>
{:else}
	<section class="mx-auto max-w-2xl rounded-2xl border border-rose-300/20 bg-rose-300/5 p-6 sm:p-8"><p class="text-sm font-medium text-rose-200">{$t("Vehicle unavailable")}</p><h1 class="mt-2 text-2xl font-semibold tracking-tight text-white">{$t("This vehicle could not be opened.")}</h1><p class="mt-3 text-sm leading-6 text-slate-400">{$t("It may have been removed, Vehicles may be disabled, or you may not have a record-level grant.")}</p><a href="/modules/vehicles" class="mt-6 inline-flex rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-200 hover:border-cyan-300">{$t("Back to Vehicles")}</a></section>
{/if}
