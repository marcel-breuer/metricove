<script lang="ts">
	import { onMount } from 'svelte';
	import { csrfToken, firstError } from '#lib/auth';
	import Button from '#lib/ui/Button.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import Panel from '#lib/ui/Panel.svelte';

	type Settings = { provider: 'disabled' | 'openai-compatible'; base_url: string | null; model: string | null; api_key_configured: boolean; external_provider: boolean };

	let settings = $state<Settings | null>(null);
	let provider = $state<Settings['provider']>('disabled');
	let baseUrl = $state('');
	let model = $state('');
	let apiKey = $state('');
	let externalProvider = $state(false);
	let clearApiKey = $state(false);
	let loading = $state(true);
	let busy = $state(false);
	let message = $state('');
	let error = $state('');

	onMount(() => { void loadSettings(); });

	async function loadSettings(): Promise<void> {
		try {
			const response = await fetch('/api/admin/settings', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			if (response.status === 401) { window.location.assign('/login'); return; }
			if (response.status === 403) throw new Error('Only an active household administrator can change general settings.');
			const result = await response.json();
			if (!response.ok || !result.ai) throw new Error(result.message ?? 'General settings are unavailable.');
			settings = result.ai;
			provider = result.ai.provider;
			baseUrl = result.ai.base_url ?? '';
			model = result.ai.model ?? '';
			externalProvider = result.ai.external_provider;
			error = '';
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'General settings are unavailable.';
		} finally { loading = false; }
	}

	async function saveSettings(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		message = '';
		error = '';
		const token = csrfToken();
		if (!token) { error = 'Reload this page and try again.'; return; }
		busy = true;
		try {
			const payload: Record<string, unknown> = {
				provider,
				base_url: baseUrl.trim() || null,
				model: model.trim() || null,
				external_provider: externalProvider,
				clear_api_key: clearApiKey
			};
			if (apiKey.trim() && !clearApiKey) payload.api_key = apiKey;
			const response = await fetch('/api/admin/settings', {
				method: 'PATCH',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify(payload)
			});
			const result = await response.json();
			if (response.status === 401) { window.location.assign('/login'); return; }
			if (!response.ok || !result.ai) throw new Error(firstError(result) || result.message || 'General settings could not be saved.');
			settings = result.ai;
			provider = result.ai.provider;
			baseUrl = result.ai.base_url ?? '';
			model = result.ai.model ?? '';
			externalProvider = result.ai.external_provider;
			apiKey = '';
			clearApiKey = false;
			message = result.message ?? 'General settings saved.';
		} catch (cause) { error = cause instanceof Error ? cause.message : 'General settings could not be saved.'; }
		finally { busy = false; }
	}
</script>

<svelte:head>
	<title>General settings · Metricove</title>
	<meta name="description" content="Configure household-wide Metricove settings." />
</svelte:head>

<section class="mx-auto w-full max-w-screen-xl">
	<div class="mx-auto max-w-5xl">
		<PageHeading eyebrow="Household settings" title="General settings." description="Configure the assistant provider for this installation. Provider credentials are encrypted at rest and never shown again after saving." />
		{#if message}<Notice class="mt-8" tone="success" message={message} />{/if}
		{#if error}<Notice class="mt-8" tone="error" message={error} />{/if}
		<Panel padding="large" class="mt-8 sm:p-8">
			{#if loading}
				<p role="status" class="text-sm text-slate-300">Loading settings…</p>
			{:else if settings}
				<form class="space-y-6" onsubmit={saveSettings} aria-busy={busy}>
					<div>
						<label for="ai-provider" class="block text-sm font-medium text-slate-200">AI provider</label>
						<select id="ai-provider" bind:value={provider} class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-3 text-sm text-slate-100 focus:border-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300/20">
							<option value="disabled">Disabled</option>
							<option value="openai-compatible">OpenAI-compatible</option>
						</select>
					</div>
					<div>
						<label for="ai-base-url" class="block text-sm font-medium text-slate-200">Provider base URL</label>
						<input id="ai-base-url" type="url" bind:value={baseUrl} maxlength="2048" placeholder="https://provider.example/v1" disabled={provider === 'disabled'} required={provider === 'openai-compatible'} class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-3 text-sm text-slate-100 placeholder:text-slate-600 disabled:opacity-50" />
					</div>
					<div>
						<label for="ai-model" class="block text-sm font-medium text-slate-200">Model</label>
						<input id="ai-model" bind:value={model} maxlength="120" disabled={provider === 'disabled'} required={provider === 'openai-compatible'} class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-3 text-sm text-slate-100 disabled:opacity-50" />
					</div>
					<div>
						<label for="ai-api-key" class="block text-sm font-medium text-slate-200">API key <span class="font-normal text-slate-500">(optional)</span></label>
						<input id="ai-api-key" type="password" autocomplete="new-password" bind:value={apiKey} maxlength="4096" placeholder={settings.api_key_configured ? 'Key stored; leave blank to keep it' : 'No key stored'} disabled={provider === 'disabled'} class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-3 text-sm text-slate-100 placeholder:text-slate-600 disabled:opacity-50" />
						{#if settings.api_key_configured}
							<label class="mt-3 inline-flex items-center gap-2 text-sm text-slate-300"><input type="checkbox" bind:checked={clearApiKey} disabled={busy} class="size-4 accent-cyan-300" />Remove stored API key</label>
						{/if}
					</div>
					<div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
						<label class="inline-flex items-start gap-3 text-sm text-slate-200"><input type="checkbox" bind:checked={externalProvider} disabled={provider === 'disabled'} class="mt-0.5 size-4 accent-cyan-300" /><span><span class="block font-medium">External provider</span><span class="mt-1 block leading-6 text-slate-400">External providers must use HTTPS. Every request requires the member to confirm sending the question and requested data.</span></span></label>
					</div>
					<div class="flex flex-wrap gap-3"><Button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save settings'}</Button><Button type="button" variant="secondary" disabled={busy} onclick={() => void loadSettings()}>Reload</Button></div>
				</form>
			{/if}
		</Panel>
	</div>
</section>
