<script lang="ts">
	import { t } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { getContext, onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import Modal from '#lib/ui/Modal.svelte';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';
	import { newTrackerField, type Tracker, type TrackerFieldDraft, type TrackerResponse } from '#lib/tracking';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'tracking'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreatePrivate = $derived(access?.capabilities.create ?? false);
	const canCreateShared = $derived(shell.currentUser?.is_admin === true && (access?.capabilities.manage ?? false));

	let trackers = $state<Tracker[]>([]);
	let loading = $state(true);
	let saving = $state(false);
	let deletingTrackerId = $state<number | null>(null);
	let trackerToDelete = $state<Tracker | null>(null);
	let deleteModalOpen = $state(false);
	let createOpen = $state(false);
	let error = $state('');
	let message = $state('');
	let visibility = $state<'private' | 'household'>('private');
	let name = $state('');
	let description = $state('');
	let nextLocalKey = 2;
	let fields = $state<TrackerFieldDraft[]>([newTrackerField(1)]);

	onMount(() => {
		void loadTrackers();
	});

	async function loadTrackers(): Promise<void> {
		loading = true;
		error = '';

		if (!canView) {
			trackers = [];
			loading = false;
			return;
		}

		try {
			const response = await fetch('/api/tracking/trackers', {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' }
			});
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			const result = (await response.json()) as TrackerResponse;
			if (!response.ok) throw new Error(result.message ?? 'Trackers are unavailable. Check your access and try again.');
			trackers = result.trackers ?? [];
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Trackers are unavailable. Check your connection and try again.';
		} finally {
			loading = false;
		}
	}

	function addField(): void {
		fields = [...fields, newTrackerField(nextLocalKey++)];
	}

	function removeField(index: number): void {
		if (fields.length <= 1) return;
		fields = fields.filter((_, fieldIndex) => fieldIndex !== index);
	}

	function apiFields(): Array<Record<string, string | boolean | number | null>> {
		return fields.map((field) => ({
			label: field.label,
			type: field.type,
			unit: field.type === 'number' ? field.unit : null,
			is_required: field.required,
			minimum: field.type === 'number' && field.minimum !== '' ? field.minimum : null,
			maximum: field.type === 'number' && field.maximum !== '' ? field.maximum : null
		}));
	}

	async function createTracker(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		message = '';
		error = '';
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}
		if (visibility === 'private' && !canCreatePrivate) {
			error = 'Your administrator must grant create access for a private tracker.';
			return;
		}
		if (visibility === 'household' && !canCreateShared) {
			error = 'Only an active administrator with manage access can create a household tracker.';
			return;
		}

		saving = true;
		try {
			const response = await fetch('/api/tracking/trackers', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ name, description, visibility, fields: apiFields() })
			});
			const result = (await response.json()) as TrackerResponse & ApiResponse;
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (!response.ok || !result.tracker) {
				error = firstError(result) || result.message || 'The tracker could not be created.';
				return;
			}

			message = visibility === 'household' ? 'Household tracker created.' : 'Private tracker created.';
			name = '';
			description = '';
			visibility = canCreatePrivate ? 'private' : 'household';
			fields = [newTrackerField(nextLocalKey++)];
			if (canView) {
				await goto('/modules/tracking/' + result.tracker.id);
				return;
			}
			message += ' Grant View access to open its schema and history.';
			await loadTrackers();
		} catch {
			error = 'The tracker could not be created. Check your connection and try again.';
		} finally {
			saving = false;
		}
	}

	async function deleteTracker(tracker: Tracker): Promise<void> {
		if (!tracker.can_manage_schema) return;
		const token = csrfToken();
		if (!token) { error = 'Reload this page and try again.'; return; }
		deletingTrackerId = tracker.id;
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
			message = 'Tracker deleted.';
			trackerToDelete = null;
			await loadTrackers();
		} catch {
			error = 'The tracker could not be deleted. Check your connection and try again.';
		} finally {
			deletingTrackerId = null;
		}
	}
</script>

<svelte:head>
	<title>{$t("Tracking")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Build household trackers and record private measurements over time.")} />
</svelte:head>

<section class="mx-auto max-w-screen-xl">
	<div class="flex flex-wrap items-end justify-between gap-4 border-b border-slate-800 pb-6">
		<div>
			<p class="text-sm font-medium tracking-wide text-cyan-200">{$t("Tracking module")}</p>
			<h1 class="mt-2 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{$t("Make your own measures.")}</h1>
		</div>
		<p class="max-w-xl text-sm leading-6 text-slate-400">{$t("Build a small schema around what matters to your household. Each measurement is saved with its own owner, date and unit.")}</p>
	</div>

	{#if message}<p role="status" class="mt-6 rounded-xl border border-emerald-300/30 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">{$t(message)}</p>{/if}
	{#if error}<p role="alert" class="mt-6 rounded-xl border border-rose-300/30 bg-rose-300/10 px-4 py-3 text-sm text-rose-100">{$t(error)}</p>{/if}

	{#if canView}
		<section class="mt-8" aria-labelledby="trackers-heading">
			<div class="flex flex-wrap items-center justify-between gap-3">
				<div><h2 id="trackers-heading" class="text-xl font-semibold text-slate-100">{$t("Your trackers")}</h2><p class="mt-1 text-sm text-slate-400">{$t("Shared templates are available to members with tracking access; entries stay private to each owner.")}</p></div>
				<button type="button" onclick={() => void loadTrackers()} disabled={loading} class="rounded-lg border border-slate-700 px-3 py-2 text-sm font-medium text-slate-200 hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:opacity-50">{$t("Refresh")}</button>
			</div>
			{#if loading}
				<p role="status" class="mt-5 rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-5 text-sm text-slate-300">{$t("Loading trackers…")}</p>
			{:else if trackers.length === 0}
				<div class="mt-5 rounded-2xl border border-slate-800 bg-slate-900/50 p-6 sm:p-8">
					<h3 class="text-lg font-semibold text-slate-100">{$t("No trackers yet.")}</h3>
					<p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">{$t("Create a private tracker for yourself, or ask an administrator to create a household template. Existing measurements are never visible to administrators unless their owner shares them.")}</p>
				</div>
			{:else}
				<ul class="mt-5 divide-y divide-slate-800 rounded-2xl border border-slate-800 bg-slate-900/50">
					{#each trackers as tracker (tracker.id)}
						<li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
							<a href={'/modules/tracking/' + tracker.id} class="group flex min-h-16 min-w-0 flex-1 flex-col gap-4 transition hover:bg-slate-900 focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-cyan-300 sm:flex-row sm:items-center sm:justify-between">
								<span class="min-w-0">
									<span class="flex flex-wrap items-center gap-2">
										<span class="font-semibold text-slate-100">{tracker.name}</span>
										<span class="rounded-full border border-slate-700 px-2 py-0.5 text-[11px] font-medium uppercase tracking-wide text-slate-400">{tracker.visibility}</span>
									</span>
									<span class="mt-1 block text-sm leading-5 text-slate-400">{tracker.description ?? tracker.fields.map((field) => field.label).join(' · ')}</span>
									<span class="mt-2 block text-xs text-slate-500">{tracker.fields.length} {$t(tracker.fields.length === 1 ? 'field' : 'fields')} {$t("· schema v")}{tracker.schema_version}</span>
								</span>
								<span class="inline-flex items-center gap-2 text-sm font-medium text-cyan-200 group-hover:text-cyan-100">{$t("Open history")} <span aria-hidden="true">→</span></span>
							</a>
							{#if tracker.can_manage_schema}<button type="button" disabled={deletingTrackerId === tracker.id} onclick={() => { trackerToDelete = tracker; deleteModalOpen = true; }} class="min-h-10 shrink-0 rounded-lg border border-rose-300/20 px-3 text-sm font-medium text-rose-200 hover:border-rose-300/50 hover:bg-rose-300/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-200 disabled:opacity-50">{$t(deletingTrackerId === tracker.id ? 'Deleting…' : 'Delete tracker')}</button>{/if}
						</li>
					{/each}
				</ul>
			{/if}
		</section>
	{:else}
		<div class="mt-8 rounded-2xl border border-amber-300/20 bg-amber-300/5 p-5 text-sm leading-6 text-amber-100">
			<p class="font-semibold">{$t("View access is not enabled.")}</p>
			<p class="mt-1 text-amber-100/75">{$t("You can create a tracker if you have create access, but browsing schemas and histories requires an explicit View grant.")}</p>
		</div>
	{/if}

	{#if canCreatePrivate || canCreateShared}
		<div class="mt-8 flex justify-end"><button type="button" onclick={() => (createOpen = true)} class="min-h-11 rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 hover:bg-cyan-200">{$t("Create tracker")}</button></div>
		<Modal bind:open={createOpen} title={$t('Start with a tracker.')} description={$t('Build a schema around the measurements you want to follow.')}>
			<div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5 sm:p-7" aria-labelledby="new-tracker-heading">
			<div class="max-w-2xl">
				<p class="text-sm font-medium text-cyan-200">{$t("Schema builder")}</p>
				<h2 id="new-tracker-heading" class="mt-2 text-2xl font-semibold tracking-tight text-white">{$t("Start with a tracker.")}</h2>
				<p class="mt-2 text-sm leading-6 text-slate-400">{$t("Number fields require a unit and are stored as exact decimals. Field type and unit are preserved after the first saved value.")}</p>
			</div>
			<form class="mt-7 space-y-6" onsubmit={createTracker}>
				<div class="grid gap-4 sm:grid-cols-2">
					<label class="block text-sm font-medium text-slate-200">{$t("Tracker name")}
						<input bind:value={name} required maxlength="120" class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("Daily steps")} />
					</label>
					<label class="block text-sm font-medium text-slate-200">{$t("Visibility")}
						<select bind:value={visibility} class="mt-2 min-h-11 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20">
							{#if canCreatePrivate}<option value="private">{$t("Private to me")}</option>{/if}
							{#if canCreateShared}<option value="household">{$t("Household template")}</option>{/if}
						</select>
					</label>
					<label class="block text-sm font-medium text-slate-200 sm:col-span-2">{$t("Description")} <span class="font-normal text-slate-500">{$t("(optional)")}</span>
						<textarea bind:value={description} maxlength="500" rows="2" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm leading-6 text-white outline-none placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("What does this tracker help you follow?")}></textarea>
					</label>
				</div>

				<div class="border-t border-slate-800 pt-6">
					<div class="flex flex-wrap items-end justify-between gap-3">
						<div><h3 class="text-base font-semibold text-slate-100">{$t("Fields")}</h3><p class="mt-1 text-sm text-slate-400">{$t("Add numeric, text, date or yes/no fields.")}</p></div>
						<button type="button" onclick={addField} class="min-h-10 rounded-lg border border-slate-700 px-3 py-2 text-sm font-medium text-slate-200 hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{$t("Add field")}</button>
					</div>
					<ol class="mt-4 space-y-4">
						{#each fields as field, index (field.local_key)}
							<li class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
								<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.2fr)_minmax(10rem,0.8fr)_minmax(9rem,0.7fr)_auto]">
									<label class="block text-sm font-medium text-slate-200">{$t("Field name")}
										<input bind:value={field.label} required maxlength="120" class="mt-2 min-h-11 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("Steps")} />
									</label>
									<label class="block text-sm font-medium text-slate-200">{$t("Type")}
										<select bind:value={field.type} class="mt-2 min-h-11 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20">
											<option value="number">{$t("Number")}</option><option value="text">{$t("Text")}</option><option value="date">{$t("Date")}</option><option value="boolean">{$t("Yes / no")}</option>
										</select>
									</label>
									{#if field.type === 'number'}
										<label class="block text-sm font-medium text-slate-200">{$t("Unit")}
											<input bind:value={field.unit} required maxlength="32" class="mt-2 min-h-11 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("kg, steps…")} />
										</label>
									{:else}
										<div class="hidden lg:block"></div>
									{/if}
									<div class="flex flex-wrap items-center gap-3 pt-1 sm:col-span-2 lg:col-span-1 lg:justify-end">
										<label class="inline-flex min-h-11 items-center gap-2 text-sm text-slate-300"><input bind:checked={field.required} type="checkbox" class="size-4 rounded border-slate-600 bg-slate-900 text-cyan-300 focus:ring-cyan-300" />{$t("Required")}</label>
										<button type="button" disabled={fields.length <= 1} onclick={() => removeField(index)} class="min-h-10 rounded-lg px-3 text-sm font-medium text-rose-200 hover:bg-rose-300/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-200 disabled:opacity-40">{$t("Remove")}</button>
									</div>
								</div>
								{#if field.type === 'number'}
									<div class="mt-4 grid gap-4 sm:grid-cols-2">
										<label class="block text-xs font-medium text-slate-400">{$t("Minimum")} <span class="font-normal">{$t("(optional)")}</span><input bind:value={field.minimum} inputmode="decimal" class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("No minimum")} /></label>
										<label class="block text-xs font-medium text-slate-400">{$t("Maximum")} <span class="font-normal">{$t("(optional)")}</span><input bind:value={field.maximum} inputmode="decimal" class="mt-2 min-h-10 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 text-sm text-white outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" placeholder={$t("No maximum")} /></label>
									</div>
								{/if}
							</li>
						{/each}
					</ol>
				</div>

				<div class="flex flex-col-reverse gap-3 border-t border-slate-800 pt-5 sm:flex-row sm:items-center sm:justify-between">
					<p class="text-xs leading-5 text-slate-500">{$t("Private trackers and entries remain hidden from administrators unless explicitly shared by their owner.")}</p>
					<button type="submit" disabled={saving} class="min-h-11 rounded-xl bg-cyan-300 px-5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-100 disabled:cursor-wait disabled:opacity-60">{$t(saving ? 'Creating…' : 'Create tracker')}</button>
				</div>
			</form>
			</div>
		</Modal>
		{#if trackerToDelete}<ConfirmModal bind:open={deleteModalOpen} title={$t('Delete tracker?')} message={$t('Delete “') + trackerToDelete.name + $t('” and all its measurements?')} busy={deletingTrackerId === trackerToDelete.id} onConfirm={() => void deleteTracker(trackerToDelete!)} />{/if}
	{:else}
		<p class="mt-8 rounded-xl border border-slate-800 bg-slate-900/40 px-4 py-5 text-sm leading-6 text-slate-400">{$t("Your account does not have permission to create a tracker. Ask an administrator for create access.")}</p>
	{/if}
</section>
