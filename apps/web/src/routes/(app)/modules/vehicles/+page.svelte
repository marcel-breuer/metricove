<script lang="ts">
	import Badge from '#lib/ui/Badge.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import { t, visibilityLabel } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import Modal from '#lib/ui/Modal.svelte';
	import Button from '#lib/ui/Button.svelte';
	import { vehicleFuelTypes, type Vehicle, type VehicleResponse, type VehicleVisibility } from '#lib/vehicles';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'vehicles'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreatePrivate = $derived(access?.capabilities.create ?? false);
	const canCreateShared = $derived(shell.currentUser?.is_admin === true && (access?.capabilities.manage ?? false));

	let vehicles = $state<Vehicle[]>([]);
	let loading = $state(true);
	let saving = $state(false);
	let createOpen = $state(false);
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
			createOpen = false;
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
	<PageHeading eyebrow="Vehicles module" title="Every mile, in one place" description="Track odometer history, service, insurance dates and running costs. Keep a vehicle private or share it with the household." />
	{#if message}<Notice tone="success" message={message} class="mt-6" />{/if}
	{#if error}<Notice tone="error" message={error} class="mt-6" />{/if}

	{#if canView}
		<section class="mt-8" aria-labelledby="vehicles-heading">
			<div class="flex flex-wrap items-center justify-between gap-3"><div><h2 id="vehicles-heading" class="text-xl font-semibold text-ink">{$t("Your vehicles")}</h2><p class="mt-1 text-sm text-ink-muted">{$t("Private records are visible to their owner and explicitly authorized members.")}</p></div><button type="button" onclick={() => void loadVehicles()} disabled={loading} class="rounded-lg border border-line-strong px-3 py-2 text-sm font-medium text-ink-secondary hover:border-accent-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink disabled:opacity-50">{$t("Refresh")}</button></div>
			{#if loading}
				<p role="status" class="mt-5 rounded-xl border border-line glass-surface px-4 py-5 text-sm text-ink-secondary">{$t("Loading vehicles…")}</p>
			{:else if vehicles.length === 0}
				<div class="mt-5 rounded-2xl border border-line glass-surface p-6 sm:p-8"><h3 class="text-lg font-semibold text-ink">{$t("No vehicles yet.")}</h3><p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted">{$t("Add a private vehicle for your own records, or ask an active administrator to create a shared household vehicle.")}</p></div>
			{:else}
				<ul class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
					{#each vehicles as vehicle (vehicle.id)}
						<li><a href={`/modules/vehicles/${vehicle.id}`} class="group flex min-h-40 flex-col justify-between rounded-2xl border border-line glass-surface p-5 transition hover:border-accent-ink/50 hover:bg-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink">
							<span><span class="flex flex-wrap items-start justify-between gap-2"><span class="font-semibold text-ink">{vehicle.name}</span><Badge>{$t(visibilityLabel(vehicle.visibility))}</Badge></span><span class="mt-2 block text-sm text-ink-muted">{vehicle.make} {vehicle.model}{vehicle.model_year ? ` · ${vehicle.model_year}` : ''}</span><span class="mt-1 block text-xs capitalize text-ink-subtle">{vehicle.fuel_type} {$t("· odometer in")} {vehicle.odometer_unit}</span></span>
							<span class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-accent-ink group-hover:text-accent-ink">{$t("Open vehicle record")} <span aria-hidden="true">→</span></span>
						</a></li>
					{/each}
				</ul>
			{/if}
		</section>
	{:else}
		<div class="mt-8 rounded-2xl border border-warning/20 bg-warning/5 p-5 text-sm leading-6 text-warning"><p class="font-semibold">{$t("View access is not enabled.")}</p><p class="mt-1 text-warning/75">{$t("You can create a private vehicle if you have Create access. Browsing records requires an explicit View grant.")}</p></div>
	{/if}

	{#if canCreatePrivate || canCreateShared}
		<div class="mt-8 flex justify-end"><button type="button" onclick={() => (createOpen = true)} class="min-h-11 rounded-xl bg-accent px-5 text-sm font-semibold text-on-accent hover:bg-accent/90">{$t('Create vehicle')}</button></div>
		<Modal bind:open={createOpen} size="wide" title={$t('Add a vehicle.')} description={$t('Keep vehicle details and records together.')}>{#if error}<Notice tone="error" message={error} class="mb-5" />{/if}
		<div>
			<p class="mb-6 max-w-2xl text-sm leading-6 text-ink-muted">{$t("Choose the odometer unit before recording mileage. The unit is fixed once readings or maintenance mileage exist.")}</p>
			<form id="vehicle-create-form" class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4" onsubmit={createVehicle}>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Display name")}<input bind:value={name} required maxlength="120" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none placeholder:text-ink-subtle focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("Daily car")} /></label>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Make")}<input bind:value={make} required maxlength="80" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none placeholder:text-ink-subtle focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("Volvo")} /></label>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Model")}<input bind:value={model} required maxlength="80" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none placeholder:text-ink-subtle focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder="V60" /></label>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Model year")} <span class="font-normal text-ink-subtle">{$t("(optional)")}</span><input bind:value={modelYear} type="number" min="1886" max="2200" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none placeholder:text-ink-subtle focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder="2022" /></label>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Fuel type")}<select bind:value={fuelType} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base">{#each vehicleFuelTypes as fuel}<option value={fuel.value}>{$t(fuel.label)}</option>{/each}</select></label>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Odometer unit")}<select bind:value={odometerUnit} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base"><option value="km">{$t("Kilometres · km")}</option><option value="mi">{$t("Miles · mi")}</option></select></label>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Visibility")}<select bind:value={visibility} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base">{#if canCreatePrivate}<option value="private">{$t("Private to me")}</option>{/if}{#if canCreateShared}<option value="household">{$t("Shared household vehicle")}</option>{/if}</select></label>
				<label class="block text-sm font-medium text-ink-secondary sm:col-span-2">{$t("Description")} <span class="font-normal text-ink-subtle">{$t("(optional)")}</span><textarea bind:value={description} maxlength="2000" rows="2" class="mt-2 w-full rounded-[10px] border border-line-strong bg-page px-3 py-2.5 text-sm leading-6 text-ink outline-none placeholder:text-ink-subtle focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("Context for this vehicle")}></textarea></label>
				<div class="sm:col-span-2 lg:col-span-4"><p class="text-xs leading-5 text-ink-subtle">{$t("Private vehicle, insurance, mileage and cost details are hidden from administrators unless the owner grants access.")}</p></div>
			</form>
		</div>
			{#snippet footer()}
				<Button variant="secondary" type="button" onclick={() => (createOpen = false)}>{$t('Cancel')}</Button>
				<Button type="submit" form="vehicle-create-form" disabled={saving}>{$t(saving ? 'Creating…' : 'Create vehicle')}</Button>
			{/snippet}
		</Modal>
	{:else}
		<p class="mt-8 rounded-xl border border-line glass-surface px-4 py-5 text-sm leading-6 text-ink-muted">{$t("Your account does not have permission to create a vehicle. Ask an administrator for Vehicles Create access.")}</p>
	{/if}
</section>
