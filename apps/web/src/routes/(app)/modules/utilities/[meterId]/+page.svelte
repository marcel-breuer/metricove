<script lang="ts">
	import { t } from '#lib/i18n';
	import { formatDate, formatDateTime } from '#lib/i18n';
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { formatMeasurement } from '#lib/number-format';
	import type { UtilityMeter, UtilityReading, UtilityReadingRevision, UtilitiesResponse } from '#lib/utilities';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const meterId = $derived(Number(page.params.meterId));
	const access = $derived(shell.modules.find((module) => module.key === 'utilities'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreate = $derived(access?.capabilities.create ?? false);
	const canEdit = $derived(access?.capabilities.edit ?? false);
	const canManage = $derived(access?.capabilities.manage ?? false);
	const importAccess = $derived(shell.modules.find((module) => module.key === 'import'));
	const canSuggest = $derived(canCreate && importAccess?.capabilities.create === true);

	let meter = $state<UtilityMeter | null>(null);
	let readings = $state<UtilityReading[]>([]);
	let loading = $state(true);
	let saving = $state(false);
	let correcting = $state(false);
	let error = $state('');
	let message = $state('');
	let readingDate = $state(localDate());
	let readingValue = $state('');
	let readingNote = $state('');
	let readingPhoto = $state<File | null>(null);
	let ocrWorking = $state(false);
	let ocrMessage = $state('');
	let meterName = $state('');
	let alertThreshold = $state('');
	let editingReadingId = $state<number | null>(null);
	let correctionDate = $state('');
	let correctionValue = $state('');
	let correctionNote = $state('');
	let correctionReason = $state('');
	let revisionsByReading = $state<Record<number, UtilityReadingRevision[]>>({});
	let revisionsLoading = $state<number | null>(null);

	const chartData = $derived(readings.filter((reading) => reading.consumption_since_previous !== null).slice(-12));
	const chartMaximum = $derived(Math.max(1, ...chartData.map((reading) => Number(reading.consumption_since_previous ?? 0))));
	const alertCount = $derived(readings.filter((reading) => reading.alert).length);
	const latestUsage = $derived([...readings].reverse().find((reading) => reading.consumption_since_previous !== null) ?? null);

	$effect(() => { if (!shell.loading) void loadMeter(); });

	function localDate(): string {
		const date = new Date();
		return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
	}

	async function loadMeter(): Promise<void> {
		loading = true;
		error = '';
		if (!canView || !Number.isInteger(meterId) || meterId < 1) {
			meter = null;
			readings = [];
			loading = false;
			return;
		}
		try {
			const response = await fetch(`/api/utilities/meters/${meterId}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as UtilitiesResponse;
			if (!response.ok || !result.meter) throw new Error(result.message ?? 'This meter is unavailable or you do not have access.');
			meter = result.meter;
			readings = result.readings ?? [];
			meterName = meter.name;
			alertThreshold = meter.alert_threshold ?? '';
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The meter could not be loaded. Check your connection and try again.';
		} finally {
			loading = false;
		}
	}

	async function saveReading(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		message = ''; error = '';
		if (!meter || !canCreate) { error = 'Your account does not have Utilities Create access.'; return; }
		const token = csrfToken();
		if (!token) { error = 'Reload this page and try again.'; return; }
		const form = new FormData();
		form.set('reading_date', readingDate);
		form.set('value', readingValue);
		form.set('note', readingNote);
		if (readingPhoto) form.set('photo', readingPhoto);
		saving = true;
		try {
			const response = await fetch(`/api/utilities/meters/${meter.id}/readings`, {
				method: 'POST', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token }, body: form
			});
			const result = (await response.json()) as UtilitiesResponse & ApiResponse;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok) { error = firstError(result) || result.message || 'The reading could not be saved.'; return; }
			readingValue = ''; readingNote = ''; readingPhoto = null; readingDate = localDate();
			message = 'Meter reading saved.';
			await loadMeter();
		} catch {
			error = 'The reading could not be saved. Check your connection and try again.';
		} finally {
			saving = false;
		}
	}

	async function suggestReadingFromPhoto(): Promise<void> {
		if (!meter || !readingPhoto || !canSuggest || ocrWorking) return;
		const token = csrfToken();
		if (!token) { ocrMessage = 'Reload this page and try again.'; return; }
		const form = new FormData();
		form.set('photo', readingPhoto);
		ocrWorking = true;
		ocrMessage = '';
		try {
			const response = await fetch(`/api/import/utilities/meters/${meter.id}/reading-suggestions`, {
				method: 'POST', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token }, body: form
			});
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as { value?: string | null; message?: string };
			if (!response.ok) throw new Error(result.message || 'A local reading suggestion is unavailable.');
			if (result.value) {
				readingValue = result.value;
				ocrMessage = `Suggested ${formatMeasurement(result.value, meter.unit)} ${meter.unit}. Check the meter photo and save the reading yourself.`;
			} else {
				ocrMessage = 'No single reading could be recognized. Enter the value from the photo manually.';
			}
		} catch (cause) {
			ocrMessage = cause instanceof Error ? cause.message : 'A local reading suggestion is unavailable.';
		} finally {
			ocrWorking = false;
		}
	}

	async function saveMeter(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!meter || !canManage) return;
		message = ''; error = '';
		const token = csrfToken();
		if (!token) { error = 'Reload this page and try again.'; return; }
		saving = true;
		try {
			const response = await fetch(`/api/utilities/meters/${meter.id}`, {
				method: 'PATCH', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ name: meterName, alert_threshold: alertThreshold || null })
			});
			const result = (await response.json()) as UtilitiesResponse & ApiResponse;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok || !result.meter) { error = firstError(result) || result.message || 'The meter settings could not be saved.'; return; }
			meter = result.meter;
			message = 'Meter settings saved.';
			await loadMeter();
		} catch { error = 'The meter settings could not be saved. Check your connection and try again.'; }
		finally { saving = false; }
	}

	function beginCorrection(reading: UtilityReading): void {
		editingReadingId = reading.id;
		correctionDate = reading.reading_date;
		correctionValue = reading.value;
		correctionNote = reading.note ?? '';
		correctionReason = '';
		message = '';
		error = '';
	}

	async function saveCorrection(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!editingReadingId || !canEdit) return;
		const token = csrfToken();
		if (!token) { error = 'Reload this page and try again.'; return; }
		correcting = true; error = ''; message = '';
		try {
			const response = await fetch(`/api/utilities/readings/${editingReadingId}`, {
				method: 'PATCH', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ reading_date: correctionDate, value: correctionValue, note: correctionNote, reason: correctionReason })
			});
			const result = (await response.json()) as UtilitiesResponse & ApiResponse;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok) { error = firstError(result) || result.message || 'The correction could not be saved.'; return; }
			editingReadingId = null;
			message = 'Correction saved. Usage intervals were recalculated.';
			await loadMeter();
		} catch { error = 'The correction could not be saved. Check your connection and try again.'; }
		finally { correcting = false; }
	}

	async function loadRevisions(readingId: number): Promise<void> {
		if (revisionsByReading[readingId]) { revisionsByReading = { ...revisionsByReading, [readingId]: [] }; return; }
		revisionsLoading = readingId;
		try {
			const response = await fetch(`/api/utilities/readings/${readingId}/revisions`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as UtilitiesResponse;
			if (!response.ok) throw new Error(result.message ?? 'Correction history is unavailable.');
			revisionsByReading = { ...revisionsByReading, [readingId]: result.revisions ?? [] };
		} catch (cause) { error = cause instanceof Error ? cause.message : 'Correction history is unavailable.'; }
		finally { revisionsLoading = null; }
	}
</script>

<svelte:head>
	<title>{meter ? `${meter.name} · Utilities · Metricove` : 'Utilities · Metricove'}</title>
	<meta name="description" content={$t("Meter reading history, consumption trends and corrections.")} />
</svelte:head>

{#if loading}
	<div role="status" class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 text-sm text-slate-300">{$t("Loading meter…")}</div>
{:else if !canView}
	<section class="mx-auto max-w-2xl rounded-2xl border border-amber-300/20 bg-amber-300/5 p-6 sm:p-8"><p class="text-sm font-medium text-amber-200">{$t("Utilities View access is required.")}</p><h1 class="mt-2 text-2xl font-semibold text-white">{$t("This meter is private to its authorized members.")}</h1><p class="mt-3 text-sm leading-6 text-slate-400">{$t("An administrator can enable Utilities and grant the exact module capability. Private meter records still require an explicit record grant.")}</p><a href="/modules/utilities" class="mt-6 inline-flex rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-200 hover:border-cyan-300">{$t("Back to Utilities")}</a></section>
{:else if meter}
	<section class="mx-auto max-w-screen-xl">
		<a href="/modules/utilities" class="inline-flex items-center gap-2 text-sm font-medium text-cyan-200 hover:text-cyan-100 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-cyan-300"><span aria-hidden="true">←</span> {$t("All meters")}</a>
		<div class="mt-5 flex flex-wrap items-end justify-between gap-4 border-b border-slate-800 pb-6">
			<div><p class="text-sm font-medium tracking-wide text-cyan-200">{meter.meter_label} {$t("meter ·")} {meter.visibility}</p><h1 class="mt-2 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{meter.name}</h1><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">{meter.description ?? 'Readings are cumulative. Consumption is calculated from adjacent dates in chronological order.'}</p></div>
			<div class="grid grid-cols-2 gap-3 text-right"><div class="rounded-xl border border-slate-800 bg-slate-900/60 px-4 py-3"><span class="block text-xs uppercase tracking-wide text-slate-500">{$t("Latest interval")}</span><span class="mt-1 block text-lg font-semibold text-white">{formatMeasurement(latestUsage?.consumption_since_previous, meter.unit)} <span class="text-sm font-medium text-slate-400">{meter.unit}</span></span></div><div class="rounded-xl border border-slate-800 bg-slate-900/60 px-4 py-3"><span class="block text-xs uppercase tracking-wide text-slate-500">{$t("Alerts")}</span><span class="mt-1 block text-lg font-semibold {alertCount > 0 ? 'text-amber-200' : 'text-white'}">{alertCount}</span></div></div>
		</div>
		{#if message}<p role="status" class="mt-5 rounded-xl border border-emerald-300/30 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">{$t(message)}</p>{/if}
		{#if error}<p role="alert" class="mt-5 rounded-xl border border-rose-300/30 bg-rose-300/10 px-4 py-3 text-sm text-rose-100">{$t(error)}</p>{/if}

		{#if canManage}
			<form class="mt-6 grid gap-4 rounded-2xl border border-slate-800 bg-slate-900/50 p-5 sm:grid-cols-[1fr_1fr_auto] sm:items-end" onsubmit={saveMeter}>
				<label class="block text-sm font-medium text-slate-200">{$t("Meter name")}<input bind:value={meterName} required maxlength="120" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
				<label class="block text-sm font-medium text-slate-200">{$t("Interval alert threshold")} <span class="font-normal text-slate-500">({meter.unit})</span><input bind:value={alertThreshold} inputmode="decimal" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("No alert")} /></label>
				<button type="submit" disabled={saving} class="min-h-11 rounded-xl border border-slate-700 px-4 text-sm font-semibold text-slate-200 hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:opacity-50">{$t(saving ? 'Saving…' : 'Save settings')}</button>
			</form>
		{/if}

		<section class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/50 p-5 sm:p-7" aria-labelledby="trend-heading">
			<div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-sm font-medium text-cyan-200">{$t("Consumption trend")}</p><h2 id="trend-heading" class="mt-1 text-xl font-semibold text-slate-100">{$t("Usage between readings")}</h2></div><p class="text-xs text-slate-500">{$t("Latest")} {chartData.length} {$t("intervals ·")} {meter.unit}</p></div>
			{#if chartData.length === 0}
				<p class="mt-5 rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-5 text-sm text-slate-400">{$t("Add a second dated reading to see consumption.")}</p>
			{:else}
				<div class="mt-5 overflow-x-auto"><svg class="h-48 min-w-[34rem] w-full" viewBox="0 0 720 190" role="img" aria-label={$t("Bar chart of consumption between consecutive meter readings")}>
					<line x1="30" y1="155" x2="700" y2="155" stroke="#334155" stroke-width="1" />
					{#each chartData as reading, index (reading.id)}
						{@const barWidth = Math.min(36, 560 / chartData.length)}
						{@const x = 48 + index * (620 / chartData.length)}
						{@const height = Math.max(3, Number(reading.consumption_since_previous ?? 0) / chartMaximum * 118)}
						<rect x={x} y={155 - height} width={barWidth} height={height} rx="5" fill={reading.alert ? '#fbbf24' : '#67e8f9'} opacity="0.9"><title>{formatDate(reading.reading_date)}: {formatMeasurement(reading.consumption_since_previous, meter.unit)} {meter.unit}{$t(reading.alert ? ' — alert' : '')}</title></rect>
						<text x={x + barWidth / 2} y="174" text-anchor="middle" fill="#94a3b8" font-size="10">{formatDate(reading.reading_date, { month: '2-digit', day: '2-digit' })}</text>
					{/each}
				</svg></div>
			{/if}
		</section>

		<div class="mt-8 grid gap-8 xl:grid-cols-[minmax(18rem,0.72fr)_minmax(0,1.28fr)]">
			{#if canCreate}
				<section class="h-fit rounded-2xl border border-slate-800 bg-slate-900/60 p-5 shadow-xl shadow-black/10 sm:p-7" aria-labelledby="add-reading-heading">
					<p class="text-sm font-medium text-cyan-200">{$t("New reading")}</p><h2 id="add-reading-heading" class="mt-1 text-xl font-semibold text-slate-100">{$t("Record the register.")}</h2><p class="mt-2 text-sm leading-6 text-slate-400">{$t("A reading can be added out of order when its value fits between the neighboring readings.")}</p>
					<form class="mt-5 space-y-4" onsubmit={saveReading}>
						<label class="block text-sm font-medium text-slate-200">{$t("Reading date")}<input bind:value={readingDate} type="date" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
						<label class="block text-sm font-medium text-slate-200">{$t("Cumulative value")} <span class="font-normal text-slate-500">({meter.unit})</span><input bind:value={readingValue} required inputmode="decimal" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder="1234.567" /></label>
						<label class="block text-sm font-medium text-slate-200">{$t("Note")} <span class="font-normal text-slate-500">{$t("(optional)")}</span><textarea bind:value={readingNote} maxlength="2000" rows="2" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm leading-6 text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("Meter display or context")}></textarea></label>
						<label class="block text-sm font-medium text-slate-200">{$t("Meter photo")} <span class="font-normal text-slate-500">{$t("(optional, max 5 MB)")}</span><input type="file" accept="image/jpeg,image/png,image/webp" onchange={(event) => { readingPhoto = event.currentTarget.files?.[0] ?? null; ocrMessage = ''; }} class="mt-2 block min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-800 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-200 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
						{#if canSuggest}
							<div><button type="button" onclick={suggestReadingFromPhoto} disabled={!readingPhoto || ocrWorking || saving} class="min-h-10 rounded-lg border border-cyan-300/30 px-3 text-xs font-semibold text-cyan-100 transition hover:bg-cyan-300/10 disabled:cursor-not-allowed disabled:opacity-50">{$t(ocrWorking ? 'Reading photo locally…' : 'Suggest value from photo')}</button><p class="mt-2 text-xs leading-5 text-slate-500">{$t("Local OCR only. The suggestion is never saved until you confirm with Save reading.")}</p>{#if ocrMessage}<p role="status" class="mt-2 text-sm text-cyan-100">{ocrMessage}</p>{/if}</div>
						{/if}
						<button type="submit" disabled={saving} class="min-h-11 w-full rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-100 disabled:cursor-wait disabled:opacity-60">{$t(saving ? 'Saving…' : 'Save reading')}</button>
					</form>
				</section>
			{/if}

			<section aria-labelledby="history-heading">
				<div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-sm font-medium text-cyan-200">{$t("Reading history")}</p><h2 id="history-heading" class="mt-1 text-xl font-semibold text-slate-100">{$t("Chronological record")}</h2></div><span class="text-xs text-slate-500">{readings.length} {$t("recent readings")}</span></div>
				{#if readings.length === 0}
					<div class="mt-4 rounded-2xl border border-slate-800 bg-slate-900/50 p-6"><h3 class="font-semibold text-slate-100">{$t("No readings yet.")}</h3><p class="mt-2 text-sm leading-6 text-slate-400">{$t("Save the current register value. The next reading will establish the first usage interval.")}</p></div>
				{:else}
				<ol class="mt-4 space-y-3">
					{#each [...readings].reverse() as reading (reading.id)}
						<li class="rounded-2xl border {reading.alert ? 'border-amber-300/30 bg-amber-300/[0.06]' : 'border-slate-800 bg-slate-900/50'} p-4 sm:p-5">
							<div class="flex flex-wrap items-start justify-between gap-4">
								<div><div class="flex flex-wrap items-center gap-2"><time datetime={reading.reading_date} class="font-semibold text-slate-100">{formatDate(reading.reading_date)}</time>{#if reading.alert}<span class="rounded-full bg-amber-300/15 px-2 py-0.5 text-xs font-semibold text-amber-100">{$t("Usage alert")}</span>{/if}{#if reading.revision_count > 0}<span class="rounded-full border border-slate-700 px-2 py-0.5 text-xs text-slate-400">{reading.revision_count} {$t(reading.revision_count === 1 ? 'correction' : 'corrections')}</span>{/if}</div><p class="mt-2 text-2xl font-semibold tracking-tight text-white">{formatMeasurement(reading.value, reading.unit)} <span class="text-sm font-medium text-slate-400">{reading.unit}</span></p></div>
								<div class="min-w-36 text-left sm:text-right"><span class="block text-xs uppercase tracking-wide text-slate-500">{$t("Since previous")}</span><span class="mt-1 block text-base font-semibold {reading.alert ? 'text-amber-100' : 'text-cyan-100'}">{formatMeasurement(reading.consumption_since_previous, meter.unit)} {reading.consumption_since_previous === null ? '' : reading.unit}</span>{#if reading.days_since_previous !== null}<span class="mt-1 block text-xs text-slate-500">{$t('{count} days since the previous reading', { count: reading.days_since_previous })}</span>{/if}</div>
							</div>
							{#if reading.note}<p class="mt-3 text-sm leading-6 text-slate-300">{reading.note}</p>{/if}
							{#if reading.photo_url}<a href={reading.photo_url} target="_blank" rel="noreferrer" class="mt-3 inline-flex rounded-lg border border-slate-700 p-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300"><img src={reading.photo_url} alt={$t('Meter photo for {date}', { date: formatDate(reading.reading_date) })} loading="lazy" class="max-h-36 max-w-48 rounded-md object-cover" /></a>{/if}
							<div class="mt-4 flex flex-wrap gap-3 border-t border-slate-800 pt-3">
								{#if canEdit}<button type="button" onclick={() => beginCorrection(reading)} class="rounded-lg px-2 py-1 text-sm font-medium text-cyan-200 hover:bg-cyan-300/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{$t("Correct reading")}</button>{/if}
								{#if reading.revision_count > 0}<button type="button" onclick={() => void loadRevisions(reading.id)} class="rounded-lg px-2 py-1 text-sm font-medium text-slate-300 hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{revisionsByReading[reading.id] ? (revisionsByReading[reading.id].length ? 'Hide correction history' : 'Correction history') : 'Correction history'}</button>{/if}
							</div>
							{#if editingReadingId === reading.id}
								<form class="mt-4 grid gap-3 rounded-xl border border-cyan-300/20 bg-slate-950/60 p-4 sm:grid-cols-2" onsubmit={saveCorrection}>
									<label class="block text-sm font-medium text-slate-200">{$t("Corrected date")}<input bind:value={correctionDate} type="date" required class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
									<label class="block text-sm font-medium text-slate-200">{$t("Corrected value (")}{reading.unit})<input bind:value={correctionValue} required inputmode="decimal" class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
									<label class="block text-sm font-medium text-slate-200 sm:col-span-2">{$t("Note")}<textarea bind:value={correctionNote} maxlength="2000" rows="2" class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"></textarea></label>
									<label class="block text-sm font-medium text-slate-200 sm:col-span-2">{$t("Reason for correction")}<input bind:value={correctionReason} required minlength="10" maxlength="500" class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("Explain why the original reading changed")} /></label>
									<div class="flex flex-wrap gap-2 sm:col-span-2"><button type="submit" disabled={correcting} class="min-h-10 rounded-lg bg-cyan-300 px-4 text-sm font-semibold text-slate-950 hover:bg-cyan-200 disabled:opacity-50">{$t(correcting ? 'Saving…' : 'Save correction')}</button><button type="button" onclick={() => { editingReadingId = null; }} class="min-h-10 rounded-lg border border-slate-700 px-4 text-sm font-medium text-slate-300 hover:border-slate-500">{$t("Cancel")}</button></div>
								</form>
							{/if}
							{#if revisionsByReading[reading.id]?.length}
								<ol class="mt-3 space-y-2 rounded-xl bg-slate-950/60 p-3" aria-label={$t("Correction history")}>{#each revisionsByReading[reading.id] as revision (revision.id)}<li class="border-l-2 border-slate-700 pl-3 text-sm"><p class="font-medium text-slate-200">{revision.old_reading_date}: {formatMeasurement(revision.old_value, reading.unit)} → {revision.new_reading_date}: {formatMeasurement(revision.new_value, reading.unit)} {reading.unit}</p><p class="mt-1 text-slate-400">{revision.reason}</p><time class="mt-1 block text-xs text-slate-500">{formatDateTime(revision.changed_at)}</time></li>{/each}</ol>
							{:else if revisionsLoading === reading.id}<p role="status" class="mt-3 text-xs text-slate-500">{$t("Loading correction history…")}</p>{/if}
						</li>
					{/each}
				</ol>
				{/if}
			</section>
		</div>
	</section>
{:else}
	<section class="mx-auto max-w-2xl rounded-2xl border border-rose-300/20 bg-rose-300/5 p-6 sm:p-8"><p class="text-sm font-medium text-rose-200">{$t("Meter unavailable")}</p><h1 class="mt-2 text-2xl font-semibold tracking-tight text-white">{$t("This meter could not be opened.")}</h1><p class="mt-3 text-sm leading-6 text-slate-400">{$t("It may have been removed, Utilities may be disabled, or you may not have a record-level grant.")}</p><a href="/modules/utilities" class="mt-6 inline-flex rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-200 hover:border-cyan-300">{$t("Back to Utilities")}</a></section>
{/if}
