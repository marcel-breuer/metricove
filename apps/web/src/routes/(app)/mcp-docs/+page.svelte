<script lang="ts">
	import { t } from '#lib/i18n';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import Panel from '#lib/ui/Panel.svelte';

	const readTools = [
		{ name: 'summarize_finance_month', scope: 'finance:view', args: '{ "month": "YYYY-MM" }', note: 'Returns visible income, expenses and net totals, grouped by currency.' },
		{ name: 'summarize_visible_utility_readings', scope: 'utilities:view', args: '{}', note: 'Returns up to 8 visible meters with 5 recent readings each. Notes, photos and alert thresholds are excluded.' },
		{ name: 'summarize_visible_tracker_entries', scope: 'tracking:view', args: '{}', note: 'Returns up to 8 visible trackers with 5 recent entries each. Text values and free-text notes are excluded.' },
		{ name: 'summarize_visible_vehicle_odometer_readings', scope: 'vehicles:view', args: '{}', note: 'Returns up to 8 visible vehicles with 5 recent odometer readings each. Costs, insurance and maintenance details are excluded.' }
	];
</script>

<svelte:head>
	<title>{$t("MCP documentation")} · Metricove</title>
	<meta name="description" content="Authenticated documentation for connecting MCP clients to Metricove." />
</svelte:head>

<div class="mx-auto max-w-5xl space-y-7">
	<PageHeading eyebrow={$t('Developer reference')} title="Metricove MCP" description={$t('Connect an MCP-compatible client to the tools your account is currently allowed to use.')} />

	<Panel padding="large" class="space-y-4">
		<h2 class="text-xl font-semibold">{$t('Connection')}</h2>
		<dl class="grid gap-4 sm:grid-cols-2">
			<div><dt class="text-sm text-slate-400">{$t('Transport')}</dt><dd class="mt-1 font-mono text-cyan-200">Streamable HTTP · stateless</dd></div>
			<div><dt class="text-sm text-slate-400">{$t('Endpoint')}</dt><dd class="mt-1 break-all font-mono text-cyan-200">/api/mcp</dd></div>
		</dl>
		<p class="text-sm leading-6 text-slate-300">{$t('Create a Metricove MCP token in Profile → MCP access tokens. Configure the client to send it as an HTTP bearer token. The token is shown only once. Metricove does not currently provide OAuth discovery or an OAuth authorization flow.')}</p>
		<pre class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-950 p-4 text-xs leading-6 text-slate-200"><code>Authorization: Bearer &lt;your-metricove-token&gt;
POST /api/mcp
Content-Type: application/json
Accept: application/json, text/event-stream</code></pre>
		<p class="text-sm font-medium text-slate-300">Discover the tools exposed to this token:</p>
		<pre class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-950 p-4 text-xs leading-6 text-slate-200"><code>{`{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}`}</code></pre>
		<p class="text-sm font-medium text-slate-300">Example call:</p>
		<pre class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-950 p-4 text-xs leading-6 text-slate-200"><code>{`{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"summarize_finance_month","arguments":{"month":"2026-10"}}}`}</code></pre>
	</Panel>

	<Panel padding="large" class="space-y-4">
		<h2 class="text-xl font-semibold">{$t('Available read tools')}</h2>
		<p class="text-sm leading-6 text-slate-300">{$t('The server exposes tools based on the token scopes and your current module permissions. It checks permissions again on every call. A token alone never grants access.')}</p>
		<div class="space-y-3">
			{#each readTools as tool}
				<article class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
					<div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-mono text-sm font-semibold text-cyan-200">{tool.name}</h3><span class="rounded-full border border-slate-700 px-2.5 py-1 text-xs text-slate-300">{tool.scope}</span></div>
					<p class="mt-2 text-sm leading-6 text-slate-300">{$t(tool.note)}</p>
					<p class="mt-2 text-xs text-slate-500">{$t('Arguments:')} <code>{tool.args}</code></p>
				</article>
			{/each}
		</div>
	</Panel>

	<Panel padding="large" class="space-y-4">
		<h2 class="text-xl font-semibold">{$t('Approved utility reading writes')}</h2>
		<p class="text-sm leading-6 text-slate-300">{$t('A connected MCP client cannot approve its own writes. The account owner must approve it in the profile before it can be completed.')} <code>preview_utility_reading</code> prepares the reading but does not change data. <code>complete_approved_utility_reading</code> saves the approved preview.</p>
		<ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-slate-300">
			<li><code>preview_utility_reading</code> requires <code>utilities:create</code>; fields are <code>meter_id</code> (integer), <code>reading_date</code> (YYYY-MM-DD), <code>value</code> (decimal string, up to 14 whole and 6 fractional digits), and optional <code>note</code> (up to 2,000 characters).</li>
			<li>{$t('Preview expires after 10 minutes. Review its meter, date, value and note under Profile → Pending MCP actions.')}</li>
			<li><code>complete_approved_utility_reading</code> requires <code>preview_id</code> and a UUID <code>idempotency_key</code>. Reuse the same key when retrying the same completion.</li>
			<li>{$t('Access is rechecked at preview, approval and completion. Revoked tokens, suspended accounts, disabled modules or changed permissions stop the operation.')}</li>
		</ul>
	</Panel>

	<Panel padding="large" class="space-y-3">
		<h2 class="text-xl font-semibold">{$t('OpenAPI reference')}</h2>
		<p class="text-sm leading-6 text-slate-300">{$t('OpenAPI generates the HTTP API reference from Laravel routes and validation. It requires an active Metricove session. MCP tool schemas are negotiated through MCP and are listed above because OpenAPI does not describe that JSON-RPC tool catalogue.')}</p>
		<a href="/docs/api" class="inline-flex min-h-10 items-center rounded-lg border border-cyan-300/40 px-4 py-2 text-sm font-semibold text-cyan-200 hover:bg-cyan-300/10">{$t('Open API reference')}</a>
	</Panel>
</div>
