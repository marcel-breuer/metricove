<script lang="ts">
	import { onMount } from 'svelte';
	import { csrfToken } from '#lib/auth';

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

	let status = $state<AssistantStatus | null>(null);
	let question = $state('');
	let answer = $state('');
	let error = $state('');
	let loading = $state(true);
	let submitting = $state(false);
	let allowExternalDataSharing = $state(false);

	onMount(() => {
		void loadStatus();
	});

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
					allow_external_data_sharing: allowExternalDataSharing
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
	<title>AI Assistant · Metricove</title>
	<meta name="description" content="Ask read-only questions about data you are allowed to see." />
</svelte:head>

<section class="mx-auto max-w-4xl space-y-6">
	<header>
		<p class="text-sm font-medium text-cyan-200">AI module</p>
		<h1 class="mt-2 text-3xl font-semibold tracking-tight text-white sm:text-4xl">Read-only assistant</h1>
		<p class="mt-3 max-w-2xl text-sm leading-6 text-slate-400">Ask about information in modules you can view. The assistant only receives a small, permission-filtered summary when it calls one of its registered tools.</p>
	</header>

	{#if loading}
		<div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-6 text-sm text-slate-300" role="status">Loading assistant settings…</div>
	{:else if status}
		<section aria-labelledby="provider-title" class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5 sm:p-6">
			<div class="flex flex-wrap items-start justify-between gap-4">
				<div>
					<h2 id="provider-title" class="text-base font-semibold text-white">Provider</h2>
					<p class="mt-1 text-sm text-slate-300">{status.provider}</p>
				</div>
				<span class="rounded-full border px-3 py-1 text-xs font-semibold {status.available ? 'border-emerald-300/30 bg-emerald-300/10 text-emerald-200' : 'border-amber-300/30 bg-amber-300/10 text-amber-100'}">{status.available ? 'Configured' : 'Not configured'}</span>
			</div>
			{#if status.external}
				<p class="mt-4 rounded-xl border border-amber-300/20 bg-amber-300/5 p-4 text-sm leading-6 text-amber-100">This installation uses an external provider. Your question and any data requested through an assistant tool will be sent to that provider only after you confirm sharing for the request.</p>
			{:else}
				<p class="mt-4 text-sm leading-6 text-slate-400">Provider choice is controlled by this installation. No external provider is contacted unless its administrator configures one and marks it external.</p>
			{/if}
			{#if status.modules.length > 0}
				<p class="mt-4 text-xs leading-5 text-slate-500">Read-only data tools currently available: {status.modules.join(', ')}. Tool access is checked again for every request.</p>
			{:else}
				<p class="mt-4 text-xs leading-5 text-slate-500">No data modules are currently available to the assistant.</p>
			{/if}
		</section>

		<form onsubmit={(event) => { event.preventDefault(); void ask(); }} class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5 sm:p-7">
			<label for="assistant-question" class="block text-sm font-semibold text-white">What would you like to know?</label>
			<textarea id="assistant-question" bind:value={question} maxlength="4000" rows="4" placeholder="For example: How did my visible utility readings change over the last few entries?" class="mt-3 min-h-32 w-full resize-y rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm leading-6 text-slate-100 placeholder:text-slate-600 focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20" disabled={!status.available || submitting}></textarea>
			<p class="mt-2 text-right text-xs text-slate-500">{question.length}/4000</p>

			{#if status.requires_external_consent}
				<label class="mt-4 flex items-start gap-3 rounded-xl border border-slate-700 bg-slate-950/70 p-4 text-sm leading-6 text-slate-300">
					<input type="checkbox" bind:checked={allowExternalDataSharing} class="mt-1 size-4 shrink-0 accent-cyan-300" />
					<span>I agree to send this question and any data requested through the assistant’s read-only tools to the external provider for this request.</span>
				</label>
			{/if}

			<div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
				<p class="text-xs leading-5 text-slate-500">Requests and answers are not stored as chat history. The assistant cannot change records.</p>
				<button type="submit" disabled={!status.available || submitting || !question.trim() || (status.requires_external_consent && !allowExternalDataSharing)} class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-cyan-300 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:cursor-not-allowed disabled:opacity-50">{submitting ? 'Thinking…' : 'Ask assistant'}</button>
			</div>
		</form>
	{/if}

	{#if error}<p role="alert" class="rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-100">{error}</p>{/if}
	{#if answer}
		<section aria-labelledby="assistant-answer-title" class="rounded-2xl border border-cyan-300/20 bg-cyan-300/5 p-5 sm:p-7">
			<h2 id="assistant-answer-title" class="text-base font-semibold text-cyan-100">Answer</h2>
			<p class="mt-3 whitespace-pre-wrap text-sm leading-7 text-slate-200">{answer}</p>
		</section>
	{/if}
</section>
