<script lang="ts">
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse, type AuthUser } from '#lib/auth';
	import { formatMeasurement } from '#lib/number-format';
	import Button from '#lib/ui/Button.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import Panel from '#lib/ui/Panel.svelte';
	import TextField from '#lib/ui/TextField.svelte';
	import ThemePicker from '#lib/ui/ThemePicker.svelte';

	let user: AuthUser | null = null;
	let name = '';
	let email = '';
	let loading = true;
	let busy = false;
	let message = '';
	let error = '';
	type McpToken = { id: number; name: string; scopes: string[]; expires_at: string | null; revoked_at: string | null; last_used_at: string | null; created_at: string | null };
	type McpAction = { id: string; action: string; state: string; meter: string; reading_date: string; value: string; unit: string; note: string | null; token_name: string; expires_at: string };
	let mcpEndpoint = '';
	let allowedScopes: string[] = [];
	let selectedScopes: string[] = [];
	let mcpTokens: McpToken[] = [];
	let mcpActions: McpAction[] = [];
	let tokenName = '';
	let tokenExpiry = 90;
	let newSecret = '';
	let mcpMessage = '';
	let mcpError = '';
	let mcpBusy = false;

	onMount(() => {
		void loadProfile();
		void loadMcpAccess();
	});

	const scopeLabels: Record<string, string> = {
		'tracking:view': 'View permitted tracking summaries',
		'finance:view': 'View permitted monthly finance totals',
		'utilities:view': 'View permitted utility readings',
		'utilities:create': 'Prepare utility readings for your approval',
		'vehicles:view': 'View permitted vehicle odometer summaries'
	};

	async function loadProfile(): Promise<void> {
		try {
			const response = await fetch('/api/profile', { headers: { Accept: 'application/json' } });
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (!response.ok) throw new Error('Profile is unavailable.');

			const result = (await response.json()) as ApiResponse;
			if (!result.user) {
				await goto('/login');
				return;
			}

			user = result.user;
			name = result.user.name;
			email = result.user.email;
		} catch {
			error = 'Profile is unavailable. Check your connection and try again.';
		} finally {
			loading = false;
		}
	}

	async function saveProfile(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		message = '';
		error = '';
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		busy = true;
		try {
			const response = await fetch('/api/profile', {
				method: 'PATCH',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-XSRF-TOKEN': token
				},
				body: JSON.stringify({ name, email })
			});
			const result = (await response.json()) as ApiResponse;

			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (!response.ok || !result.user) {
				error = firstError(result) || 'Profile could not be saved.';
				return;
			}

			user = result.user;
			name = result.user.name;
			email = result.user.email;
			message = 'Your profile has been updated.';
		} catch {
			error = 'Profile could not be saved. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}

	async function loadMcpAccess(): Promise<void> {
		try {
			const response = await fetch('/api/profile/mcp', { headers: { Accept: 'application/json' } });
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			const result = await response.json();
			if (!response.ok) throw new Error('MCP access settings are unavailable.');
			mcpEndpoint = result.endpoint;
			allowedScopes = result.allowed_scopes;
			mcpTokens = result.tokens;
			mcpActions = result.actions;
			selectedScopes = selectedScopes.filter((scope) => allowedScopes.includes(scope));
		} catch {
			mcpError = 'MCP access settings are unavailable. Reload and try again.';
		}
	}

	async function submitMcp(method: string, path: string, body?: Record<string, unknown>): Promise<Response> {
		const token = csrfToken();
		if (!token) throw new Error('Reload this page and try again.');
		return fetch(path, {
			method,
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'X-XSRF-TOKEN': token
			},
			...(body ? { body: JSON.stringify(body) } : {})
		});
	}

	async function createMcpToken(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		mcpMessage = '';
		mcpError = '';
		newSecret = '';
		mcpBusy = true;
		try {
			const response = await submitMcp('POST', '/api/profile/mcp/tokens', {
				name: tokenName,
				scopes: selectedScopes,
				expires_in_days: tokenExpiry
			});
			const result = await response.json();
			if (!response.ok) {
				mcpError = firstError(result) || 'The MCP token could not be created.';
				return;
			}
			newSecret = result.secret;
			tokenName = '';
			mcpMessage = result.message;
			await loadMcpAccess();
		} catch {
			mcpError = 'The MCP token could not be created. Check your connection and try again.';
		} finally {
			mcpBusy = false;
		}
	}

	async function revokeMcpToken(id: number): Promise<void> {
		mcpError = '';
		try {
			const response = await submitMcp('DELETE', `/api/profile/mcp/tokens/${id}`);
			const result = await response.json();
			if (!response.ok) throw new Error(firstError(result) || 'The token could not be revoked.');
			newSecret = '';
			mcpMessage = 'The MCP token has been revoked.';
			await loadMcpAccess();
		} catch (cause) {
			mcpError = cause instanceof Error ? cause.message : 'The token could not be revoked.';
		}
	}

	async function decideMcpAction(id: string, decision: 'approve' | 'reject'): Promise<void> {
		mcpError = '';
		try {
			const response = await submitMcp('POST', `/api/profile/mcp/actions/${id}/${decision}`);
			const result = await response.json();
			if (!response.ok) throw new Error(firstError(result) || 'The action could not be updated.');
			mcpMessage = decision === 'approve' ? 'The action is approved. The MCP client can now complete it.' : 'The action was rejected.';
			await loadMcpAccess();
		} catch (cause) {
			mcpError = cause instanceof Error ? cause.message : 'The action could not be updated.';
		}
	}

</script>

<svelte:head>
	<title>Your profile · Metricove</title>
	<meta name="description" content="Update your Metricove household profile." />
</svelte:head>

<section class="mx-auto w-full max-w-screen-xl">
	<div class="mx-auto flex min-h-[calc(100vh-5rem)] max-w-5xl flex-col">
		<section class="my-auto grid gap-10 py-12 lg:grid-cols-[0.9fr_1.1fr] lg:items-center lg:gap-16">
			<div class="max-w-xl">
				<PageHeading eyebrow="Account settings" title="Your Metricove profile." description="Update the name and email address used for this household account." />
				{#if user?.is_admin}
					<p class="mt-5 inline-flex rounded-full border border-cyan-300/20 bg-cyan-300/10 px-3 py-1 text-sm font-medium text-cyan-200">Household administrator</p>
				{/if}
				<ThemePicker class="mt-8 max-w-xs" />
			</div>

			<Panel padding="large" class="sm:p-8">
				{#if loading}
					<p role="status" class="text-sm text-slate-300">Loading your profile…</p>
				{:else if user}
					<h2 class="text-xl font-semibold">Profile details</h2>
					<form class="mt-6 space-y-5" onsubmit={saveProfile}>
						<TextField id="name" name="name" label="Your name" autocomplete="name" bind:value={name} required maxlength={255} />
						<TextField id="email" name="email" label="Email address" type="email" autocomplete="email" bind:value={email} required maxlength={255} />
						{#if message}<Notice tone="success" message={message} />{/if}
						{#if error}<Notice tone="error" message={error} />{/if}
						<Button type="submit" fullWidth disabled={busy}>
							{busy ? 'Saving…' : 'Save profile'}
						</Button>
					</form>
				{:else}
					<p role="alert" class="text-sm text-rose-200">{error || 'Profile is unavailable.'}</p>
					<a href="/login" class="mt-5 inline-flex text-sm font-semibold text-cyan-200">Sign in</a>
				{/if}
			</Panel>
		</section>

		<section class="mb-12 grid gap-8 lg:grid-cols-2">
			<div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-6 shadow-xl shadow-black/10 sm:p-8">
				<p class="text-sm font-medium tracking-[0.18em] text-slate-400 uppercase">External access</p>
				<h2 class="mt-2 text-2xl font-semibold">MCP access tokens</h2>
				<p class="mt-3 text-sm leading-6 text-slate-300">Use this endpoint with a Metricove bearer token. Tokens are shown once, expire within one year, and can be revoked here. MCP uses Metricove-managed tokens; OAuth authorization-server discovery is not provided.</p>
				{#if mcpEndpoint}
					<p class="mt-3 break-all rounded-lg bg-slate-950 px-3 py-2 font-mono text-xs text-cyan-200">{mcpEndpoint}</p>
				{/if}
				{#if allowedScopes.length > 0}
					<form class="mt-6 space-y-4" onsubmit={createMcpToken}>
						<div>
							<label for="mcp-token-name" class="mb-2 block text-sm font-medium text-slate-200">Token name</label>
							<input id="mcp-token-name" bind:value={tokenName} required maxlength="100" autocomplete="off" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" />
						</div>
						<fieldset class="space-y-2">
							<legend class="mb-2 text-sm font-medium text-slate-200">Available scopes</legend>
							{#each allowedScopes as scope}
								<label class="flex items-start gap-3 rounded-lg border border-slate-800 px-3 py-2.5 text-sm text-slate-300">
									<input type="checkbox" value={scope} bind:group={selectedScopes} class="mt-0.5 accent-cyan-300" />
									<span>{scopeLabels[scope] || scope}<span class="mt-0.5 block font-mono text-xs text-slate-500">{scope}</span></span>
								</label>
							{/each}
						</fieldset>
						<div>
							<label for="mcp-token-expiry" class="mb-2 block text-sm font-medium text-slate-200">Expires in days</label>
							<input id="mcp-token-expiry" type="number" min="1" max="365" bind:value={tokenExpiry} required class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" />
						</div>
						<button type="submit" disabled={mcpBusy || selectedScopes.length === 0} class="inline-flex w-full items-center justify-center rounded-lg bg-cyan-300 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:opacity-60">{mcpBusy ? 'Creating…' : 'Create MCP token'}</button>
					</form>
				{:else if !loading}
					<p class="mt-5 rounded-lg border border-slate-700 bg-slate-950/70 px-3 py-3 text-sm text-slate-400">No MCP scopes are currently available. Ask a household admin to enable a module and grant your account access.</p>
				{/if}
				{#if newSecret}
					<div class="mt-5 rounded-xl border border-amber-300/30 bg-amber-300/10 p-4">
						<p class="text-sm font-semibold text-amber-100">Copy this token now. It will not be shown again.</p>
						<code class="mt-3 block break-all rounded-lg bg-slate-950 p-3 text-xs text-slate-100">{newSecret}</code>
					</div>
				{/if}
				{#if mcpMessage}<p role="status" class="mt-4 rounded-lg border border-emerald-400/30 bg-emerald-400/10 px-3 py-2 text-sm text-emerald-200">{mcpMessage}</p>{/if}
				{#if mcpError}<p role="alert" class="mt-4 rounded-lg border border-rose-400/30 bg-rose-400/10 px-3 py-2 text-sm text-rose-200">{mcpError}</p>{/if}
				<div class="mt-8 space-y-3">
					<h3 class="text-base font-semibold">Your tokens</h3>
					{#if mcpTokens.length === 0}<p class="text-sm text-slate-400">No tokens created yet.</p>{/if}
					{#each mcpTokens as token}
						<div class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
							<div class="flex flex-wrap items-start justify-between gap-3">
								<div><p class="font-medium text-slate-100">{token.name}</p><p class="mt-1 text-xs text-slate-400">{token.scopes.join(', ')}</p><p class="mt-1 text-xs text-slate-500">Expires {token.expires_at ? new Date(token.expires_at).toLocaleDateString() : 'unknown'}{token.revoked_at ? ' · revoked' : ''}</p></div>
								{#if !token.revoked_at && token.expires_at && new Date(token.expires_at).getTime() > Date.now()}<button type="button" onclick={() => revokeMcpToken(token.id)} class="rounded-lg border border-rose-300/30 px-3 py-2 text-xs font-semibold text-rose-200 hover:bg-rose-300/10">Revoke</button>{/if}
							</div>
						</div>
					{/each}
				</div>
			</div>

			<div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-6 shadow-xl shadow-black/10 sm:p-8">
				<p class="text-sm font-medium tracking-[0.18em] text-slate-400 uppercase">Human approval</p>
				<h2 class="mt-2 text-2xl font-semibold">Pending MCP actions</h2>
				<p class="mt-3 text-sm leading-6 text-slate-300">A connected MCP client cannot approve its own writes. Review each utility reading here before the client can save it.</p>
				<div class="mt-6 space-y-4">
					{#if mcpActions.length === 0}<p class="rounded-lg border border-slate-800 bg-slate-950/70 px-3 py-3 text-sm text-slate-400">No pending actions.</p>{/if}
					{#each mcpActions as action}
						<article class="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
							<div class="flex items-start justify-between gap-3"><h3 class="font-semibold text-slate-100">{action.meter}</h3><span class="rounded-full border border-cyan-300/20 bg-cyan-300/10 px-2.5 py-1 text-xs text-cyan-200">{action.state}</span></div>
							<dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-slate-500">Date</dt><dd class="mt-1 text-slate-200">{action.reading_date}</dd></div><div><dt class="text-slate-500">Reading</dt><dd class="mt-1 text-slate-200">{formatMeasurement(action.value, action.unit)} {action.unit}</dd></div><div class="col-span-2"><dt class="text-slate-500">Requested by</dt><dd class="mt-1 text-slate-200">{action.token_name}</dd></div>{#if action.note}<div class="col-span-2"><dt class="text-slate-500">Note</dt><dd class="mt-1 whitespace-pre-wrap text-slate-200">{action.note}</dd></div>{/if}<div class="col-span-2"><dt class="text-slate-500">Expires</dt><dd class="mt-1 text-slate-200">{new Date(action.expires_at).toLocaleString()}</dd></div></dl>
							{#if action.state === 'pending'}<div class="mt-5 flex flex-wrap gap-3"><button type="button" onclick={() => decideMcpAction(action.id, 'approve')} class="rounded-lg bg-cyan-300 px-4 py-2.5 text-sm font-semibold text-slate-950 hover:bg-cyan-200">Approve</button><button type="button" onclick={() => decideMcpAction(action.id, 'reject')} class="rounded-lg border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-200 hover:bg-slate-800">Reject</button></div>{:else}<p class="mt-4 text-sm text-emerald-200">Approved. The connected MCP client can complete this action until it expires.</p><button type="button" onclick={() => decideMcpAction(action.id, 'reject')} class="mt-3 rounded-lg border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-200 hover:bg-slate-800">Cancel approval</button>{/if}
						</article>
					{/each}
				</div>
			</div>
		</section>

		<footer class="border-t border-slate-800 pt-5 text-sm text-slate-500">
			<a href="/" class="hover:text-slate-300">Return home</a>
		</footer>
	</div>
</section>
