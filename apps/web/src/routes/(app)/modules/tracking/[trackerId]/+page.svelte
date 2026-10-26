<script lang="ts">
	import { t } from '#lib/i18n';
	import { formatDateTime } from '#lib/i18n';
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { getContext, onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import Modal from '#lib/ui/Modal.svelte';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';
	import { formatMeasurement } from '#lib/number-format';
	import {
		newTrackerField,
		type Tracker,
		type TrackerEntry,
		type TrackerField,
		type TrackerFieldDraft,
		type TrackerResponse,
		type TrackerRevision,
		type TrackerValue
	} from '#lib/tracking';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const trackerId = $derived(Number(page.params.trackerId));
	const access = $derived(shell.modules.find((module) => module.key === 'tracking'));
	const canCreate = $derived(access?.capabilities.create ?? false);

	let tracker = $state<Tracker | null>(null);
	let revisions = $state<TrackerRevision[]>([]);
	let entries = $state<TrackerEntry[]>([]);
	let hasMoreEntries = $state(false);
	let loading = $state(true);
	let loadingMoreEntries = $state(false);
	let loadMoreSentinel: HTMLDivElement | null = $state(null);
	let savingEntry = $state(false);
	let savingSchema = $state(false);
	let deletingEntryId = $state<number | null>(null);
	let deletingTracker = $state(false);
	let deleteTargetEntry = $state<TrackerEntry | null>(null);
	let deleteModalOpen = $state(false);
	let deleteTrackerModalOpen = $state(false);
	let entryModalOpen = $state(false);
	let editingEntryId = $state<number | null>(null);
	let schemaOpen = $state(false);
	let error = $state('');
	let message = $state('');
	let recordedAt = $state(localDateTime());
	let note = $state('');
	let entryValues = $state<Record<string, string>>({});
	let booleanValues = $state<Record<string, boolean>>({});
	let schemaName = $state('');
	let schemaDescription = $state('');
	let schemaFields = $state<TrackerFieldDraft[]>([]);
	let nextLocalKey = 1;
	const activeFields = $derived((tracker?.fields ?? []).filter((field) => field.active));
	const historyColumns = $derived(columnsForHistory(entries));
	const numericColumns = $derived(historyColumns.filter((field) => field.type === 'number'));

	onMount(() => {
		void loadTracker();
	});

	$effect(() => {
		const sentinel = loadMoreSentinel;
		if (!sentinel || !hasMoreEntries || typeof IntersectionObserver === 'undefined') return;

		const observer = new IntersectionObserver((observations) => {
			if (observations.some((observation) => observation.isIntersecting)) void loadMoreEntries();
		}, { rootMargin: '320px 0px' });
		observer.observe(sentinel);

		return () => observer.disconnect();
	});

	async function loadTracker(): Promise<void> {
		loading = true;
		error = '';

		try {
			const response = await fetch('/api/tracking/trackers/' + trackerId, {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' }
			});
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			const result = (await response.json()) as TrackerResponse;
			if (!response.ok || !result.tracker) {
				throw new Error(result.message ?? (response.status === 404 || response.status === 403
					? 'This tracker is unavailable to your account.'
					: 'Tracker details could not be loaded. Try again.'));
			}
			tracker = result.tracker;
			revisions = result.schema_revisions ?? [];

			const historyResponse = await fetch('/api/tracking/trackers/' + trackerId + '/entries?limit=30', {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' }
			});
			if (historyResponse.status === 401) {
				await goto('/login');
				return;
			}
			const history = (await historyResponse.json()) as TrackerResponse;
			if (!historyResponse.ok) throw new Error(history.message ?? 'Measurement history is unavailable.');
			entries = history.entries ?? [];
			hasMoreEntries = history.has_more ?? false;
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Tracker details could not be loaded. Check your connection and try again.';
		} finally {
			loading = false;
		}
	}

	async function loadMoreEntries(): Promise<void> {
		const lastEntry = entries[entries.length - 1];
		if (!lastEntry || !hasMoreEntries || loadingMoreEntries) return;
		loadingMoreEntries = true;
		error = '';
		try {
			const cursor = new URLSearchParams({
				limit: '30',
				before_recorded_at: lastEntry.recorded_at,
				before_id: String(lastEntry.id)
			});
			const response = await fetch('/api/tracking/trackers/' + trackerId + '/entries?' + cursor.toString(), {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' }
			});
			if (response.status === 401) { await goto('/login'); return; }
			const result = (await response.json()) as TrackerResponse;
			if (!response.ok) throw new Error(result.message ?? 'More measurements could not be loaded.');
			entries = [...entries, ...(result.entries ?? [])];
			hasMoreEntries = result.has_more ?? false;
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'More measurements could not be loaded. Check your connection and try again.';
		} finally {
			loadingMoreEntries = false;
		}
	}

	function localDateTime(value?: string): string {
		const date = value ? new Date(value) : new Date();
		const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
		return local.toISOString().slice(0, 16);
	}

	function clearEntryForm(): void {
		editingEntryId = null;
		recordedAt = localDateTime();
		note = '';
		entryValues = {};
		booleanValues = {};
	}

	function beginEdit(entry: TrackerEntry): void {
		editingEntryId = entry.id;
		recordedAt = localDateTime(entry.recorded_at);
		note = entry.note ?? '';
		entryValues = {};
		booleanValues = {};
		for (const field of activeFields) {
			const value = entry.values.find((item) => item.key === field.key)?.value;
			if (field.type === 'boolean') booleanValues[field.key] = value === true;
			else entryValues[field.key] = value === null || value === undefined ? '' : String(value);
		}
		entryModalOpen = true;
	}

	async function saveEntry(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!tracker) return;
		error = '';
		message = '';
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		const values: Record<string, string | boolean> = {};
		for (const field of activeFields) {
			values[field.key] = field.type === 'boolean'
				? (booleanValues[field.key] ?? false)
				: (entryValues[field.key] ?? '');
		}

		savingEntry = true;
		try {
			const updating = editingEntryId !== null;
			const response = await fetch(
				updating ? '/api/tracking/entries/' + editingEntryId : '/api/tracking/trackers/' + tracker.id + '/entries',
				{
					method: updating ? 'PATCH' : 'POST',
					credentials: 'same-origin',
					headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
					body: JSON.stringify({
						schema_version: tracker.schema_version,
						recorded_at: new Date(recordedAt).toISOString(),
						note,
						values
					})
				}
			);
			const result = (await response.json()) as TrackerResponse & ApiResponse;
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (!response.ok) {
				error = firstError(result) || result.message || 'The measurement could not be saved.';
				return;
			}

			message = updating ? 'Measurement updated.' : 'Measurement saved.';
			clearEntryForm();
			entryModalOpen = false;
			await loadTracker();
		} catch {
			error = 'The measurement could not be saved. Check your connection and try again.';
		} finally {
			savingEntry = false;
		}
	}

	async function deleteEntry(entry: TrackerEntry): Promise<void> {
		if (!entry.can_edit) return;
		const token = csrfToken();
		if (!token) { error = 'Reload this page and try again.'; return; }
		deletingEntryId = entry.id;
		message = '';
		error = '';
		try {
			const response = await fetch('/api/tracking/entries/' + entry.id, {
				method: 'DELETE', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token }
			});
			const result = (await response.json()) as ApiResponse;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok) { error = result.message ?? 'The measurement could not be deleted.'; return; }
			if (editingEntryId === entry.id) clearEntryForm();
			message = 'Measurement deleted.';
			deleteTargetEntry = null;
			deleteModalOpen = false;
			await loadTracker();
		} catch {
			error = 'The measurement could not be deleted. Check your connection and try again.';
		} finally {
			deletingEntryId = null;
		}
	}

	async function deleteTracker(): Promise<void> {
		if (!tracker?.can_manage_schema) return;
		const token = csrfToken();
		if (!token) { error = 'Reload this page and try again.'; return; }
		deletingTracker = true;
		message = '';
		error = '';
		try {
			const response = await fetch('/api/tracking/trackers/' + tracker.id, {
				method: 'DELETE', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token }
			});
			const result = (await response.json()) as ApiResponse;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok) { error = result.message ?? 'The tracker could not be deleted.'; return; }
			deleteModalOpen = false;
			await goto('/modules/tracking');
		} catch {
			error = 'The tracker could not be deleted. Check your connection and try again.';
		} finally {
			deletingTracker = false;
		}
	}

	function beginSchemaEdit(): void {
		if (!tracker) return;
		schemaName = tracker.name;
		schemaDescription = tracker.description ?? '';
		schemaFields = tracker.fields.filter((field) => field.active).map((field) => ({
			id: field.id,
			local_key: nextLocalKey++,
			label: field.label,
			type: field.type,
			unit: field.unit ?? '',
			required: field.required,
			minimum: field.minimum ?? '',
			maximum: field.maximum ?? ''
		}));
		schemaOpen = true;
	}

	function addSchemaField(): void {
		schemaFields = [...schemaFields, newTrackerField(nextLocalKey++)];
	}

	function removeSchemaField(index: number): void {
		if (schemaFields.length <= 1) return;
		schemaFields = schemaFields.filter((_, fieldIndex) => fieldIndex !== index);
	}

	async function saveSchema(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!tracker) return;
		error = '';
		message = '';
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		const fields = schemaFields.map((field) => ({
			...(field.id ? { id: field.id } : {}),
			label: field.label,
			type: field.type,
			unit: field.type === 'number' ? field.unit : null,
			is_required: field.required,
			minimum: field.type === 'number' && field.minimum !== '' ? field.minimum : null,
			maximum: field.type === 'number' && field.maximum !== '' ? field.maximum : null
		}));

		savingSchema = true;
		try {
			const response = await fetch('/api/tracking/trackers/' + tracker.id, {
				method: 'PUT',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({
					schema_version: tracker.schema_version,
					name: schemaName,
					description: schemaDescription,
					fields
				})
			});
			const result = (await response.json()) as TrackerResponse & ApiResponse;
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (!response.ok) {
				error = firstError(result) || result.message || 'The tracker schema could not be updated.';
				return;
			}
			message = 'Schema revision saved. Earlier entries keep their original field labels and units.';
			schemaOpen = false;
			await loadTracker();
		} catch {
			error = 'The tracker schema could not be updated. Check your connection and try again.';
		} finally {
			savingSchema = false;
		}
	}

	function columnsForHistory(rows: TrackerEntry[]): TrackerValue[] {
		const byKey = new Map<string, TrackerValue>();
		for (const entry of rows) {
			for (const value of entry.values) {
				if (!byKey.has(value.key)) byKey.set(value.key, value);
			}
		}
		return Array.from(byKey.values());
	}

	function valueForEntry(entry: TrackerEntry, key: string): string {
		const item = entry.values.find((value) => value.key === key);
		if (!item || item.value === null) return '—';
		if (typeof item.value === 'boolean') return item.value ? 'Yes' : 'No';
		const displayedValue = item.type === 'number' ? formatMeasurement(item.value, item.unit) : String(item.value);
		return displayedValue + (item.unit ? ' ' + item.unit : '');
	}

	function numericSeries(field: TrackerValue): Array<{ x: number; y: number; value: number; displayedValue: string; at: string }> {
		const points = entries.slice().reverse().flatMap((entry) => {
			const item = entry.values.find((value) => value.key === field.key);
			if (!item || item.type !== 'number' || typeof item.value !== 'string') return [];
			const value = Number(item.value);
			return Number.isFinite(value) ? [{ value, displayedValue: formatMeasurement(item.value, item.unit), at: entry.recorded_at }] : [];
		});
		if (points.length === 0) return [];

		const values = points.map((point) => point.value);
		const minimum = Math.min(...values);
		const maximum = Math.max(...values);
		const range = maximum - minimum || 1;
		return points.map((point, index) => ({
			...point,
			x: points.length === 1 ? 500 : (index / (points.length - 1)) * 1000,
			y: 180 - ((point.value - minimum) / range) * 150
		}));
	}

	function chartPoints(field: TrackerValue): string {
		return numericSeries(field).map((point) => point.x + ',' + point.y).join(' ');
	}

	function formatDate(value: string): string {
		return formatDateTime(value);
	}
</script>

<svelte:head>
	<title>{tracker ? tracker.name + ' · Tracking · Metricove' : 'Tracker history · Metricove'}</title>
	<meta name="description" content={$t("Record a measurement and review its private history.")} />
</svelte:head>

{#if loading}
	<div role="status" class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 text-sm text-slate-300">{$t("Loading tracker and history…")}</div>
{:else if error && !tracker}
	<section class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 sm:p-8">
		<p class="text-sm font-medium text-amber-200">{$t("Tracker unavailable")}</p>
		<h1 class="mt-2 text-2xl font-semibold text-white">{$t("You cannot open this tracker.")}</h1>
		<p role="alert" class="mt-3 text-sm leading-6 text-slate-400">{$t(error)}</p>
		<a href="/modules/tracking" class="mt-6 inline-flex min-h-11 items-center rounded-xl border border-slate-700 px-4 text-sm font-semibold text-slate-200 hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{$t("Back to trackers")}</a>
	</section>
{:else if tracker}
	<section class="mx-auto max-w-screen-xl">
		<div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-800 pb-6">
			<div class="min-w-0">
				<a href="/modules/tracking" class="text-sm font-medium text-cyan-200 hover:text-cyan-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{$t("← All trackers")}</a>
				<div class="mt-4 flex flex-wrap items-center gap-2">
					<p class="text-sm font-medium text-cyan-200">{$t(tracker.visibility === 'household' ? 'Household template' : 'Private tracker')}</p>
					<span class="rounded-full border border-slate-700 px-2 py-0.5 text-xs text-slate-400">{$t("Schema v")}{tracker.schema_version}</span>
				</div>
				<h1 class="mt-2 break-words text-3xl font-semibold tracking-tight text-white sm:text-4xl">{tracker.name}</h1>
				{#if tracker.description}<p class="mt-3 max-w-2xl text-sm leading-6 text-slate-400">{tracker.description}</p>{/if}
			</div>
			{#if tracker.can_manage_schema}
				<div class="flex flex-wrap gap-2"><button type="button" onclick={beginSchemaEdit} class="min-h-11 rounded-xl border border-slate-700 px-4 text-sm font-semibold text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{$t("Edit schema")}</button><button type="button" disabled={deletingTracker} onclick={() => (deleteTrackerModalOpen = true)} class="min-h-11 rounded-xl border border-rose-300/20 px-4 text-sm font-semibold text-rose-200 transition hover:border-rose-300/50 hover:bg-rose-300/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-200 disabled:opacity-50">{$t(deletingTracker ? 'Deleting…' : 'Delete tracker')}</button></div>
			{/if}
		</div>

		{#if message}<p role="status" class="mt-6 rounded-xl border border-emerald-300/30 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">{$t(message)}</p>{/if}
		{#if error}<p role="alert" class="mt-6 rounded-xl border border-rose-300/30 bg-rose-300/10 px-4 py-3 text-sm text-rose-100">{$t(error)}</p>{/if}

		<Modal bind:open={schemaOpen} title={$t('Update tracker fields.')} description={$t('Removed fields remain in past measurements.')}>
			<section class="rounded-2xl border border-cyan-300/20 bg-slate-900/70 p-5 sm:p-8" aria-labelledby="edit-schema-heading">
				<div class="max-w-2xl">
					<p class="text-sm font-medium text-cyan-200">{$t("Schema builder · new revision")}</p>
					<h2 id="edit-schema-heading" class="mt-2 text-2xl font-semibold text-white">{$t("Update tracker fields.")}</h2>
					<p class="mt-2 text-sm leading-6 text-slate-400">{$t("Removed fields stay in past measurements. Once a field has values, its type and unit cannot change; add a new field for a new unit.")}</p>
				</div>
				<form class="mt-6 space-y-5" onsubmit={saveSchema}>
					<div class="grid gap-4 sm:grid-cols-2">
						<label class="block text-sm font-medium text-slate-200">{$t("Tracker name")}<input bind:value={schemaName} required maxlength="120" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
						<label class="block text-sm font-medium text-slate-200 sm:col-span-2">{$t("Description")}<textarea bind:value={schemaDescription} maxlength="500" rows="2" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"></textarea></label>
					</div>
					<div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-800 pt-5">
						<div><h3 class="font-semibold text-slate-100">{$t("Current fields")}</h3><p class="mt-1 text-sm text-slate-400">{$t("Leave a field out to retire it while retaining its history.")}</p></div>
						<button type="button" onclick={addSchemaField} class="min-h-10 rounded-lg border border-slate-700 px-3 text-sm font-medium text-slate-200 hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{$t("Add field")}</button>
					</div>
					<ol class="space-y-3">
						{#each schemaFields as field, index (field.local_key)}
							<li class="grid gap-3 rounded-xl border border-slate-800 bg-slate-950/60 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.2fr)_minmax(9rem,0.8fr)_minmax(8rem,0.7fr)_auto]">
								<label class="block text-sm font-medium text-slate-200">{$t("Field name")}<input bind:value={field.label} required maxlength="120" class="mt-2 min-h-11 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
								<label class="block text-sm font-medium text-slate-200">{$t("Type")}<select bind:value={field.type} class="mt-2 min-h-11 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"><option value="number">{$t("Number")}</option><option value="text">{$t("Text")}</option><option value="date">{$t("Date")}</option><option value="boolean">{$t("Yes / no")}</option></select></label>
								{#if field.type === 'number'}<label class="block text-sm font-medium text-slate-200">{$t("Unit")}<input bind:value={field.unit} required maxlength="32" class="mt-2 min-h-11 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>{/if}
								<div class="flex flex-wrap items-center gap-3 lg:justify-end"><label class="inline-flex min-h-11 items-center gap-2 text-sm text-slate-300"><input bind:checked={field.required} type="checkbox" class="size-4 rounded border-slate-600 bg-slate-900 text-cyan-300 focus:ring-cyan-300" />{$t("Required")}</label><button type="button" disabled={schemaFields.length <= 1} onclick={() => removeSchemaField(index)} class="min-h-10 rounded-lg px-3 text-sm text-rose-200 hover:bg-rose-300/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-200 disabled:opacity-40">{$t("Retire")}</button></div>
								{#if field.type === 'number'}<div class="grid gap-3 sm:col-span-2 sm:grid-cols-2 lg:col-span-4"><label class="text-xs font-medium text-slate-400">{$t("Minimum")}<input bind:value={field.minimum} inputmode="decimal" class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("No minimum")} /></label><label class="text-xs font-medium text-slate-400">{$t("Maximum")}<input bind:value={field.maximum} inputmode="decimal" class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("No maximum")} /></label></div>{/if}
							</li>
						{/each}
					</ol>
					<div class="flex flex-col-reverse gap-3 border-t border-slate-800 pt-5 sm:flex-row sm:justify-end">
						<button type="button" onclick={() => (schemaOpen = false)} class="min-h-11 rounded-xl border border-slate-700 px-4 text-sm font-semibold text-slate-200 hover:border-slate-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{$t("Cancel")}</button>
						<button type="submit" disabled={savingSchema} class="min-h-11 rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-100 disabled:opacity-60">{$t(savingSchema ? 'Saving revision…' : 'Save schema revision')}</button>
					</div>
				</form>
			</section>
		</Modal>

		{#if canCreate}
			<div class="mt-7 flex justify-end"><button type="button" onclick={() => { clearEntryForm(); entryModalOpen = true; }} class="min-h-11 rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 hover:bg-cyan-200">{$t('Record a measurement')}</button></div>
			<Modal bind:open={entryModalOpen} title={$t(editingEntryId ? 'Edit measurement' : 'Record a measurement')} description={$t('Your entries are private to your account by default.')}>
			<section class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5 shadow-xl shadow-black/10 sm:p-7" aria-labelledby="new-entry-heading">
				<div class="flex flex-wrap items-start justify-between gap-4">
					<div><p class="text-sm font-medium text-cyan-200">{$t("Quick entry")}</p><h2 id="new-entry-heading" class="mt-1 text-xl font-semibold text-slate-100">{$t(editingEntryId ? 'Edit measurement' : 'Record a measurement')}</h2><p class="mt-1 text-sm text-slate-400">{$t("Your entries are private to your account by default.")}</p></div>
					{#if editingEntryId}<button type="button" onclick={() => { clearEntryForm(); entryModalOpen = false; }} class="min-h-10 rounded-lg border border-slate-700 px-3 text-sm text-slate-300 hover:border-slate-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{$t("Cancel edit")}</button>{/if}
				</div>
				<form class="mt-5 space-y-5" onsubmit={saveEntry}>
					<div class="grid gap-4 sm:grid-cols-2">
						<label class="block text-sm font-medium text-slate-200">{$t("Recorded at")}<input bind:value={recordedAt} type="datetime-local" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" /></label>
						<label class="block text-sm font-medium text-slate-200">{$t("Note")} <span class="font-normal text-slate-500">{$t("(optional)")}</span><input bind:value={note} maxlength="1000" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("Add context")} /></label>
					</div>
					<div class="grid gap-4 sm:grid-cols-2">
						{#each activeFields as field (field.key)}
							<div class="min-w-0">
								{#if field.type === 'boolean'}
									<label class="flex min-h-14 items-center gap-3 rounded-xl border border-slate-800 bg-slate-950/60 px-4 text-sm font-medium text-slate-200"><input bind:checked={booleanValues[field.key]} type="checkbox" class="size-4 rounded border-slate-600 bg-slate-900 text-cyan-300 focus:ring-cyan-300" />{field.label}{$t(field.required ? ' · required' : '')}</label>
								{:else}
									<label class="block text-sm font-medium text-slate-200">{field.label}{$t(field.required ? ' · required' : '')}
										{#if field.type === 'number'}
										<span class="ml-1 text-xs font-normal text-slate-400">{field.unit}{field.minimum !== null || field.maximum !== null ? ' · ' + (field.minimum === null ? '−∞' : formatMeasurement(field.minimum, field.unit)) + ' to ' + (field.maximum === null ? '∞' : formatMeasurement(field.maximum, field.unit)) : ''}</span>
											<input bind:value={entryValues[field.key]} type="text" inputmode="decimal" required={field.required} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("Enter a decimal value")} />
										{:else if field.type === 'date'}
											<input bind:value={entryValues[field.key]} type="date" required={field.required} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" />
										{:else}
											<input bind:value={entryValues[field.key]} type="text" maxlength="5000" required={field.required} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" />
										{/if}
									</label>
								{/if}
							</div>
						{/each}
					</div>
					<div class="flex justify-end border-t border-slate-800 pt-4"><button type="submit" disabled={savingEntry} class="min-h-11 rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-100 disabled:cursor-wait disabled:opacity-60">{savingEntry ? 'Saving…' : editingEntryId ? 'Save changes' : 'Save measurement'}</button></div>
				</form>
			</section>
			</Modal>
		{/if}

		<section class="mt-8" aria-labelledby="history-heading">
			<div class="flex flex-wrap items-end justify-between gap-3"><div><h2 id="history-heading" class="text-xl font-semibold text-white">{$t("Measurement history")}</h2><p class="mt-1 text-sm text-slate-400">{$t("The latest")} {entries.length} {$t("entries you can view, newest first.")}</p></div></div>
			{#if numericColumns.length > 0}
				<div class="mt-5 grid gap-4 xl:grid-cols-2">
					{#each numericColumns as field (field.key)}
						{@const series = numericSeries(field)}
						<article class="rounded-2xl border border-slate-800 bg-slate-900/60 p-4 sm:p-5">
							<div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-slate-100">{field.label}</h3><p class="mt-1 text-xs text-slate-400">{series.length} {$t("saved values ·")} {field.unit ?? 'unit not set'}</p></div><span class="rounded-full border border-slate-700 px-2.5 py-1 text-xs text-slate-400">{field.unit ?? '—'}</span></div>
							{#if series.length > 0}
								<div class="mt-4 overflow-hidden rounded-xl border border-slate-800 bg-slate-950/70 p-2" role="img" aria-label={field.label + ' measurement history'}>
									<svg viewBox="0 0 1000 200" preserveAspectRatio="none" class="h-40 w-full">
										<path d="M0 30H1000M0 105H1000M0 180H1000" stroke="#1e293b" stroke-width="2" />
										{#if series.length > 1}<polyline points={chartPoints(field)} fill="none" stroke="#67e8f9" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />{/if}
										{#each series as point (point.at + point.x)}
											<circle cx={point.x} cy={point.y} r="8" fill="#67e8f9" stroke="#0f172a" stroke-width="4" vector-effect="non-scaling-stroke"><title>{point.displayedValue} {field.unit ?? ''} on {formatDate(point.at)}</title></circle>
										{/each}
									</svg>
								</div>
								<div class="mt-2 flex justify-between text-xs text-slate-500"><span>{formatDate(series[0].at)}</span><span>{formatDate(series[series.length - 1].at)}</span></div>
							{:else}<p class="mt-4 rounded-lg bg-slate-950/60 p-4 text-sm text-slate-400">{$t("No numeric values are available for this field yet.")}</p>{/if}
						</article>
					{/each}
				</div>
			{/if}

			{#if entries.length === 0}
				<div class="mt-5 rounded-2xl border border-slate-800 bg-slate-900/50 p-6 sm:p-8"><h3 class="text-lg font-semibold text-slate-100">{$t("No measurements recorded.")}</h3><p class="mt-2 text-sm leading-6 text-slate-400">{$t("This history only includes entries you own or that an owner explicitly shared with you.")}</p></div>
			{:else}
				<div class="mt-5 overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/50">
					<div class="overflow-x-auto">
						<table class="min-w-full border-collapse text-left text-sm">
							<thead class="bg-slate-950/80 text-xs uppercase tracking-wide text-slate-400"><tr><th scope="col" class="whitespace-nowrap px-4 py-3 sm:px-5">{$t("Recorded at")}</th>{#each historyColumns as field (field.key)}<th scope="col" class="whitespace-nowrap px-4 py-3 sm:px-5">{field.label}{field.unit ? ' (' + field.unit + ')' : ''}</th>{/each}<th scope="col" class="px-4 py-3 sm:px-5">{$t("Note")}</th><th scope="col"><span class="sr-only">{$t("Actions")}</span></th></tr></thead>
							<tbody class="divide-y divide-slate-800">
								{#each entries as entry (entry.id)}
									<tr class="align-top">
										<th scope="row" class="whitespace-nowrap px-4 py-4 font-medium text-slate-200 sm:px-5"><time datetime={entry.recorded_at}>{formatDate(entry.recorded_at)}</time><span class="mt-1 block text-xs font-normal text-slate-500">{$t("Schema v")}{entry.schema_version}</span></th>
										{#each historyColumns as field (field.key)}<td class="whitespace-nowrap px-4 py-4 text-slate-300 sm:px-5">{valueForEntry(entry, field.key)}</td>{/each}
										<td class="max-w-xs px-4 py-4 text-slate-400 sm:px-5">{entry.note ?? '—'}</td>
										<td class="px-4 py-4 text-right"><div class="flex justify-end gap-2">{#if entry.can_edit}<button type="button" onclick={() => beginEdit(entry)} class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-200 hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{$t("Edit")}</button><button type="button" disabled={deletingEntryId === entry.id} onclick={() => { deleteTargetEntry = entry; deleteModalOpen = true; }} class="rounded-lg border border-rose-300/20 px-3 py-2 text-xs font-semibold text-rose-200 hover:border-rose-300/50 hover:bg-rose-300/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-200 disabled:opacity-50">{$t(deletingEntryId === entry.id ? 'Deleting…' : 'Delete')}</button>{/if}</div></td>
									</tr>
								{/each}
							</tbody>
						</table>
					</div>
					<p class="border-t border-slate-800 px-4 py-3 text-xs leading-5 text-slate-500">{$t("Showing {count} measurements. Values keep the field label and unit that were active when they were saved.", { count: entries.length })}</p>
				</div>
				{#if hasMoreEntries}<div class="mt-4 flex flex-col items-center gap-2"><div bind:this={loadMoreSentinel} class="h-px w-full" aria-hidden="true"></div><button type="button" onclick={() => void loadMoreEntries()} disabled={loadingMoreEntries} aria-live="polite" class="min-h-11 rounded-xl border border-slate-700 px-5 text-sm font-semibold text-slate-200 transition hover:border-cyan-300 hover:text-cyan-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:cursor-wait disabled:opacity-50">{$t(loadingMoreEntries ? 'Loading…' : 'Load more measurements')}</button></div>{/if}
			{/if}
		</section>
		{#if deleteTargetEntry}<ConfirmModal bind:open={deleteModalOpen} title={$t('Delete measurement?')} message={$t('Delete this measurement?')} busy={deletingEntryId === deleteTargetEntry.id} onConfirm={() => void deleteEntry(deleteTargetEntry!)} />{/if}
		{#if tracker}<ConfirmModal bind:open={deleteTrackerModalOpen} title={$t('Delete tracker?')} message={$t('Delete “') + tracker.name + $t('” and all its measurements?')} busy={deletingTracker} onConfirm={() => void deleteTracker()} />{/if}

		{#if revisions.length > 0}
			<details class="mt-7 rounded-2xl border border-slate-800 bg-slate-900/40 p-5">
				<summary class="cursor-pointer font-semibold text-slate-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{$t("Schema revisions")} <span class="ml-1 text-sm font-normal text-slate-400">({revisions.length})</span></summary>
				<ol class="mt-4 divide-y divide-slate-800">
					{#each revisions as revision (revision.version)}
						<li class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between"><span class="text-sm text-slate-200">{$t("Version")} {revision.version} · {revision.schema.name} · {revision.schema.fields.length} {$t(revision.schema.fields.length === 1 ? 'field' : 'fields')}</span><time datetime={revision.created_at} class="text-xs text-slate-500">{formatDate(revision.created_at)}</time></li>
					{/each}
				</ol>
			</details>
		{/if}
	</section>
{/if}
