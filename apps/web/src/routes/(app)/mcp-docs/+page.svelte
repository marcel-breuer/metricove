<script lang="ts">
	import { t } from '#lib/i18n';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import Panel from '#lib/ui/Panel.svelte';
</script>

<svelte:head>
	<title>{$t('MCP documentation')} {$t('· Metricove')}</title>
	<meta name="description" content={$t('Authenticated documentation for connecting MCP clients to Metricove.')} />
</svelte:head>

<div class="mx-auto max-w-5xl space-y-7">
	<PageHeading eyebrow={$t('Developer reference')} title="Metricove MCP" description={$t('Connect an MCP-compatible client to the tools your account is currently allowed to use.')} />

	<Panel padding="large" class="space-y-4">
		<h2 class="text-xl font-semibold">{$t('Connection')}</h2>
		<dl class="grid gap-4 sm:grid-cols-2">
			<div><dt class="text-sm text-ink-muted">{$t('Transport')}</dt><dd class="mt-1 font-mono text-accent-ink">{$t('Streamable HTTP · stateless')}</dd></div>
			<div><dt class="text-sm text-ink-muted">{$t('Endpoint')}</dt><dd class="mt-1 break-all font-mono text-accent-ink">/api/mcp</dd></div>
		</dl>
		<p class="text-sm leading-6 text-ink-secondary">{$t('Create a Metricove MCP token in Profile → MCP access tokens. Configure the client to send it as an HTTP bearer token. The token is shown only once. Metricove does not currently provide OAuth discovery or an OAuth authorization flow.')}</p>
		<pre class="overflow-x-auto rounded-xl border border-line bg-page p-4 text-xs leading-6 text-ink-secondary"><code>Authorization: Bearer &lt;your-metricove-token&gt;
POST /api/mcp
Content-Type: application/json
Accept: application/json, text/event-stream</code></pre>
	</Panel>

	<Panel padding="large" class="space-y-4">
		<h2 class="text-xl font-semibold">{$t('Discover current tools')}</h2>
		<p class="text-sm leading-6 text-ink-secondary">{$t('Call tools/list with the same token to get the current tools, descriptions and JSON input schemas. The server filters this live catalogue by token scopes and current module permissions, then checks authorization again when each tool is called.')}</p>
		<pre class="overflow-x-auto rounded-xl border border-line bg-page p-4 text-xs leading-6 text-ink-secondary"><code>{`{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}`}</code></pre>
		<p class="text-sm leading-6 text-ink-secondary">{$t('Use the exact tool name and arguments from that response. MCP clients should refresh tools/list when reconnecting or when access changes.')}</p>
		<pre class="overflow-x-auto rounded-xl border border-line bg-page p-4 text-xs leading-6 text-ink-secondary"><code>{`{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"summarize_finance_month","arguments":{"month":"2026-10"}}}`}</code></pre>
	</Panel>

	<Panel padding="large" class="space-y-3">
		<h2 class="text-xl font-semibold">{$t('Approved MCP writes')}</h2>
		<p class="text-sm leading-6 text-ink-secondary">{$t('Preview tools prepare an encrypted, expiring change. A member must approve it in Metricove before the MCP client can call the matching completion tool. Read the current input schemas and tool descriptions for the exact fields and requirements.')}</p>
		<p class="text-sm leading-6 text-ink-secondary">{$t('Access is checked at preview, approval and completion. Revoked tokens, suspended accounts, disabled modules, inaccessible private records or changed permissions stop the operation.')}</p>
	</Panel>

	<Panel padding="large" class="space-y-3">
		<h2 class="text-xl font-semibold">{$t('HTTP API reference')}</h2>
		<p class="text-sm leading-6 text-ink-secondary">{$t('The OpenAPI reference is generated from the current Laravel routes and validation rules. It documents the session-authenticated HTTP API. The MCP tools/list response is the live source of truth for MCP tool names, permissions and input schemas.')}</p>
		<p class="text-sm leading-6 text-ink-secondary">{$t('MCP clients can read the generated OpenAPI contract at metricove://api/http/openapi.json. It describes the session-authenticated HTTP API; the MCP bearer token authorizes only the registered tools returned by tools/list.')}</p>
		<div class="flex flex-wrap gap-3">
			<a href="/docs/api" class="inline-flex min-h-10 items-center rounded-lg border border-accent-ink/40 px-4 py-2 text-sm font-semibold text-accent-ink hover:bg-accent/10">{$t('Open API reference')}</a>
			<a href="/docs/api.json" class="inline-flex min-h-10 items-center rounded-lg border border-line-strong px-4 py-2 text-sm font-semibold text-ink-secondary hover:bg-page">{$t('OpenAPI JSON')}</a>
		</div>
	</Panel>
</div>
