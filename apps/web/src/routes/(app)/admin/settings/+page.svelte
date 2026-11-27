<script lang="ts">
	import { Alert } from '#lib/components/ui/alert/index.js';
	import { Checkbox } from '#lib/components/ui/checkbox/index.js';
	import { Input } from '#lib/components/ui/input/index.js';
	import { Label } from '#lib/components/ui/label/index.js';
	import * as NativeSelect from '#lib/components/ui/native-select/index.js';
	import { Textarea } from '#lib/components/ui/textarea/index.js';
	import { t } from '#lib/i18n';
	import { onMount } from 'svelte';
	import { csrfToken, firstError } from '#lib/auth';
	import Button from '#lib/ui/Button.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import Panel from '#lib/ui/Panel.svelte';

	type Provider = 'disabled' | 'openai-compatible' | 'claude' | 'gemini' | 'openrouter';
	type Settings = { provider: Provider; base_url: string | null; model: string | null; api_key_configured: boolean; external_provider: boolean };
	type NetworkAccessSettings = { enabled: boolean; allowed_ip_ranges: string[]; client_ip: string | null };

	let settings = $state<Settings | null>(null);
	let networkAccess = $state<NetworkAccessSettings | null>(null);
	let provider = $state<Provider>('disabled');
	let baseUrl = $state('');
	let model = $state('');
	let apiKey = $state('');
	let allowedIpRanges = $state('');
	let externalProvider = $state(false);
	let clearApiKey = $state(false);
	let loading = $state(true);
	let busy = $state(false);
	let networkBusy = $state(false);
	let message = $state('');
	let error = $state('');
	let networkMessage = $state('');
	let networkError = $state('');
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
			if (!response.ok || !result.ai || !result.network_access) throw new Error(result.message ?? 'General settings are unavailable.');
			settings = result.ai;
			networkAccess = result.network_access;
			allowedIpRanges = result.network_access.allowed_ip_ranges.join('\n');
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

	async function saveNetworkAccess(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		networkMessage = '';
		networkError = '';
		const entries = [...new Set(allowedIpRanges.split(/[\n,]+/).map((entry) => entry.trim()).filter(Boolean))];

		const token = csrfToken();
		if (!token) { networkError = 'Reload this page and try again.'; return; }
		networkBusy = true;
		try {
			const sendUpdate = (confirmLockout: boolean): Promise<Response> => fetch('/api/admin/settings/network-access', {
				method: 'PATCH',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ allowed_ip_ranges: entries, confirm_lockout: confirmLockout })
			});
			let response = await sendUpdate(false);
			let result = await response.json();
			if (response.status === 409 && result.reason === 'network_access_lockout') {
				if (!window.confirm($t('Your current IP address is not included in this list. Saving it may lock you out until you connect from an allowed network. Continue?'))) return;
				response = await sendUpdate(true);
				result = await response.json();
			}
			if (response.status === 401) { window.location.assign('/login'); return; }
			if (!response.ok || !result.network_access) throw new Error(firstError(result) || result.message || 'Network access settings could not be saved.');
			networkAccess = result.network_access;
			allowedIpRanges = result.network_access.allowed_ip_ranges.join('\n');
			networkMessage = result.message ?? 'Network access settings saved.';
		} catch (cause) {
			networkError = cause instanceof Error ? cause.message : 'Network access settings could not be saved.';
		} finally { networkBusy = false; }
	}
</script>

<svelte:head>
	<title>{$t("General settings")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Configure household-wide Metricove settings.")} />
</svelte:head>

<section class="mx-auto w-full max-w-screen-xl">
	<div class="mx-auto max-w-5xl">
		<PageHeading eyebrow="Household settings" title="General settings" description="Configure the assistant provider and network access for this installation. Provider credentials are encrypted at rest and never shown again after saving." />
		{#if message}<Notice class="mt-8" tone="success" message={$t(message)} />{/if}
		{#if error}<Notice class="mt-8" tone="error" message={$t(error)} />{/if}
		<Panel padding="large" class="mt-8 sm:p-8">
			{#if loading}
				<Alert variant="plain" role="status" class="text-sm text-ink-secondary">{$t("Loading settings…")}</Alert>
			{:else if settings}
				<form class="space-y-6" onsubmit={saveSettings} aria-busy={busy}>
					<div>
						<Label variant="field" for="ai-provider" class="block text-sm font-medium text-ink-secondary">{$t("AI provider")}</Label>
						<NativeSelect.Root id="ai-provider" variant="glass" bind:value={provider} class="mt-2 w-full">
							<NativeSelect.Option value="disabled">{$t("Disabled")}</NativeSelect.Option>
							<NativeSelect.Option value="openai-compatible">{$t("OpenAI-compatible")}</NativeSelect.Option>
							<NativeSelect.Option value="claude">{$t("Claude")}</NativeSelect.Option>
							<NativeSelect.Option value="gemini">{$t("Gemini")}</NativeSelect.Option>
							<NativeSelect.Option value="openrouter">{$t("OpenRouter")}</NativeSelect.Option>
						</NativeSelect.Root>
					</div>
					{#if provider === 'openai-compatible'}
					<div>
						<Label variant="field" for="ai-base-url" class="block text-sm font-medium text-ink-secondary">{$t("Provider base URL")}</Label>
						<Input id="ai-base-url" type="url" variant="glass" bind:value={baseUrl} maxlength={2048} placeholder={$t("https://provider.example/v1")} required class="mt-2" />
					</div>
					{/if}
					<div>
						<Label variant="field" for="ai-model" class="block text-sm font-medium text-ink-secondary">{$t("Model")}</Label>
						<Input id="ai-model" variant="glass" bind:value={model} maxlength={120} placeholder={provider === 'openrouter' ? 'provider/model' : provider === 'gemini' ? 'gemini-2.5-flash' : provider === 'claude' ? 'claude-sonnet-4-5' : ''} disabled={!providerEnabled} required={providerEnabled} class="mt-2" />
					</div>
					<div>
						<Label variant="field" for="ai-api-key" class="block text-sm font-medium text-ink-secondary">{$t("API key")} <span class="font-normal text-ink-subtle">({$t(providerRequiresKey ? 'required' : 'optional for compatible endpoints')})</span></Label>
						<Input id="ai-api-key" type="password" variant="glass" autocomplete="new-password" bind:value={apiKey} maxlength={4096} placeholder={settings.api_key_configured && provider === settings.provider ? $t('Key stored; leave blank to keep it') : $t('Enter a key for the selected provider')} required={providerRequiresKey && (!settings.api_key_configured || provider !== settings.provider)} disabled={!providerEnabled} class="mt-2" />
						{#if settings.api_key_configured}
							<Label variant="field" class="mt-3 inline-flex items-center gap-2 text-sm text-ink-secondary"><Checkbox bind:checked={clearApiKey} disabled={busy || providerEnabled} />{$t("Remove stored API key (disable the provider first)")}</Label>
						{/if}
					</div>
					{#if usesManagedEndpoint}
						<p class="text-sm leading-6 text-ink-muted">{$t("Metricove uses the official")} {provider === 'claude' ? 'Claude' : provider === 'gemini' ? 'Gemini' : 'OpenRouter'} {$t("endpoint. Each request sends your question and authorized tool data to this provider after you confirm.")}</p>
					{/if}
					{#if provider === 'openai-compatible'}
						<Panel padding="medium" class="bg-page">
							<Label variant="field" class="inline-flex items-start gap-3 text-sm text-ink-secondary"><Checkbox bind:checked={externalProvider} class="mt-0.5" /><span><span class="block font-medium">{$t("External provider")}</span><span class="mt-1 block leading-6 text-ink-muted">{$t("External providers must use HTTPS. Every request requires the member to confirm sending the question and requested data.")}</span></span></Label>
						</Panel>
					{/if}
					<div class="flex flex-wrap gap-3"><Button type="submit" disabled={busy}>{$t(busy ? 'Saving…' : 'Save settings')}</Button><Button type="button" variant="secondary" disabled={busy} onclick={() => void loadSettings()}>{$t("Reload")}</Button></div>
				</form>
			{/if}
		</Panel>
		<Panel padding="large" class="mt-8 sm:p-8">
			<div class="mb-6">
				<h2 class="text-xl font-semibold text-ink">{$t('Network access')}</h2>
				<p class="mt-2 max-w-3xl text-sm leading-6 text-ink-muted">{$t('Leave the list empty to allow access from any IP address. When entries are saved, only matching addresses and networks can use the platform API, including sign-in and MCP.')}</p>
			</div>
			{#if networkMessage}<Notice class="mb-6" tone="success" message={$t(networkMessage)} />{/if}
			{#if networkError}<Notice class="mb-6" tone="error" message={$t(networkError)} />{/if}
			{#if networkAccess}
				<form class="space-y-5" onsubmit={saveNetworkAccess} aria-busy={networkBusy}>
					<div>
						<p class="text-sm font-medium text-ink-secondary">{$t('Observed client IP')}</p>
						{#if networkAccess.client_ip}
							<code class="mt-2 inline-flex rounded-lg border border-line-strong bg-page px-3 py-2 font-mono text-sm text-accent-ink">{networkAccess.client_ip}</code>
						{:else}
							<p class="mt-2 text-sm text-warning">{$t('The client IP could not be determined. Check the trusted proxy configuration before enabling this restriction.')}</p>
						{/if}
					</div>
					<div>
						<Label variant="field" for="allowed-ip-ranges" class="block text-sm font-medium text-ink-secondary">{$t('Allowed IP addresses and networks')}</Label>
						<Textarea id="allowed-ip-ranges" variant="glass" bind:value={allowedIpRanges} rows={6} maxlength={8192} spellcheck="false" autocomplete="off" placeholder={'192.0.2.25\n192.0.2.0/24\n2001:db8::/32'} class="mt-2 font-mono" />
						<p class="mt-2 text-sm leading-6 text-ink-muted">{$t('Enter one IPv4 or IPv6 address or CIDR network per line. Commas are also accepted. Up to 100 entries are supported.')}</p>
					</div>
					<Alert variant="warning" role="none" class="border-warning/20 bg-warning/5 p-4 leading-6">{$t('Include the observed address above or a network containing it. Otherwise, connect from an allowed network before managing these settings again.')}</Alert>
					<div class="flex flex-wrap gap-3"><Button type="submit" disabled={networkBusy}>{$t(networkBusy ? 'Saving…' : 'Save network access')}</Button></div>
				</form>
			{/if}
		</Panel>
	</div>
</section>
