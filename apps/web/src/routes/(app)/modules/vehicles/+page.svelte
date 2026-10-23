<script lang="ts">
	import { t } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { vehicleFuelTypes, type Vehicle, type VehicleResponse, type VehicleVisibility } from '#lib/vehicles';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'vehicles'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreatePrivate = $derived(access?.capabilities.create ?? false);
	const canCreateShared = $derived(shell.currentUser?.is_admin === true && (access?.capabilities.manage ?? false));

	let vehicles = $state<Vehicle[]>([]);
	let loading = $state(true);
	let saving = $state(false);
	let error = $state('');
	let message = $state('');
	let name = $state('');
	let make = $state('');
	let model = $state('');
	let modelYear = $state('');
	let fuelType = $state<'petrol' | 'diesel' | 'hybrid' | 'electric' | 'lpg' | 'other'>('petrol');
	let odometerUnit = $state<'km' | 'mi'>('km');
	let visibility = $state<VehicleVisibility>('private');
	let description = $state('');

	$effect(() => {
		if (!canCreatePrivate && canCreateShared) visibility = 'household';
	});

	$effect(() => {
		if (!shell.loading) void loadVehicles();
	});

	async function loadVehicles(): Promise<void> {
		loading = true;
		error = '';
		if (!canView) { vehicles = []; loading = false; return; }
		try {
			const response = await fetch('/api/vehicles', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as VehicleResponse;
			if (!response.ok) throw new Error(result.message ?? 'Vehicles are unavailable. Check your access and try again.');
			vehicles = result.vehicles ?? [];
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Vehicles are unavailable. Check your connection and try again.';
		} finally { loading = false; }
	}

	async function createVehicle(event: SubmitEvent): Promise<void> {
		event.preventDefault(); message = ''; error = '';
		if ((visibility === 'private' && !canCreatePrivate) || (visibility === 'household' && !canCreateShared)) {
			error = 'Your account does not have permission to create this vehicle.'; return;
		}
		const token = csrfToken();
		if (!token) { error = 'Reload this page and try again.'; return; }
		saving = true;
		try {
			const response = await fetch('/api/vehicles', {
				method: 'POST', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ name, make, model, model_year: modelYear === '' ? null : Number(modelYear), fuel_type: fuelType, odometer_unit: odometerUnit, visibility, description })
			});
			const result = (await response.json()) as VehicleResponse & ApiResponse;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok || !result.vehicle) { error = firstError(result) || result.message || 'The vehicle could not be created.'; return; }
			name = ''; make = ''; model = ''; modelYear = ''; description = '';
			message = visibility === 'household' ? 'Household vehicle created.' : 'Private vehicle created.';
			if (canView) { await goto(`/modules/vehicles/${result.vehicle.id}`); return; }
			await loadVehicles();
		} catch {
			error = 'The vehicle could not be created. Check your connection and try again.';
		} finally { saving = false; }
	}
</script>

<svelte:head>
	<title>{$t("Vehicles")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Keep private or shared vehicle, mileage, service and cost records.")} />
</svelte:head>

<section class="mx-auto max-w-screen-xl">
	<div class="flex flex-wrap items-end justify-between gap-4 border-b border-slate-800 pb-6">
		<div><p class="text-sm font-medium tracking-wide text-cyan-200">{$t("Vehicles module")}</p><h1 class="mt-2 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{$t("Every mile, in one place.")}</h1></div>
		<p class="max-w-xl text-sm leading-6 text-slate-400">{$t("Track odometer history, service, insurance dates and running costs. Keep a vehicle private or share it with the household.")}</p>
	</div>
	{#if message}<p role="status" class="mt-6 rounded-xl border border-emerald-300/30 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">{$t(message)}</p>{/if}
	{#if error}<p role="alert" class="mt-6 rounded-xl border border-rose-300/30 bg-rose-300/10 px-4 py-3 text-sm text-rose-100">{$t(error)}</p>{/if}

	{#if canView}
		<section class="mt-8" aria-labelledby="vehicles-heading">
			<div class="flex flex-wrap items-center justify-between gap-3"><div><h2 id="vehicles-heading" class="text-xl font-semibold text-slate-100">{$t("Your vehicles")}</h2><p class="mt-1 text-sm text-slate-400">{$t("Private records are visible to their owner and explicitly authorized members.")}</p></div><button type="button" onclick={() => void loadVehicles()} disabled={loading} class="rounded-lg border border-slate-700 px-3 py-2 text-sm font-medium text-slate-200 hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:opacity-50">{$t("Refresh")}</button></div>
			{#if loading}
				<p role="status" class="mt-5 rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-5 text-sm text-slate-300">{$t("Loading vehicles…")}</p>
			{:else if vehicles.length === 0}
				<div class="mt-5 rounded-2xl border border-slate-800 bg-slate-900/50 p-6 sm:p-8"><h3 class="text-lg font-semibold text-slate-100">{$t("No vehicles yet.")}</h3><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">{$t("Add a private vehicle for your own records, or ask an active administrator to create a shared household vehicle.")}</p></div>
			{:else}
				<ul class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
					{#each vehicles as vehicle (vehicle.id)}
						<li><a href={`/modules/vehicles/${vehicle.id}`} class="group flex min-h-40 flex-col justify-between rounded-2xl border border-slate-800 bg-slate-900/50 p-5 transition hover:border-cyan-300/50 hover:bg-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">
							<span><span class="flex flex-wrap items-start justify-between gap-2"><span class="font-semibold text-slate-100">{vehicle.name}</span><span class="rounded-full border border-slate-700 px-2 py-0.5 text-[11px] font-medium uppercase tracking-wide text-slate-400">{vehicle.visibility}</span></span><span class="mt-2 block text-sm text-slate-400">{vehicle.make} {vehicle.model}{vehicle.model_year ? ` · ${vehicle.model_year}` : ''}</span><span class="mt-1 block text-xs capitalize text-slate-500">{vehicle.fuel_type} {$t("· odometer in")} {vehicle.odometer_unit}</span></span>
							<span class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-cyan-200 group-hover:text-cyan-100">{$t("Open vehicle record")} <span aria-hidden="true">→</span></span>
						</a></li>
					{/each}
				</ul>
			{/if}
		</section>
	{:else}
		<div class="mt-8 rounded-2xl border border-amber-300/20 bg-amber-300/5 p-5 text-sm leading-6 text-amber-100"><p class="font-semibold">{$t("View access is not enabled.")}</p><p class="mt-1 text-amber-100/75">{$t("You can create a private vehicle if you have Create access. Browsing records requires an explicit View grant.")}</p></div>
	{/if}

	{#if canCreatePrivate || canCreateShared}
		<section class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/60 p-5 shadow-xl shadow-black/10 sm:p-8" aria-labelledby="new-vehicle-heading">
			<p class="text-sm font-medium text-cyan-200">{$t("Add to the fleet")}</p><h2 id="new-vehicle-heading" class="mt-2 text-2xl font-semibold tracking-tight text-white">{$t("Set up a vehicle.")}</h2><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">{$t("Choose the odometer unit before recording mileage. The unit is fixed once readings or maintenance mileage exist.")}</p>
			<form class="mt-7 grid gap-4 sm:grid-cols-2" onsubmit={createVehicle}>
				<label class="block text-sm font-medium text-slate-200">{$t("Display name")}<input bind:value={name} required maxlength="120" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("Daily car")} /></label>
				<label class="block text-sm font-medium text-slate-200">{$t("Make")}<input bind:value={make} required maxlength="80" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("Volvo")} /></label>
				<label class="block text-sm font-medium text-slate-200">{$t("Model")}<input bind:value={model} required maxlength="80" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder="V60" /></label>
				<label class="block text-sm font-medium text-slate-200">{$t("Model year")} <span class="font-normal text-slate-500">{$t("(optional)")}</span><input bind:value={modelYear} type="number" min="1886" max="2200" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder="2022" /></label>
				<label class="block text-sm font-medium text-slate-200">{$t("Fuel type")}<select bind:value={fuelType} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20">{#each vehicleFuelTypes as fuel}<option value={fuel.value}>{fuel.label}</option>{/each}</select></label>
				<label class="block text-sm font-medium text-slate-200">{$t("Odometer unit")}<select bind:value={odometerUnit} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"><option value="km">{$t("Kilometres · km")}</option><option value="mi">{$t("Miles · mi")}</option></select></label>
				<label class="block text-sm font-medium text-slate-200">{$t("Visibility")}<select bind:value={visibility} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20">{#if canCreatePrivate}<option value="private">{$t("Private to me")}</option>{/if}{#if canCreateShared}<option value="household">{$t("Shared household vehicle")}</option>{/if}</select></label>
				<label class="block text-sm font-medium text-slate-200 sm:col-span-2">{$t("Description")} <span class="font-normal text-slate-500">{$t("(optional)")}</span><textarea bind:value={description} maxlength="2000" rows="2" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm leading-6 text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("Context for this vehicle")}></textarea></label>
				<div class="flex flex-col-reverse gap-3 border-t border-slate-800 pt-5 sm:col-span-2 sm:flex-row sm:items-center sm:justify-between"><p class="text-xs leading-5 text-slate-500">{$t("Private vehicle, insurance, mileage and cost details are hidden from administrators unless the owner grants access.")}</p><button type="submit" disabled={saving} class="min-h-11 rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-100 disabled:cursor-wait disabled:opacity-60">{$t(saving ? 'Creating…' : 'Create vehicle')}</button></div>
			</form>
		</section>
	{:else}
		<p class="mt-8 rounded-xl border border-slate-800 bg-slate-900/40 px-4 py-5 text-sm leading-6 text-slate-400">{$t("Your account does not have permission to create a vehicle. Ask an administrator for Vehicles Create access.")}</p>
	{/if}
</section>
