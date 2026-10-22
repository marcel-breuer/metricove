<script lang="ts">
	import { onMount } from 'svelte';
	import { csrfToken, firstError } from '#lib/auth';
	import Button from '#lib/ui/Button.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import Panel from '#lib/ui/Panel.svelte';

	type Provider = 'disabled' | 'openai-compatible' | 'claude' | 'gemini' | 'openrouter';
	type Settings = { provider: Provider; base_url: string | null; model: string | null; api_key_configured: boolean; external_provider: boolean };

	let settings = $state<Settings | null>(null);
	let provider = $state<Provider>('disabled');
	let baseUrl = $state('');
	let model = $state('');
	let apiKey = $state('');
	let externalProvider = $state(false);
	let clearApiKey = $state(false);
	let loading = $state(true);
	let busy = $state(false);
	let message = $state('');
	let error = $state('');
	let usesManagedEndpoint = $derived(provider === 'claude' || provider === 'gemini' || provider === 'openrouter');
	let providerEnabled = $derived(provider !== 'disabled');
	let providerRequiresKey = $derived(usesManagedEndpoint);
	let requiresConsent = $derived(usesManagedEndpoint || (provider === 'openai-compatible' && externalProvider));

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
			const selectedProviderChanged = settings !== null && provider !== settings.provider;
			const payload: Record<string, unknown> = {
				provider,
				base_url: provider === 'openai-compatible' ? (baseUrl.trim() || null) : null,
				model: model.trim() || null,
				external_provider: requiresConsent,
				clear_api_key: clearApiKey || (selectedProviderChanged && !apiKey.trim())
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
							<option value="claude">Claude</option>
							<option value="gemini">Gemini</option>
							<option value="openrouter">OpenRouter</option>
						</select>
					</div>
					{#if provider === 'openai-compatible'}
					<div>
						<label for="ai-base-url" class="block text-sm font-medium text-slate-200">Provider base URL</label>
						<input id="ai-base-url" type="url" bind:value={baseUrl} maxlength="2048" placeholder="https://provider.example/v1" required class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-3 text-sm text-slate-100 placeholder:text-slate-600" />
					</div>
					{/if}
					<div>
						<label for="ai-model" class="block text-sm font-medium text-slate-200">Model</label>
						<input id="ai-model" bind:value={model} maxlength="120" placeholder={provider === 'openrouter' ? 'provider/model' : provider === 'gemini' ? 'gemini-2.5-flash' : provider === 'claude' ? 'claude-sonnet-4-5' : ''} disabled={!providerEnabled} required={providerEnabled} class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-3 text-sm text-slate-100 disabled:opacity-50" />
					</div>
					<div>
						<label for="ai-api-key" class="block text-sm font-medium text-slate-200">API key <span class="font-normal text-slate-500">({providerRequiresKey ? 'required' : 'optional for compatible endpoints'})</span></label>
						<input id="ai-api-key" type="password" autocomplete="new-password" bind:value={apiKey} maxlength="4096" placeholder={settings.api_key_configured && provider === settings.provider ? 'Key stored; leave blank to keep it' : 'Enter a key for the selected provider'} required={providerRequiresKey && (!settings.api_key_configured || provider !== settings.provider)} disabled={!providerEnabled} class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-3 text-sm text-slate-100 placeholder:text-slate-600 disabled:opacity-50" />
						{#if settings.api_key_configured}
							<label class="mt-3 inline-flex items-center gap-2 text-sm text-slate-300"><input type="checkbox" bind:checked={clearApiKey} disabled={busy || providerEnabled} class="size-4 accent-cyan-300" />Remove stored API key (disable the provider first)</label>
						{/if}
					</div>
					{#if usesManagedEndpoint}
						<p class="text-sm leading-6 text-slate-400">Metricove uses the official {provider === 'claude' ? 'Claude' : provider === 'gemini' ? 'Gemini' : 'OpenRouter'} endpoint. Each request sends your question and authorized tool data to this provider after you confirm.</p>
					{/if}
					{#if provider === 'openai-compatible'}
						<div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
							<label class="inline-flex items-start gap-3 text-sm text-slate-200"><input type="checkbox" bind:checked={externalProvider} class="mt-0.5 size-4 accent-cyan-300" /><span><span class="block font-medium">External provider</span><span class="mt-1 block leading-6 text-slate-400">External providers must use HTTPS. Every request requires the member to confirm sending the question and requested data.</span></span></label>
						</div>
					{/if}
					<div class="flex flex-wrap gap-3"><Button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save settings'}</Button><Button type="button" variant="secondary" disabled={busy} onclick={() => void loadSettings()}>Reload</Button></div>
				</form>
			{/if}
		</Panel>
	</div>
</section>
