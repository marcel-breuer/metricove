<script lang="ts">
	import { Card } from '#lib/components/ui/card/index.js';
	import { Alert } from '#lib/components/ui/alert/index.js';
	import { Checkbox } from '#lib/components/ui/checkbox/index.js';
	import { Input } from '#lib/components/ui/input/index.js';
	import { Label } from '#lib/components/ui/label/index.js';
	import Button from '#lib/ui/Button.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { csrfToken, type ApiResponse } from '#lib/auth';
	import { formatDate } from '#lib/i18n';
	import { t } from '#lib/i18n';
	type Reminder = { id: number; source_module: string; title: string; due_on: string; href: string; is_read: boolean };
	type Preferences = { email_enabled: boolean; lead_days: number; sources: Record<string, boolean> };
	type Response = ApiResponse & { reminders?: Reminder[]; unread_count?: number; preferences?: Preferences };
	const defaultSources = { vehicles: true, utilities: true, pantry: true, tasks: true };
	let reminders = $state<Reminder[]>([]);
	let emailEnabled = $state(false);
	let leadDays = $state(3);
	let sources = $state<Record<string, boolean>>({ ...defaultSources });
	let loading = $state(true);
	let saving = $state(false);
	let error = $state('');
	let message = $state('');

	onMount(() => { void load(); });

	async function load(): Promise<void> {
		loading = true; error = '';
		try {
			const [inboxResponse, preferencesResponse] = await Promise.all([
				fetch('/api/reminders', { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
				fetch('/api/reminders/preferences', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			]);
			if (inboxResponse.status === 401 || preferencesResponse.status === 401) { await goto('/login'); return; }
			const inbox = (await inboxResponse.json()) as Response;
			const preferenceResult = (await preferencesResponse.json()) as Response;
			if (!inboxResponse.ok || !preferencesResponse.ok || !preferenceResult.preferences) throw new Error(inbox.message ?? preferenceResult.message ?? 'Reminders are unavailable.');
			reminders = inbox.reminders ?? [];
			emailEnabled = preferenceResult.preferences.email_enabled;
			leadDays = preferenceResult.preferences.lead_days;
			sources = { ...defaultSources, ...preferenceResult.preferences.sources };
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Reminders are unavailable. Check your connection and try again.';
		} finally { loading = false; }
	}

	async function savePreferences(event: SubmitEvent): Promise<void> {
		event.preventDefault(); error = ''; message = '';
		const token = csrfToken();
		if (!token) { error = 'Reload this page and try again.'; return; }
		saving = true;
		try {
			const response = await fetch('/api/reminders/preferences', {
				method: 'PATCH', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ email_enabled: emailEnabled, lead_days: leadDays, sources })
			});
			const result = (await response.json()) as Response;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok || !result.preferences) throw new Error(result.message ?? 'Reminder preferences could not be saved.');
			emailEnabled = result.preferences.email_enabled;
			leadDays = result.preferences.lead_days;
			sources = { ...defaultSources, ...result.preferences.sources };
			message = 'Reminder preferences saved.';
			await load();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Reminder preferences could not be saved.';
		} finally { saving = false; }
	}

	async function updateReminder(reminder: Reminder, action: 'read' | 'dismiss'): Promise<void> {
		error = ''; message = '';
		const token = csrfToken();
		if (!token) { error = 'Reload this page and try again.'; return; }
		try {
			const response = await fetch(`/api/reminders/${reminder.id}`, {
				method: 'PATCH', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ action })
			});
			const result = (await response.json()) as Response;
			if (response.status === 401) { await goto('/login'); return; }
			if (!response.ok) throw new Error(result.message ?? 'The reminder could not be updated.');
			await load();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The reminder could not be updated.';
		}
	}
</script>

<svelte:head>
	<title>{$t('Reminders')} {$t('· Metricove')}</title>
	<meta name="description" content={$t('Review upcoming household reminders and manage how you receive them.')} />
</svelte:head>

<section class="mx-auto max-w-screen-xl">
	<PageHeading eyebrow="Household" title="Reminders" description="Keep upcoming bills, service dates, utility alerts and task checklists in one place. Reminders only show records you can currently view." />
	{#if message}<Notice tone="success" message={message} class="mt-6" />{/if}
	{#if error}<Notice tone="error" message={error} class="mt-6" />{/if}

	<Card as="section" variant="glass" class="mt-8 rounded-[20px] p-5 sm:p-6" aria-labelledby="inbox-heading">
		<div class="flex flex-wrap items-end justify-between gap-3">
			<div><h2 id="inbox-heading" class="text-xl font-semibold text-ink">{$t('Upcoming')}</h2><p class="mt-1 text-sm text-ink-muted">{reminders.filter((reminder) => !reminder.is_read).length} {$t('unread reminders')}</p></div>
			<Button variant="secondary" size="small" type="button" onclick={() => void load()} disabled={loading} class="rounded-lg border-line-strong bg-transparent px-3 py-2 text-sm font-medium text-ink-secondary hover:border-accent-ink focus-visible:outline-accent-ink disabled:opacity-50">{$t('Refresh')}</Button>
		</div>
		{#if loading}
			<Alert variant="plain" role="status" class="mt-5 text-sm text-ink-muted">{$t('Loading reminders…')}</Alert>
		{:else if reminders.length === 0}
			<Card variant="inset" class="mt-5 rounded-xl px-4 py-5 text-sm text-ink-muted">{$t('No upcoming reminders. They appear here when enabled modules have an obligation or alert within your reminder window.')}</Card>
		{:else}
			<ul class="mt-5 divide-y divide-line">
				{#each reminders as reminder (reminder.id)}
					<li class="flex flex-wrap items-center justify-between gap-3 py-4 {reminder.is_read ? 'opacity-75' : ''}">
						<div class="min-w-0"><p class="font-medium text-ink">{reminder.title}</p><p class="mt-1 text-sm text-ink-muted">{$t('Due')} · {formatDate(reminder.due_on)}</p><a href={reminder.href} class="mt-1 inline-flex text-sm font-medium text-accent-ink hover:text-accent-ink">{$t('Open record')} <span class="ml-1" aria-hidden="true">→</span></a></div>
						<div class="flex gap-2">
							{#if !reminder.is_read}<Button variant="secondary" size="small" type="button" onclick={() => void updateReminder(reminder, 'read')} class="min-h-10 rounded-lg border-line-strong bg-transparent px-3 text-sm font-normal text-ink-secondary hover:border-accent-ink focus-visible:outline-accent-ink">{$t('Mark read')}</Button>{/if}
							<Button variant="secondary" size="small" type="button" onclick={() => void updateReminder(reminder, 'dismiss')} class="min-h-10 rounded-lg border-line-strong bg-transparent px-3 text-sm font-normal text-ink-secondary hover:border-danger focus-visible:outline-danger">{$t('Dismiss')}</Button>
						</div>
					</li>
				{/each}
			</ul>
		{/if}
	</Card>

	<Card as="section" variant="glass" class="mt-8 rounded-[20px] p-5 sm:p-6" aria-labelledby="settings-heading">
		<div><h2 id="settings-heading" class="text-xl font-semibold text-ink">{$t('Reminder settings')}</h2><p class="mt-1 text-sm text-ink-muted">{$t('Email is optional and uses the SMTP settings configured for this installation.')}</p></div>
		<form class="mt-5 space-y-5" onsubmit={savePreferences}>
			<Label variant="field" class="flex items-start gap-3 text-sm text-ink-secondary"><Checkbox bind:checked={emailEnabled} class="mt-0.5 border-line-strong bg-page data-checked:border-accent data-checked:bg-accent data-checked:text-on-accent" /><span><span class="font-medium">{$t('Send reminder emails')}</span><span class="mt-1 block text-ink-muted">{$t('Email details are sent only while you retain permission to view the source record.')}</span></span></Label>
			<Label variant="field" for="reminder-lead-days" class="block max-w-sm gap-0 text-sm font-medium text-ink-secondary">{$t('Days in advance')}<Input id="reminder-lead-days" variant="glass" bind:value={leadDays} type="number" min="0" max="30" required class="mt-2 min-h-11 rounded-[10px] border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /><span class="mt-1 block text-xs text-ink-subtle">{$t('Choose between 0 and 30 days. Utility threshold alerts are created when a new reading exceeds the meter threshold.')}</span></Label>
				<fieldset><legend class="text-sm font-medium text-ink-secondary">{$t('Reminder sources')}</legend><div class="mt-3 grid gap-3 sm:grid-cols-2">{#each Object.keys(defaultSources) as source (source)}<Label variant="field" class="flex items-center gap-3 rounded-xl border border-line bg-page px-3 py-3 text-sm text-ink-secondary"><Checkbox bind:checked={sources[source]} class="border-line-strong bg-page data-checked:border-accent data-checked:bg-accent data-checked:text-on-accent" />{$t(source === 'vehicles' ? 'Vehicle insurance and service' : source === 'utilities' ? 'Utility threshold alerts' : source === 'pantry' ? 'Pantry best-before dates' : 'Task checklist reminders')}</Label>{/each}</div></fieldset>
			<Button type="submit" disabled={saving} class="min-h-11 rounded-xl bg-accent px-5 text-sm font-semibold text-on-accent hover:bg-accent/90 focus-visible:outline-accent-ink disabled:opacity-50">{$t(saving ? 'Saving…' : 'Save reminder settings')}</Button>
		</form>
	</Card>
</section>
