<script lang="ts">
	import { Alert } from '#lib/components/ui/alert/index.js';
	import { Checkbox } from '#lib/components/ui/checkbox/index.js';
	import { Card } from '#lib/components/ui/card/index.js';
	import { Input } from '#lib/components/ui/input/index.js';
	import { Label } from '#lib/components/ui/label/index.js';
	import * as NativeSelect from '#lib/components/ui/native-select/index.js';
	import Button from '#lib/ui/Button.svelte';
	import Badge from '#lib/ui/Badge.svelte';
	import Markdown from '#lib/ui/Markdown.svelte';
	import MarkdownEditor from '#lib/ui/MarkdownEditor.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import { t } from '#lib/i18n';
	import { page } from '$app/state';
	import { onMount, tick } from 'svelte';
	import { csrfToken } from '#lib/auth';
	import { formatDate } from '#lib/i18n';
	import Modal from '#lib/ui/Modal.svelte';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';

	type AssistantStatus = {
		available: boolean;
		provider: string;
		external: boolean;
		requires_external_consent: boolean;
		modules: string[];
	};

	type AssistantResponse = {
		answer?: string;
		message?: string;
		errors?: Record<string, string[]>;
	};
	type ContextEntry = { id: number; scope: 'user' | 'project'; project_key: string | null; kind: string; content: string; source: string | null; enabled: boolean; expires_at: string | null; version: number; updated_at: string };

	let status = $state<AssistantStatus | null>(null);
	let question = $state('');
	let answer = $state('');
	let error = $state('');
	let loading = $state(true);
	let submitting = $state(false);
	let allowExternalDataSharing = $state(false);
	let contextEnabled = $state(true);
	let contextEntries = $state<ContextEntry[]>([]);
	let contextScope = $state<'user' | 'project'>('user');
	let contextProject = $state('');
	let questionProject = $state('');
	let contextKind = $state('preference');
	let contextSource = $state('');
	let contextContent = $state('');
	let contextMessage = $state('');
	let contextError = $state('');
	let contextBusy = $state(false);
	let contextCreateOpen = $state(false);
	let contextEditOpen = $state(false);
	let contextDeleteOpen = $state(false);
	let contextToEdit = $state<ContextEntry | null>(null);
	let contextToDelete = $state<ContextEntry | null>(null);
	let editedContextContent = $state('');
	let lastSearchRequestId = '';

	$effect(() => {
		const requestId = page.state.assistantSearchRequestId;
		const requestedQuestion = page.state.assistantQuestion;
		if (!requestId || requestId === lastSearchRequestId || !requestedQuestion) return;

		lastSearchRequestId = requestId;
		question = requestedQuestion.slice(0, 4000);
		void tick().then(() => document.getElementById('assistant-question')?.focus());
	});

	onMount(() => {
		void loadStatus();
		void loadContext();
	});

	async function loadContext(): Promise<void> {
		try {
			const response = await fetch('/api/ai/context', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			if (!response.ok) return;
			const payload = await response.json();
			contextEntries = payload.items ?? [];
			contextEnabled = payload.enabled ?? true;
		} catch {
			contextError = 'Saved context is unavailable.';
		}
	}

	async function saveContext(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		const token = csrfToken();
		if (!token) { contextError = 'Reload this page and try again.'; return; }
		contextBusy = true;
		contextError = '';
		contextMessage = '';
		try {
			const response = await fetch('/api/ai/context', {
				method: 'POST', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ scope: contextScope, project_key: contextScope === 'project' ? contextProject.trim() : null, kind: contextKind, content: contextContent.trim(), source: contextSource.trim() || null })
			});
			const payload = await response.json();
			if (!response.ok) throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? 'Context could not be saved.');
			contextContent = '';
			contextSource = '';
			contextProject = '';
			contextScope = 'user';
			contextCreateOpen = false;
			contextMessage = 'Saved context. It will be used only for relevant questions.';
			await loadContext();
		} catch (cause) { contextError = cause instanceof Error ? cause.message : 'Context could not be saved.'; }
		finally { contextBusy = false; }
	}

	function beginContextEdit(entry: ContextEntry): void {
		contextToEdit = entry;
		editedContextContent = entry.content;
		contextEditOpen = true;
	}

	async function updateContext(): Promise<void> {
		const entry = contextToEdit;
		if (!entry) return;
		const token = csrfToken();
		if (!token) { contextError = 'Reload this page and try again.'; return; }
		contextBusy = true;
		try {
			const response = await fetch(`/api/ai/context/${entry.id}`, {
				method: 'PUT', credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ scope: entry.scope, project_key: entry.project_key, kind: entry.kind, content: editedContextContent.trim(), enabled: entry.enabled, expected_version: entry.version })
			});
			const payload = await response.json();
			if (!response.ok) throw new Error(String(Object.values(payload.errors ?? {}).flat()[0] ?? 'Context could not be updated.'));
			contextEditOpen = false;
			contextToEdit = null;
			contextMessage = 'Saved context changes.';
			await loadContext();
		} catch (cause) { contextError = cause instanceof Error ? cause.message : 'Context could not be updated.'; }
		finally { contextBusy = false; }
	}

	async function deleteContext(): Promise<void> {
		const entry = contextToDelete;
		if (!entry) return;
		const token = csrfToken();
		if (!token) { contextError = 'Reload this page and try again.'; return; }
		try {
			const response = await fetch(`/api/ai/context/${entry.id}`, { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token } });
			if (!response.ok) throw new Error('Context could not be deleted.');
			contextDeleteOpen = false;
			contextToDelete = null;
			contextMessage = 'Deleted saved context.';
			await loadContext();
		} catch (cause) { contextError = cause instanceof Error ? cause.message : 'Context could not be deleted.'; }
	}

	async function setContextEnabled(enabled: boolean): Promise<void> {
		const token = csrfToken();
		if (!token) { contextError = 'Reload this page and try again.'; return; }
		try {
			const response = await fetch('/api/ai/context/preferences', { method: 'PATCH', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token }, body: JSON.stringify({ enabled }) });
			if (!response.ok) throw new Error('Privacy setting could not be updated.');
			contextEnabled = enabled;
			contextMessage = enabled ? 'Saved context is enabled.' : 'Saved context is disabled and will not be sent to the assistant.';
		} catch (cause) { contextError = cause instanceof Error ? cause.message : 'Privacy setting could not be updated.'; }
	}

	async function loadStatus(): Promise<void> {
		loading = true;
		error = '';

		try {
			const response = await fetch('/api/ai/assistant', {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' }
			});
			const payload = (await response.json()) as AssistantResponse & AssistantStatus;
			if (!response.ok) throw new Error(payload.message ?? 'Assistant status is unavailable.');
			status = payload;
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Assistant status is unavailable.';
		} finally {
			loading = false;
		}
	}

	async function ask(): Promise<void> {
		const message = question.trim();
		if (!status?.available || !message || submitting) return;

		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		submitting = true;
		error = '';
		answer = '';

		try {
			const response = await fetch('/api/ai/assistant', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-XSRF-TOKEN': token
				},
				body: JSON.stringify({
					message,
					allow_external_data_sharing: allowExternalDataSharing,
					...(questionProject.trim() ? { project_key: questionProject.trim() } : {})
				})
			});
			const payload = (await response.json()) as AssistantResponse;
			if (!response.ok) {
				error = Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? 'The assistant could not answer.';
				return;
			}
			answer = payload.answer ?? '';
		} catch {
			error = 'The assistant is unavailable. Check your connection and try again.';
		} finally {
			submitting = false;
		}
	}
</script>

<svelte:head>
	<title>{$t("AI Assistant")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Ask read-only questions about data you are allowed to see.")} />
</svelte:head>

<section class="mx-auto max-w-4xl space-y-6">
	<PageHeading eyebrow="Metricove Assistant" title="Ask your household assistant" description="Ask about information in modules you can view. The assistant only receives a small, permission-filtered summary when it calls one of its registered tools." />

	{#if loading}
		<Alert variant="glass" class="rounded-2xl p-6 text-sm text-ink-secondary" role="status">{$t("Loading assistant settings…")}</Alert>
	{:else if status}
		<Card as="section" variant="glass" aria-labelledby="provider-title" class="p-5 sm:p-6">
			<div class="flex flex-wrap items-start justify-between gap-4">
				<div>
					<h2 id="provider-title" class="text-base font-semibold text-ink">{$t("Provider")}</h2>
					<p class="mt-1 text-sm text-ink-secondary">{status.provider}</p>
				</div>
				<Badge class="px-3 py-1 font-semibold {status.available ? 'border-positive/30 bg-positive/10 text-positive' : 'border-warning/30 bg-warning/10 text-warning'} leading-4 whitespace-normal">{$t(status.available ? 'Configured' : 'Not configured')}</Badge>
			</div>
			{#if status.external}
				<Alert variant="warning" role="none" class="mt-4 border-warning/20 bg-warning/5 p-4 leading-6">{$t("This installation uses an external provider. Your question and any data requested through an assistant tool will be sent to that provider only after you confirm sharing for the request.")}</Alert>
			{:else}
				<p class="mt-4 text-sm leading-6 text-ink-muted">{$t("Provider choice is controlled by this installation. No external provider is contacted unless its administrator configures one and marks it external.")}</p>
			{/if}
			{#if status.modules.length > 0}
				<p class="mt-4 text-xs leading-5 text-ink-subtle">{$t("Read-only data tools currently available:")} {status.modules.join(', ')}. Tool access is checked again for every request.</p>
			{:else}
				<p class="mt-4 text-xs leading-5 text-ink-subtle">{$t("No data modules are currently available to the assistant.")}</p>
			{/if}
		</Card>

		<Card as="form" variant="glass" onsubmit={(event) => { event.preventDefault(); void ask(); }} class="rounded-[20px] p-5 sm:p-7">
			<Label variant="field" for="assistant-question" class="block text-sm font-semibold text-ink">{$t("What would you like to know?")}</Label>
			<MarkdownEditor id="assistant-question" bind:value={question} maxlength={4000} rows={4} placeholder={$t("For example: How did my visible utility readings change over the last few entries?")} class="mt-3 min-h-32 w-full resize-y rounded-[10px] border border-line-strong bg-page px-4 py-3 text-sm leading-6 text-ink placeholder:text-ink-subtle focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" disabled={!status.available || submitting} />
			<div class="mt-4 max-w-lg">
				<Label variant="field" for="assistant-project" class="block text-sm font-medium text-ink-secondary">{$t("Project context key")} <span class="font-normal text-ink-subtle">{$t("(optional)")}</span></Label>
			<Input variant="glass" id="assistant-project" bind:value={questionProject} maxlength={120} placeholder={$t("e.g. budget-app")} class="mt-2 rounded-[10px] border-line-strong bg-page px-4 py-3 text-sm focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" disabled={!status.available || submitting} />
			</div>
			<p class="mt-2 text-right text-xs text-ink-subtle">{question.length}/4000</p>

			{#if status.requires_external_consent}
				<label class="mt-4 flex items-start gap-3 rounded-xl border border-line-strong bg-page p-4 text-sm leading-6 text-ink-secondary">
					<Checkbox bind:checked={allowExternalDataSharing} class="mt-1" />
					<span>{$t("I agree to send this question and any data requested through the assistant’s read-only tools to the external provider for this request.")}</span>
				</label>
			{/if}

			<div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
				<p class="text-xs leading-5 text-ink-subtle">{$t("Requests and answers are not stored as chat history. The assistant cannot change records.")}</p>
				<Button type="submit" disabled={!status.available || submitting || !question.trim() || (status.requires_external_consent && !allowExternalDataSharing)} class="min-h-11 rounded-xl px-5 py-3">{$t(submitting ? 'Thinking…' : 'Ask assistant')}</Button>
			</div>
		</Card>

		<Card as="section" variant="glass" aria-labelledby="context-title" class="p-5 sm:p-7">
			<div class="flex flex-wrap items-start justify-between gap-4">
				<div><h2 id="context-title" class="text-xl font-semibold text-ink">{$t("Saved context")}</h2><p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted">{$t("Private notes and preferences can personalize future answers. Context is isolated to your account and is never treated as an instruction.")}</p></div>
				<Label variant="field" class="inline-flex items-center gap-3 text-sm text-ink-secondary"><Checkbox checked={contextEnabled} onCheckedChange={(checked) => void setContextEnabled(checked === true)} />{$t("Use saved context")}</Label>
			</div>
			<div class="mt-6"><Button type="button" onclick={() => (contextCreateOpen = true)} class="rounded-xl px-4 py-3">{$t("Add saved context")}</Button></div>
			<Modal bind:open={contextCreateOpen} title={$t("Save context")}>
			<form onsubmit={(event) => void saveContext(event)} class="grid gap-4 sm:grid-cols-2">
				{#if contextError}<Alert variant="plain" role="alert" class="sm:col-span-2 text-sm text-danger">{$t(contextError)}</Alert>{/if}
				<div><Label variant="field" for="context-scope" class="block text-sm font-medium text-ink-secondary">{$t("Applies to")}</Label><NativeSelect.Root variant="glass" id="context-scope" bind:value={contextScope} class="mt-2 w-full rounded-[10px] border-line-strong bg-page px-3 py-3 text-sm text-ink max-sm:text-base"><NativeSelect.Option value="user">{$t("All my questions")}</NativeSelect.Option><NativeSelect.Option value="project">{$t("One project key")}</NativeSelect.Option></NativeSelect.Root></div>
				{#if contextScope === 'project'}<div><Label variant="field" for="context-project" class="block text-sm font-medium text-ink-secondary">{$t("Project key")}</Label><Input variant="glass" id="context-project" bind:value={contextProject} required maxlength={120} class="mt-2 rounded-[10px] border-line-strong bg-page px-3 py-3 text-sm focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /></div>{/if}
				<div><Label variant="field" for="context-kind" class="block text-sm font-medium text-ink-secondary">{$t("Type")}</Label><Input variant="glass" id="context-kind" bind:value={contextKind} required maxlength={40} placeholder={$t("preference, goal, fact")} class="mt-2 rounded-[10px] border-line-strong bg-page px-3 py-3 text-sm focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /></div>
				<div><Label variant="field" for="context-source" class="block text-sm font-medium text-ink-secondary">{$t("Source")} <span class="font-normal text-ink-subtle">{$t("(optional)")}</span></Label><Input variant="glass" id="context-source" bind:value={contextSource} maxlength={120} placeholder={$t("Added by me")} class="mt-2 rounded-[10px] border-line-strong bg-page px-3 py-3 text-sm focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" /></div>
				<div class="sm:col-span-2"><label for="context-content" class="block text-sm font-medium text-ink-secondary">{$t("Context")}</label><MarkdownEditor id="context-content" bind:value={contextContent} required maxlength={4000} rows={3} class="mt-2 w-full rounded-[10px] border border-line-strong bg-page px-3 py-3 text-sm leading-6 text-ink max-sm:text-base" /></div>
				<div class="sm:col-span-2"><Button type="submit" disabled={contextBusy || !contextContent.trim() || (contextScope === 'project' && !contextProject.trim())} class="rounded-xl px-4 py-3">{$t(contextBusy ? 'Saving…' : 'Save context')}</Button></div>
			</form>
			</Modal>
			{#if contextEntries.length}
				<Card as="ul" variant="inset" class="mt-6 divide-y divide-line">
					{#each contextEntries as entry (entry.id)}
						<li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
							<div class="min-w-0 flex-1"><p class="text-xs font-medium text-accent-ink">{$t(entry.kind)} · {entry.scope === 'project' ? entry.project_key : $t('all questions')}</p><Markdown class="mt-2 text-sm leading-6 text-ink-secondary" value={entry.content} /><p class="mt-1 text-xs text-ink-subtle">{$t("Expires")} {entry.expires_at ? formatDate(entry.expires_at) : $t('never')} {$t("· updated")} {formatDate(entry.updated_at)}</p></div>
							<div class="flex shrink-0 gap-2"><Button type="button" variant="ghost" onclick={() => beginContextEdit(entry)} class="min-h-10 rounded-lg border border-line-strong px-3 py-2 text-sm font-medium text-ink-secondary hover:border-accent-ink">{$t("Edit")}</Button><Button type="button" variant="ghost" onclick={() => { contextToDelete = entry; contextDeleteOpen = true; }} class="min-h-10 rounded-lg border border-danger/20 px-3 py-2 text-sm font-medium text-danger hover:bg-danger/10">{$t("Delete")}</Button></div>
						</li>
					{/each}
				</Card>
			{:else}<p class="mt-5 text-sm text-ink-subtle">{$t("No saved context yet.")}</p>{/if}
			{#if contextMessage}<Alert variant="plain" role="status" class="mt-4 text-sm text-positive">{contextMessage}</Alert>{/if}
			{#if contextError}<Alert variant="plain" role="alert" class="mt-4 text-sm text-danger">{$t(contextError)}</Alert>{/if}
			{#if contextToEdit}<Modal bind:open={contextEditOpen} title={$t('Edit saved context')}><form onsubmit={(event) => { event.preventDefault(); void updateContext(); }} class="space-y-4">{#if contextError}<Alert variant="plain" role="alert" class="text-sm text-danger">{$t(contextError)}</Alert>{/if}<div><Label variant="field" for="markdown-field-1" class="block text-sm font-medium text-ink-secondary">{$t('Context')}</Label><MarkdownEditor id="markdown-field-1" bind:value={editedContextContent} required maxlength={4000} rows={5} class="mt-2 w-full rounded-[10px] border border-line-strong bg-page px-3 py-3 text-sm leading-6 text-ink max-sm:text-base" /></div><Button type="submit" disabled={contextBusy || !editedContextContent.trim()} class="rounded-xl px-4 py-3">{$t(contextBusy ? 'Saving…' : 'Save changes')}</Button></form></Modal>{/if}
			{#if contextToDelete}<ConfirmModal bind:open={contextDeleteOpen} title={$t('Delete saved context?')} message={$t('Delete this saved context entry?')} confirmLabel={$t('Delete')} onConfirm={() => void deleteContext()} />{/if}
		</Card>
	{/if}

	{#if error}<Notice tone="error" message={error} />{/if}
	{#if answer}
		<Alert variant="plain" role="status" aria-labelledby="assistant-answer-title" class="rounded-2xl border border-accent-ink/20 bg-accent/5 p-5 sm:p-7">
			<h2 id="assistant-answer-title" class="text-base font-semibold text-accent-ink">{$t("Answer")}</h2>
			<Markdown class="mt-3 text-sm leading-7 text-ink-secondary" value={answer} />
		</Alert>
	{/if}
</section>
