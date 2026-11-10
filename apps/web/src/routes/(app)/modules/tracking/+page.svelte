<script lang="ts">
	import Badge from '#lib/ui/Badge.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import Button from '#lib/ui/Button.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import { numberLocale, t, visibilityLabel } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { page } from '$app/state';
	import { getContext, onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import Modal from '#lib/ui/Modal.svelte';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';
	import { canonicalizeDecimalInput } from '#lib/number-format';
	import { newTrackerField, type Tracker, type TrackerFieldDraft, type TrackerResponse } from '#lib/tracking';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'tracking'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreatePrivate = $derived(access?.capabilities.create ?? false);
	const canCreateShared = $derived(shell.currentUser?.is_admin === true && (access?.capabilities.manage ?? false));
	const createRequested = $derived(page.url.searchParams.get('create') === '1');

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

	$effect(() => {
		if (!createRequested || (!canCreatePrivate && !canCreateShared)) return;
		createOpen = true;
		void goto('/modules/tracking', { replaceState: true });
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
			minimum: field.type === 'number' && field.minimum !== '' ? canonicalizeDecimalInput(field.minimum, $numberLocale) : null,
			maximum: field.type === 'number' && field.maximum !== '' ? canonicalizeDecimalInput(field.maximum, $numberLocale) : null
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
	<PageHeading eyebrow="Tracking module" title="Make your own measures" description="Build a small schema around what matters to your household. Each measurement is saved with its own owner, date and unit." />

	{#if message}<Notice tone="success" message={message} class="mt-6" />{/if}
	{#if error}<Notice tone="error" message={error} class="mt-6" />{/if}

	{#if canView}
		<section class="mt-8" aria-labelledby="trackers-heading">
			<div class="flex flex-wrap items-center justify-between gap-3">
				<div><h2 id="trackers-heading" class="text-xl font-semibold text-ink">{$t("Your trackers")}</h2><p class="mt-1 text-sm text-ink-muted">{$t("Shared templates are available to members with tracking access; entries stay private to each owner.")}</p></div>
				<button type="button" onclick={() => void loadTrackers()} disabled={loading} class="rounded-lg border border-line-strong px-3 py-2 text-sm font-medium text-ink-secondary hover:border-accent-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink disabled:opacity-50">{$t("Refresh")}</button>
			</div>
			{#if loading}
				<p role="status" class="mt-5 rounded-xl border glass-surface px-4 py-5 text-sm text-ink-secondary">{$t("Loading trackers…")}</p>
			{:else if trackers.length === 0}
				<div class="mt-5 rounded-[20px] border glass-surface p-6 sm:p-8">
					<h3 class="text-lg font-semibold text-ink">{$t("No trackers yet.")}</h3>
					<p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted">{$t("Create a private tracker for yourself, or ask an administrator to create a household template. Existing measurements are never visible to administrators unless their owner shares them.")}</p>
				</div>
			{:else}
				<ul class="mt-5 divide-y divide-line rounded-[20px] border glass-surface">
					{#each trackers as tracker (tracker.id)}
						<li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
							<a href={'/modules/tracking/' + tracker.id} class="group flex min-h-16 min-w-0 flex-1 flex-col gap-4 transition hover:bg-surface focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-accent-ink sm:flex-row sm:items-center sm:justify-between">
								<span class="min-w-0">
									<span class="flex flex-wrap items-center gap-2">
										<span class="font-semibold text-ink">{tracker.name}</span>
										<Badge>{$t(visibilityLabel(tracker.visibility))}</Badge>
									</span>
									<span class="mt-1 block text-sm leading-5 text-ink-muted">{tracker.description ?? tracker.fields.map((field) => field.label).join(' · ')}</span>
									<span class="mt-2 block text-xs text-ink-subtle">{tracker.fields.length} {$t(tracker.fields.length === 1 ? 'field' : 'fields')} {$t("· schema v")}{tracker.schema_version}</span>
								</span>
								<span class="inline-flex items-center gap-2 text-sm font-medium text-accent-ink group-hover:text-accent-ink">{$t("Open history")} <span aria-hidden="true">→</span></span>
							</a>
							{#if tracker.can_manage_schema}<button type="button" disabled={deletingTrackerId === tracker.id} onclick={() => { trackerToDelete = tracker; deleteModalOpen = true; }} class="min-h-10 shrink-0 rounded-lg border border-danger/20 px-3 text-sm font-medium text-danger hover:border-danger/50 hover:bg-danger/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-danger disabled:opacity-50">{$t(deletingTrackerId === tracker.id ? 'Deleting…' : 'Delete tracker')}</button>{/if}
						</li>
					{/each}
				</ul>
			{/if}
		</section>
	{:else}
		<div class="mt-8 rounded-2xl border border-warning/20 bg-warning/5 p-5 text-sm leading-6 text-warning">
			<p class="font-semibold">{$t("View access is not enabled.")}</p>
			<p class="mt-1 text-warning/75">{$t("You can create a tracker if you have create access, but browsing schemas and histories requires an explicit View grant.")}</p>
		</div>
	{/if}

	{#if canCreatePrivate || canCreateShared}
		<div class="mt-8 flex justify-end"><button type="button" onclick={() => (createOpen = true)} class="min-h-11 rounded-xl bg-accent px-5 text-sm font-semibold text-on-accent hover:bg-accent/90">{$t("Create tracker")}</button></div>
		<Modal bind:open={createOpen} size="wide" title={$t('Start with a tracker.')} description={$t('Build a schema around the measurements you want to follow.')}>
			{#if error}<Notice tone="error" message={error} class="mb-5" />{/if}
			<form id="tracker-create-form" class="grid gap-x-8 gap-y-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]" onsubmit={createTracker}>
				<div class="grid h-fit gap-4 sm:grid-cols-2 lg:grid-cols-1">
					<label class="block text-sm font-medium text-ink-secondary">{$t("Tracker name")}
						<input bind:value={name} required maxlength="120" class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none placeholder:text-ink-subtle focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("Daily steps")} />
					</label>
					<label class="block text-sm font-medium text-ink-secondary">{$t("Visibility")}
						<select bind:value={visibility} class="mt-2 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base">
							{#if canCreatePrivate}<option value="private">{$t("Private to me")}</option>{/if}
							{#if canCreateShared}<option value="household">{$t("Household template")}</option>{/if}
						</select>
					</label>
					<label class="block text-sm font-medium text-ink-secondary sm:col-span-2 lg:col-span-1">{$t("Description")} <span class="font-normal text-ink-subtle">{$t("(optional)")}</span>
						<textarea bind:value={description} maxlength="500" rows="4" class="mt-2 w-full rounded-[10px] border border-line-strong bg-page px-3 py-2.5 text-sm leading-6 text-ink outline-none placeholder:text-ink-subtle focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("What does this tracker help you follow?")}></textarea>
					</label>
				</div>

				<div class="border-t border-line pt-6 lg:border-t-0 lg:pt-0">
					<div class="flex flex-wrap items-end justify-between gap-3">
						<div><h3 class="text-base font-semibold text-ink">{$t("Fields")}</h3><p class="mt-1 text-sm text-ink-muted">{$t("Add numeric, text, date or yes/no fields.")}</p></div>
						<button type="button" onclick={addField} class="min-h-10 rounded-lg border border-line-strong px-3 py-2 text-sm font-medium text-ink-secondary hover:border-accent-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink">{$t("Add field")}</button>
					</div>
					<ol class="mt-4 space-y-4">
						{#each fields as field, index (field.local_key)}
							<li class="rounded-xl border border-line bg-page p-4">
								<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.2fr)_minmax(10rem,0.8fr)_minmax(9rem,0.7fr)_auto]">
									<label class="block text-sm font-medium text-ink-secondary">{$t("Field name")}
										<input bind:value={field.label} required maxlength="120" class="mt-2 min-h-11 w-full rounded-[10px] border glass-control px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("Steps")} />
									</label>
									<label class="block text-sm font-medium text-ink-secondary">{$t("Type")}
										<select bind:value={field.type} class="mt-2 min-h-11 w-full rounded-[10px] border glass-control px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base">
											<option value="number">{$t("Number")}</option><option value="text">{$t("Text")}</option><option value="date">{$t("Date")}</option><option value="boolean">{$t("Yes / no")}</option>
										</select>
									</label>
									{#if field.type === 'number'}
										<label class="block text-sm font-medium text-ink-secondary">{$t("Unit")}
											<input bind:value={field.unit} required maxlength="32" class="mt-2 min-h-11 w-full rounded-[10px] border glass-control px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("kg, steps…")} />
										</label>
									{:else}
										<div class="hidden lg:block"></div>
									{/if}
									<div class="flex flex-wrap items-center gap-3 pt-1 sm:col-span-2 lg:col-span-1 lg:justify-end">
										<label class="inline-flex min-h-11 items-center gap-2 text-sm text-ink-secondary"><input bind:checked={field.required} type="checkbox" class="size-4 rounded border-line-strong bg-surface text-accent-ink focus:ring-accent-ink" />{$t("Required")}</label>
										<button type="button" disabled={fields.length <= 1} onclick={() => removeField(index)} class="min-h-10 rounded-lg px-3 text-sm font-medium text-danger hover:bg-danger/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-danger disabled:opacity-40">{$t("Remove")}</button>
									</div>
								</div>
								{#if field.type === 'number'}
									<div class="mt-4 grid gap-4 sm:grid-cols-2">
										<label class="block text-xs font-medium text-ink-muted">{$t("Minimum")} <span class="font-normal">{$t("(optional)")}</span><input bind:value={field.minimum} inputmode="decimal" class="mt-2 min-h-10 w-full rounded-[10px] border glass-control px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("No minimum")} /></label>
										<label class="block text-xs font-medium text-ink-muted">{$t("Maximum")} <span class="font-normal">{$t("(optional)")}</span><input bind:value={field.maximum} inputmode="decimal" class="mt-2 min-h-10 w-full rounded-[10px] border glass-control px-3 text-sm text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" placeholder={$t("No maximum")} /></label>
									</div>
								{/if}
							</li>
						{/each}
					</ol>
				</div>

				<div class="space-y-2 text-xs leading-5 text-ink-subtle lg:col-span-2">
					<p>{$t("Number fields require a unit and are stored as exact decimals. Field type and unit are preserved after the first saved value.")}</p>
					<p>{$t("Private trackers and entries remain hidden from administrators unless explicitly shared by their owner.")}</p>
				</div>
			</form>
			{#snippet footer()}
				<Button variant="secondary" type="button" disabled={saving} onclick={() => (createOpen = false)}>{$t('Cancel')}</Button>
				<Button type="submit" form="tracker-create-form" disabled={saving}>{$t(saving ? 'Creating…' : 'Create tracker')}</Button>
			{/snippet}
		</Modal>
		{#if trackerToDelete}<ConfirmModal bind:open={deleteModalOpen} title={$t('Delete tracker?')} message={$t('Delete “{name}” and all its measurements?', { name: trackerToDelete.name })} busy={deletingTrackerId === trackerToDelete.id} onConfirm={() => void deleteTracker(trackerToDelete!)} />{/if}
	{:else}
		<p class="mt-8 rounded-xl border glass-surface px-4 py-5 text-sm leading-6 text-ink-muted">{$t("Your account does not have permission to create a tracker. Ask an administrator for create access.")}</p>
	{/if}
</section>
