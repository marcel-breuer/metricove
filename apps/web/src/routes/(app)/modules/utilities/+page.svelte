<script lang="ts">
	import Badge from '#lib/ui/Badge.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import { t, visibilityLabel } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { getContext, onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import Modal from '#lib/ui/Modal.svelte';
	import Button from '#lib/ui/Button.svelte';
	import { canonicalizeDecimalInput, formatMeasurement } from '#lib/number-format';
	import { numberLocale } from '#lib/i18n';
	import { utilityMeterTypes, type UtilityMeter, type UtilitiesResponse, type UtilityVisibility } from '#lib/utilities';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'utilities'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreatePrivate = $derived(access?.capabilities.create ?? false);
	const canCreateShared = $derived(shell.currentUser?.is_admin === true && (access?.capabilities.manage ?? false));

	let meters = $state<UtilityMeter[]>([]);
	let loading = $state(true);
	let saving = $state(false);
	let createOpen = $state(false);
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
				body: JSON.stringify({ name, meter_type: meterType, visibility, description, alert_threshold: alertThreshold ? canonicalizeDecimalInput(alertThreshold, $numberLocale) : null })
			});
			const result = (await response.json()) as UtilitiesResponse & ApiResponse;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok || !result.meter) { error = firstError(result) || result.message || 'The meter could not be created.'; return; }
			name = ''; description = ''; alertThreshold = '';
			createOpen = false;
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
	<title>{$t("Utilities")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Record household electricity, gas and water meter readings.")} />
</svelte:head>

<section class="mx-auto max-w-screen-xl">
	<PageHeading eyebrow="Utilities module" title="Know what your meters use" description="Keep dated electricity, gas and water readings together. Metricove derives interval usage from the cumulative register and highlights unusually large jumps." />
	{#if message}<Notice tone="success" message={message} class="mt-6" />{/if}
	{#if error}<Notice tone="error" message={error} class="mt-6" />{/if}

	{#if canView}
		<section class="mt-8" aria-labelledby="meters-heading">
			<div class="flex flex-wrap items-center justify-between gap-3"><div><h2 id="meters-heading" class="text-xl font-semibold text-ink">{$t("Meters")}</h2><p class="mt-1 text-sm text-ink-muted">{$t("Private meters are visible to their owner. Shared meters are visible to members with Utilities View access.")}</p></div><button type="button" onclick={() => void loadMeters()} disabled={loading} class="rounded-lg border border-line-strong px-3 py-2 text-sm font-medium text-ink-secondary hover:border-accent-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink disabled:opacity-50">{$t("Refresh")}</button></div>
			{#if loading}
				<p role="status" class="mt-5 rounded-xl border border-line glass-surface px-4 py-5 text-sm text-ink-secondary">{$t("Loading meters…")}</p>
			{:else if meters.length === 0}
				<div class="mt-5 rounded-2xl border border-line glass-surface p-6 sm:p-8"><h3 class="text-lg font-semibold text-ink">{$t("No meters yet.")}</h3><p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted">{$t("Add a private meter for your own records, or ask an active administrator to create one shared across the household.")}</p></div>
			{:else}
				<ul class="mt-5 grid gap-3 md:grid-cols-2">
					{#each meters as meter (meter.id)}
						<li><a href={`/modules/utilities/${meter.id}`} class="group flex min-h-32 flex-col justify-between rounded-2xl border border-line glass-surface p-5 transition hover:border-accent-ink/50 hover:bg-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink">
							<span><span class="flex flex-wrap items-center justify-between gap-2"><span class="font-semibold text-ink">{meter.name}</span><Badge>{$t(visibilityLabel(meter.visibility))}</Badge></span><span class="mt-2 block text-sm text-ink-muted">{meter.meter_label} · {meter.unit}</span><span class="mt-1 block text-xs text-ink-subtle">{meter.description || (meter.alert_threshold ? $t('Alert above {value} {unit} per interval', { value: formatMeasurement(meter.alert_threshold, meter.unit), unit: meter.unit }) : $t('No interval alert set'))}</span></span>
							<span class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-accent-ink group-hover:text-accent-ink">{$t("Open readings")} <span aria-hidden="true">→</span></span>
						</a></li>
					{/each}
				</ul>
			{/if}
		</section>
	{:else}
		<div class="mt-8 rounded-2xl border border-warning/20 bg-warning/5 p-5 text-sm leading-6 text-warning"><p class="font-semibold">{$t("View access is not enabled.")}</p><p class="mt-1 text-warning/75">{$t("You can create a private meter if you have Create access, but browsing readings requires an explicit View grant.")}</p></div>
	{/if}

	{#if canCreatePrivate || canCreateShared}
		<div class="mt-8 flex justify-end"><button type="button" onclick={() => (createOpen = true)} class="min-h-11 rounded-xl bg-accent px-5 text-sm font-semibold text-on-accent hover:bg-accent/90">{$t('Create meter')}</button></div>
		<Modal bind:open={createOpen} size="fullscreen" title={$t('Add a meter.')} description={$t('Register a meter and its units for future readings.')}>{#if error}<Notice tone="error" message={error} class="mb-5" />{/if}
		<div>
			<p class="mb-6 max-w-2xl text-sm leading-6 text-ink-muted">{$t("Register units are fixed by type to prevent accidental conversions: electricity uses kWh; gas and water use m³. Enter cumulative readings from the meter display.")}</p>
			<form id="meter-create-form" class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4" onsubmit={createMeter}>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Meter name")}<input bind:value={name} required maxlength="120" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none placeholder:text-ink-subtle focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("Main electricity")} /></label>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Meter type")}<select bind:value={meterType} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base">{#each utilityMeterTypes as type}<option value={type.value}>{$t(type.label)} · {type.unit}</option>{/each}</select></label>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Visibility")}<select bind:value={visibility} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base">{#if canCreatePrivate}<option value="private">{$t("Private to me")}</option>{/if}{#if canCreateShared}<option value="household">{$t("Shared household meter")}</option>{/if}</select></label>
				<label class="block text-sm font-medium text-ink-secondary">{$t("Alert threshold")} <span class="font-normal text-ink-subtle">{$t("(optional,")} {utilityMeterTypes.find((type) => type.value === meterType)?.unit} {$t("per interval)")}</span><input bind:value={alertThreshold} inputmode="decimal" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none placeholder:text-ink-subtle focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("No alert")} /></label>
				<label class="block text-sm font-medium text-ink-secondary sm:col-span-2">{$t("Description")} <span class="font-normal text-ink-subtle">{$t("(optional)")}</span><textarea bind:value={description} maxlength="2000" rows="2" class="mt-2 w-full rounded-[10px] border border-line-strong bg-page px-3 py-2.5 text-sm leading-6 text-ink outline-none placeholder:text-ink-subtle focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("Location or meter details")}></textarea></label>
				<div class="sm:col-span-2 lg:col-span-4"><p class="text-xs leading-5 text-ink-subtle">{$t("Private readings and meter photos remain hidden from administrators unless their owner grants access.")}</p></div>
			</form>
		</div>
			{#snippet footer()}
				<Button variant="secondary" type="button" onclick={() => (createOpen = false)}>{$t('Cancel')}</Button>
				<Button type="submit" form="meter-create-form" disabled={saving}>{$t(saving ? 'Creating…' : 'Create meter')}</Button>
			{/snippet}
		</Modal>
	{:else}
		<p class="mt-8 rounded-xl border border-line glass-surface px-4 py-5 text-sm leading-6 text-ink-muted">{$t("Your account does not have permission to create a meter. Ask an administrator for Utilities Create access.")}</p>
	{/if}
</section>
