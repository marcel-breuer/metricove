<script lang="ts">
	import { goto } from '$app/navigation';
	import { getContext, onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { formatMeasurement } from '#lib/number-format';
	import { utilityMeterTypes, type UtilityMeter, type UtilitiesResponse, type UtilityVisibility } from '#lib/utilities';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'utilities'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreatePrivate = $derived(access?.capabilities.create ?? false);
	const canCreateShared = $derived(shell.currentUser?.is_admin === true && (access?.capabilities.manage ?? false));

	let meters = $state<UtilityMeter[]>([]);
	let loading = $state(true);
	let saving = $state(false);
	let error = $state('');
	let message = $state('');
	let name = $state('');
	let meterType = $state<'electricity' | 'gas' | 'water'>('electricity');
	let visibility = $state<UtilityVisibility>('private');
	let description = $state('');
	let alertThreshold = $state('');

	$effect(() => {
		if (!canCreatePrivate && canCreateShared) visibility = 'household';
	});

	onMount(() => { void loadMeters(); });

	async function loadMeters(): Promise<void> {
		loading = true;
		error = '';
		if (!canView) {
			meters = [];
			loading = false;
			return;
		}
		try {
			const response = await fetch('/api/utilities/meters', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as UtilitiesResponse;
			if (!response.ok) throw new Error(result.message ?? 'Meters are unavailable. Check your access and try again.');
			meters = result.meters ?? [];
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Meters are unavailable. Check your connection and try again.';
		} finally {
			loading = false;
		}
	}

	async function createMeter(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		message = '';
		error = '';
		if ((visibility === 'private' && !canCreatePrivate) || (visibility === 'household' && !canCreateShared)) {
			error = 'Your account does not have permission to create this meter.';
			return;
		}
		const token = csrfToken();
		if (!token) { error = 'Reload this page and try again.'; return; }
		saving = true;
		try {
			const response = await fetch('/api/utilities/meters', {
				method: 'POST', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ name, meter_type: meterType, visibility, description, alert_threshold: alertThreshold || null })
			});
			const result = (await response.json()) as UtilitiesResponse & ApiResponse;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok || !result.meter) { error = firstError(result) || result.message || 'The meter could not be created.'; return; }
			name = ''; description = ''; alertThreshold = '';
			message = visibility === 'household' ? 'Household meter created.' : 'Private meter created.';
			if (canView) { await goto(`/modules/utilities/${result.meter.id}`); return; }
		} catch {
			error = 'The meter could not be created. Check your connection and try again.';
		} finally {
			saving = false;
		}
	}
</script>

<svelte:head>
	<title>Utilities · Metricove</title>
	<meta name="description" content="Record household electricity, gas and water meter readings." />
</svelte:head>

<section class="mx-auto max-w-screen-xl">
	<div class="flex flex-wrap items-end justify-between gap-4 border-b border-slate-800 pb-6">
		<div><p class="text-sm font-medium tracking-wide text-cyan-200">Utilities module</p><h1 class="mt-2 text-3xl font-semibold tracking-tight text-white sm:text-4xl">Know what your meters use.</h1></div>
		<p class="max-w-xl text-sm leading-6 text-slate-400">Keep dated electricity, gas and water readings together. Metricove derives interval usage from the cumulative register and highlights unusually large jumps.</p>
	</div>
	{#if message}<p role="status" class="mt-6 rounded-xl border border-emerald-300/30 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">{message}</p>{/if}
	{#if error}<p role="alert" class="mt-6 rounded-xl border border-rose-300/30 bg-rose-300/10 px-4 py-3 text-sm text-rose-100">{error}</p>{/if}

	{#if canView}
		<section class="mt-8" aria-labelledby="meters-heading">
			<div class="flex flex-wrap items-center justify-between gap-3"><div><h2 id="meters-heading" class="text-xl font-semibold text-slate-100">Meters</h2><p class="mt-1 text-sm text-slate-400">Private meters are visible to their owner. Shared meters are visible to members with Utilities View access.</p></div><button type="button" onclick={() => void loadMeters()} disabled={loading} class="rounded-lg border border-slate-700 px-3 py-2 text-sm font-medium text-slate-200 hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:opacity-50">Refresh</button></div>
			{#if loading}
				<p role="status" class="mt-5 rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-5 text-sm text-slate-300">Loading meters…</p>
			{:else if meters.length === 0}
				<div class="mt-5 rounded-2xl border border-slate-800 bg-slate-900/50 p-6 sm:p-8"><h3 class="text-lg font-semibold text-slate-100">No meters yet.</h3><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">Add a private meter for your own records, or ask an active administrator to create one shared across the household.</p></div>
			{:else}
				<ul class="mt-5 grid gap-3 md:grid-cols-2">
					{#each meters as meter (meter.id)}
						<li><a href={`/modules/utilities/${meter.id}`} class="group flex min-h-32 flex-col justify-between rounded-2xl border border-slate-800 bg-slate-900/50 p-5 transition hover:border-cyan-300/50 hover:bg-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">
							<span><span class="flex flex-wrap items-center justify-between gap-2"><span class="font-semibold text-slate-100">{meter.name}</span><span class="rounded-full border border-slate-700 px-2 py-0.5 text-[11px] font-medium uppercase tracking-wide text-slate-400">{meter.visibility}</span></span><span class="mt-2 block text-sm text-slate-400">{meter.meter_label} · {meter.unit}</span><span class="mt-1 block text-xs text-slate-500">{meter.description || (meter.alert_threshold ? `Alert above ${formatMeasurement(meter.alert_threshold, meter.unit)} ${meter.unit} per interval` : 'No interval alert set')}</span></span>
							<span class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-cyan-200 group-hover:text-cyan-100">Open readings <span aria-hidden="true">→</span></span>
						</a></li>
					{/each}
				</ul>
			{/if}
		</section>
	{:else}
		<div class="mt-8 rounded-2xl border border-amber-300/20 bg-amber-300/5 p-5 text-sm leading-6 text-amber-100"><p class="font-semibold">View access is not enabled.</p><p class="mt-1 text-amber-100/75">You can create a private meter if you have Create access, but browsing readings requires an explicit View grant.</p></div>
	{/if}

	{#if canCreatePrivate || canCreateShared}
		<section class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/60 p-5 shadow-xl shadow-black/10 sm:p-8" aria-labelledby="new-meter-heading">
			<p class="text-sm font-medium text-cyan-200">Meter setup</p><h2 id="new-meter-heading" class="mt-2 text-2xl font-semibold tracking-tight text-white">Add a meter.</h2><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">Register units are fixed by type to prevent accidental conversions: electricity uses kWh; gas and water use m³. Enter cumulative readings from the meter display.</p>
			<form class="mt-7 grid gap-4 sm:grid-cols-2" onsubmit={createMeter}>
				<label class="block text-sm font-medium text-slate-200">Meter name<input bind:value={name} required maxlength="120" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder="Main electricity" /></label>
				<label class="block text-sm font-medium text-slate-200">Meter type<select bind:value={meterType} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20">{#each utilityMeterTypes as type}<option value={type.value}>{type.label} · {type.unit}</option>{/each}</select></label>
				<label class="block text-sm font-medium text-slate-200">Visibility<select bind:value={visibility} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20">{#if canCreatePrivate}<option value="private">Private to me</option>{/if}{#if canCreateShared}<option value="household">Shared household meter</option>{/if}</select></label>
				<label class="block text-sm font-medium text-slate-200">Alert threshold <span class="font-normal text-slate-500">(optional, {utilityMeterTypes.find((type) => type.value === meterType)?.unit} per interval)</span><input bind:value={alertThreshold} inputmode="decimal" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder="No alert" /></label>
				<label class="block text-sm font-medium text-slate-200 sm:col-span-2">Description <span class="font-normal text-slate-500">(optional)</span><textarea bind:value={description} maxlength="2000" rows="2" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm leading-6 text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder="Location or meter details"></textarea></label>
				<div class="flex flex-col-reverse gap-3 border-t border-slate-800 pt-5 sm:col-span-2 sm:flex-row sm:items-center sm:justify-between"><p class="text-xs leading-5 text-slate-500">Private readings and meter photos remain hidden from administrators unless their owner grants access.</p><button type="submit" disabled={saving} class="min-h-11 rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-100 disabled:cursor-wait disabled:opacity-60">{saving ? 'Creating…' : 'Create meter'}</button></div>
			</form>
		</section>
	{:else}
		<p class="mt-8 rounded-xl border border-slate-800 bg-slate-900/40 px-4 py-5 text-sm leading-6 text-slate-400">Your account does not have permission to create a meter. Ask an administrator for Utilities Create access.</p>
	{/if}
</section>
