<script lang="ts">
	import Badge from '#lib/ui/Badge.svelte';
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse, type AuthUser } from '#lib/auth';
	import { formatMeasurement } from '#lib/number-format';
	import Button from '#lib/ui/Button.svelte';
	import IconChoiceGroup from '#lib/ui/IconChoiceGroup.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import Panel from '#lib/ui/Panel.svelte';
	import TextField from '#lib/ui/TextField.svelte';
	import ThemePicker from '#lib/ui/ThemePicker.svelte';
	import Modal from '#lib/ui/Modal.svelte';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';
	import { currencyDisplay, defaultCurrency, formatDate, formatDateTime, numberLocale, setUserPreferences, t, uiLanguage } from '#lib/i18n';

	let user: AuthUser | null = $state(null);
	let name = $state('');
	let email = $state('');
	let loading = $state(true);
	let busy = $state(false);
	let message = $state('');
	let error = $state('');
	let currentPassword = $state('');
	let newPassword = $state('');
	let passwordConfirmation = $state('');
	let passwordBusy = $state(false);
	let passwordMessage = $state('');
	let passwordError = $state('');
	type McpToken = { id: number; name: string; scopes: string[]; expires_at: string | null; revoked_at: string | null; last_used_at: string | null; created_at: string | null };
	type McpAction = { id: string; action: string; state: string; details?: Record<string, unknown>; meter?: string; reading_date?: string; value?: string; unit?: string; note?: string | null; token_name: string; expires_at: string };
	let mcpEndpoint = $state('');
	let allowedScopes = $state<string[]>([]);
	let selectedScopes = $state<string[]>([]);
	let mcpTokens = $state<McpToken[]>([]);
	let mcpActions = $state<McpAction[]>([]);
	let tokenName = $state('');
	let tokenExpiry = $state(90);
	let tokenNeverExpires = $state(false);
	let newSecret = $state('');
	let mcpMessage = $state('');
	let mcpError = $state('');
	let mcpBusy = $state(false);
	let mcpCreateOpen = $state(false);
	let mcpRevokeOpen = $state(false);
	let tokenToRevoke = $state<McpToken | null>(null);

	onMount(() => {
		void loadProfile();
		void loadMcpAccess();
	});

	const scopeLabels: Record<string, string> = {
		'core:profile:view': 'View your basic profile preferences',
		'tracking:view': 'View permitted tracking summaries',
		'finance:view': 'View permitted monthly finance totals',
		'utilities:view': 'View permitted utility readings',
		'utilities:create': 'Prepare utility readings for your approval',
		'vehicles:view': 'View permitted vehicle odometer summaries'
	};

	const languageOptions = [
		{ value: 'en', label: 'English', icon: 'language', marker: 'EN' },
		{ value: 'de', label: 'German', icon: 'language', marker: 'DE' }
	] as const;
	const moduleScopeLabels: Record<string, string> = {
		tracking: 'Tracking', utilities: 'Utilities', vehicles: 'Vehicles', finance: 'Finance', recipes: 'Recipes',
		core: 'Core', meal_planning: 'Meal Planning', pantry: 'Pantry', shopping: 'Shopping', analytics: 'Analytics', tasks: 'Tasks', import: 'Import', notes: 'Notes', receipts: 'Receipts'
	};

	function scopeLabel(scope: string): string {
		if (scopeLabels[scope]) return $t(scopeLabels[scope]);
		const [module, capability] = scope.split(':');
		const labels: Record<string, string> = { view: 'View access', create: 'Create access', edit: 'Edit access', manage: 'Manage access' };
		return `${$t(labels[capability] || capability)} · ${$t(moduleScopeLabels[module] || module)}`;
	}

	const approvalFieldLabels: Record<string, string> = {
		recipe_id: 'Recipe', tracker_id: 'Tracker', schema_version: 'Tracker schema version', recorded_at: 'Recorded at',
		vehicle_id: 'Vehicle', reading_id: 'Reading', reading_date: 'Date', meter_id: 'Meter', list_id: 'Shopping list',
		item_id: 'Item', slot_id: 'Meal plan slot', task_id: 'Task', widget_id: 'Analytics widget', transaction_id: 'Transaction',
		finance_category_id: 'Finance category', expected_version: 'Record version', quantity_delta: 'Quantity change',
		category_id: 'Finance category', budget_id: 'Finance budget', entry_id: 'Recurring entry', amount: 'Amount', currency: 'Currency',
		period_start: 'Period start', period_end: 'Period end', frequency: 'Frequency', starts_on: 'Starts on', next_due_on: 'Next due date', ends_on: 'Ends on',
		is_active: 'Active', meter_type: 'Meter type', alert_threshold: 'Alert threshold', make: 'Make', model: 'Model', model_year: 'Model year',
		fuel_type: 'Fuel type', odometer_unit: 'Odometer unit', title: 'Title', service_type: 'Service type', occurred_on: 'Occurred on',
		odometer_value: 'Odometer value', provider: 'Provider', coverage_type: 'Coverage type', valid_from: 'Valid from', valid_until: 'Valid until',
		fields: 'Fields', label: 'Field name', type: 'Data type', unit: 'Unit', is_required: 'Required field', minimum: 'Minimum', maximum: 'Maximum', visibility: 'Visibility',
		best_before_on: 'Best-before date', planned_for: 'Planned for', dish_type: 'Dish type', dish_name: 'Dish',
		meal_type: 'Meal', metric_key: 'Metric', range_key: 'Time range', chart_type: 'Chart type', preparation_minutes: 'Preparation minutes',
		recurrence_frequency: 'Recurrence', recurrence_interval: 'Recurrence interval', assignee_id: 'Assignee'
	};

	function approvalFieldLabel(key: string): string {
		return $t(approvalFieldLabels[key] ?? key.replaceAll('_', ' '));
	}

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
			setUserPreferences(result.user);
		} catch {
			error = 'Profile is unavailable. Check your connection and try again.';
		} finally {
			loading = false;
		}
	}

	function changeLanguage(language: 'en' | 'de'): void {
		$uiLanguage = language;
		$numberLocale = language === 'de' ? 'de-DE' : 'en-US';
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
				body: JSON.stringify({
					name, email, language: $uiLanguage, number_locale: $numberLocale,
					default_currency: $defaultCurrency, currency_display: $currencyDisplay
				})
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
			setUserPreferences(result.user);
			message = 'Your profile has been updated.';
		} catch {
			error = 'Profile could not be saved. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}

	async function changePassword(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		passwordMessage = '';
		passwordError = '';
		const token = csrfToken();
		if (!token) {
			passwordError = 'Reload this page and try again.';
			return;
		}

		passwordBusy = true;
		try {
			const response = await fetch('/api/profile/password', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-XSRF-TOKEN': token
				},
				body: JSON.stringify({
					current_password: currentPassword,
					password: newPassword,
					password_confirmation: passwordConfirmation
				})
			});
			const result = (await response.json()) as ApiResponse;
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (!response.ok) {
				passwordError = firstError(result) || result.message || 'Password could not be changed.';
				return;
			}

			currentPassword = '';
			newPassword = '';
			passwordConfirmation = '';
			passwordMessage = result.message ?? 'Your password has been changed.';
		} catch {
			passwordError = 'Password could not be changed. Check your connection and try again.';
		} finally {
			passwordBusy = false;
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
				expires_in_days: tokenNeverExpires ? null : tokenExpiry
			});
			const result = await response.json();
			if (!response.ok) {
				mcpError = firstError(result) || 'The MCP token could not be created.';
				return;
			}
			newSecret = result.secret;
			tokenName = '';
			mcpCreateOpen = false;
			mcpMessage = result.message;
			await loadMcpAccess();
		} catch {
			mcpError = 'The MCP token could not be created. Check your connection and try again.';
		} finally {
			mcpBusy = false;
		}
	}

	async function revokeMcpToken(id: number): Promise<boolean> {
		mcpError = '';
		try {
			const response = await submitMcp('DELETE', `/api/profile/mcp/tokens/${id}`);
			const result = await response.json();
			if (!response.ok) throw new Error(firstError(result) || 'The token could not be revoked.');
			newSecret = '';
			mcpMessage = 'The MCP token has been revoked.';
			await loadMcpAccess();
			return true;
		} catch (cause) {
			mcpError = cause instanceof Error ? cause.message : 'The token could not be revoked.';
			return false;
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
	<title>{$t('Your profile')} {$t("· Metricove")}</title>
	<meta name="description" content={$t('Update the name and email address used for this household account.')} />
</svelte:head>

<section class="mx-auto w-full max-w-screen-xl">
	<div class="mx-auto max-w-5xl">
		<section class="grid gap-8 lg:grid-cols-[0.9fr_1.1fr] lg:items-start lg:gap-16">
			<div class="max-w-xl">
				<PageHeading eyebrow="Account settings" title="Your Metricove profile" description="Update the name and email address used for this household account." />
				{#if user?.is_admin}
					<p class="mt-5"><Badge tone="accent">{$t('Household administrator')}</Badge></p>
				{/if}
				<ThemePicker class="mt-8 max-w-xs" />
			</div>

			<Panel padding="large" class="sm:p-8">
				{#if loading}
					<p role="status" class="text-sm text-ink-secondary">{$t('Loading your profile…')}</p>
				{:else if user}
					<h2 class="text-xl font-semibold">{$t('Profile details')}</h2>
					<form class="mt-6 space-y-5" onsubmit={saveProfile}>
						<TextField id="name" name="name" label={$t('Your name')} autocomplete="name" bind:value={name} required maxlength={255} />
						<TextField id="email" name="email" label={$t('Email address')} type="email" autocomplete="email" bind:value={email} required maxlength={255} />
						<div class="grid gap-4 sm:grid-cols-2">
						<IconChoiceGroup id="language" label="Language" value={$uiLanguage} options={languageOptions} onchange={(value) => changeLanguage(value as 'en' | 'de')} />
							<label class="block text-sm font-medium text-ink-secondary" for="number-locale">{$t('Number and date format')}
								<select id="number-locale" class="mt-2 w-full rounded-[10px] border border-line-strong bg-page px-3 py-3 text-ink" bind:value={$numberLocale}>
									<option value="de-DE">{$t('German (Germany)')} · 1.234,56</option><option value="en-US">{$t('English (United States)')} · 1,234.56</option><option value="en-GB">{$t('English (United Kingdom)')} · 1,234.56</option>
								</select>
							</label>
							<label class="block text-sm font-medium text-ink-secondary" for="default-currency">{$t('Currency')}
								<select id="default-currency" class="mt-2 w-full rounded-[10px] border border-line-strong bg-page px-3 py-3 text-ink" bind:value={$defaultCurrency}>
									<option>{$t("EUR")}</option><option>{$t("USD")}</option><option>{$t("GBP")}</option><option>{$t("CHF")}</option>
								</select>
							</label>
							<label class="block text-sm font-medium text-ink-secondary" for="currency-display">{$t('Currency display')}
								<select id="currency-display" class="mt-2 w-full rounded-[10px] border border-line-strong bg-page px-3 py-3 text-ink" bind:value={$currencyDisplay}>
									<option value="code">{$t('Currency code (EUR)')}</option><option value="symbol">{$t('Currency symbol (€)')}</option>
								</select>
							</label>
						</div>
						{#if message}<Notice tone="success" message={$t(message)} />{/if}
						{#if error}<Notice tone="error" message={$t(error)} />{/if}
						<Button type="submit" fullWidth disabled={busy}>
							{busy ? $t('Saving…') : $t('Save profile')}
						</Button>
					</form>
				{:else}
					<p role="alert" class="text-sm text-danger">{$t(error || 'Profile is unavailable.')}</p>
					<a href="/login" class="mt-5 inline-flex text-sm font-semibold text-accent-ink">{$t('Sign in')}</a>
				{/if}
			</Panel>
		</section>

		<section class="mb-12 max-w-2xl">
			<Panel padding="large" class="sm:p-8">
				<h2 class="text-xl font-semibold">{$t('Change password')}</h2>
				<p class="mt-2 text-sm leading-6 text-ink-muted">{$t('Confirm your current password and choose a new password with at least 12 characters.')}</p>
				{#if passwordMessage}<Notice class="mt-5" tone="success" message={passwordMessage} />{/if}
				{#if passwordError}<Notice class="mt-5" tone="error" message={passwordError} />{/if}
				<form class="mt-6 space-y-5" onsubmit={changePassword} aria-busy={passwordBusy}>
					<div>
						<label for="current-password" class="mb-2 block text-sm font-medium text-ink-secondary">{$t('Current password')}</label>
						<input id="current-password" name="current_password" type="password" autocomplete="current-password" bind:value={currentPassword} required class="w-full rounded-[10px] border border-line-strong bg-page px-3 py-3 text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20" />
					</div>
					<div>
						<label for="new-password" class="mb-2 block text-sm font-medium text-ink-secondary">{$t('New password')}</label>
						<input id="new-password" name="password" type="password" autocomplete="new-password" bind:value={newPassword} required minlength="12" class="w-full rounded-[10px] border border-line-strong bg-page px-3 py-3 text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20" />
					</div>
					<div>
						<label for="confirm-password" class="mb-2 block text-sm font-medium text-ink-secondary">{$t('Confirm new password')}</label>
						<input id="confirm-password" name="password_confirmation" type="password" autocomplete="new-password" bind:value={passwordConfirmation} required minlength="12" class="w-full rounded-[10px] border border-line-strong bg-page px-3 py-3 text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20" />
					</div>
					<Button type="submit" disabled={passwordBusy}>{passwordBusy ? $t('Changing…') : $t('Change password')}</Button>
				</form>
			</Panel>
		</section>

		<section class="mb-12 grid gap-8 lg:grid-cols-2">
			<div class="rounded-2xl border border-line glass-surface p-6 shadow-xs sm:p-8">
				<p class="text-sm font-medium text-ink-muted">{$t('External access')}</p>
				<h2 class="mt-2 text-2xl font-semibold">{$t('MCP access tokens')}</h2>
				<p class="mt-3 text-sm leading-6 text-ink-secondary">{$t('Use this endpoint with a Metricove bearer token. Tokens are shown once, can expire within one year or never expire, and can be revoked here. MCP uses Metricove-managed tokens; OAuth authorization-server discovery is not provided.')}</p>
				<a href="/mcp-docs" class="mt-3 inline-flex text-sm font-semibold text-accent-ink underline decoration-accent-ink/40 underline-offset-4">{$t('Read the MCP documentation')}</a>
				{#if mcpEndpoint}
					<p class="mt-3 break-all rounded-lg bg-page px-3 py-2 font-mono text-xs text-accent-ink">{mcpEndpoint}</p>
				{/if}
				{#if allowedScopes.length > 0}
					<button type="button" onclick={() => (mcpCreateOpen = true)} class="mt-6 inline-flex w-full items-center justify-center rounded-lg bg-accent px-4 py-3 text-sm font-semibold text-on-accent transition hover:bg-accent/90 disabled:opacity-50">{$t('Create MCP token')}</button>
					<Modal bind:open={mcpCreateOpen} title={$t('Create MCP token')}>
					<form class="space-y-4" onsubmit={createMcpToken}>
						<div>
							<label for="mcp-token-name" class="mb-2 block text-sm font-medium text-ink-secondary">{$t("Token name")}</label>
							<input id="mcp-token-name" bind:value={tokenName} required maxlength="100" autocomplete="off" class="w-full rounded-[10px] border border-line-strong bg-page px-3 py-2.5 text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20" />
						</div>
						<fieldset class="space-y-2">
							<legend class="mb-2 text-sm font-medium text-ink-secondary">{$t("Available scopes")}</legend>
							{#each allowedScopes as scope}
								<label class="flex items-start gap-3 rounded-lg border border-line px-3 py-2.5 text-sm text-ink-secondary">
					<input type="checkbox" value={scope} bind:group={selectedScopes} class="mt-0.5 accent-accent" />
									<span>{scopeLabel(scope)}<span class="mt-0.5 block font-mono text-xs text-ink-subtle">{scope}</span></span>
								</label>
							{/each}
						</fieldset>
						{#if !tokenNeverExpires}
							<div>
								<label for="mcp-token-expiry" class="mb-2 block text-sm font-medium text-ink-secondary">{$t("Expires in days")}</label>
								<input id="mcp-token-expiry" type="number" min="1" max="365" bind:value={tokenExpiry} required class="w-full rounded-[10px] border border-line-strong bg-page px-3 py-2.5 text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20" />
							</div>
						{/if}
						<label class="flex items-start gap-3 text-sm text-ink-secondary">
							<input type="checkbox" bind:checked={tokenNeverExpires} class="mt-0.5 accent-accent" />
							<span>{$t("Never expires")}</span>
						</label>
						{#if mcpError}<Notice tone="error" message={mcpError} />{/if}
						<button type="submit" disabled={mcpBusy || selectedScopes.length === 0} class="inline-flex w-full items-center justify-center rounded-lg bg-accent px-4 py-3 text-sm font-semibold text-on-accent transition hover:bg-accent/90 disabled:cursor-not-allowed disabled:opacity-60">{mcpBusy ? $t('Creating…') : $t('Create MCP token')}</button>
					</form>
					</Modal>
				{:else if !loading}
					<p class="mt-5 rounded-lg border border-line-strong bg-page px-3 py-3 text-sm text-ink-muted">{$t("No MCP scopes are currently available. Ask a household admin to enable a module and grant your account access.")}</p>
				{/if}
				{#if newSecret}
					<div class="mt-5 rounded-xl border border-warning/30 bg-warning/10 p-4">
						<p class="text-sm font-semibold text-warning">{$t('Copy this token now. It will not be shown again.')}</p>
						<code class="mt-3 block break-all rounded-lg bg-page p-3 text-xs text-ink">{newSecret}</code>
					</div>
				{/if}
				{#if mcpMessage}<Notice tone="success" message={mcpMessage} class="mt-4" />{/if}
				{#if mcpError}<Notice tone="error" message={mcpError} class="mt-4" />{/if}
				<div class="mt-8 space-y-3">
					<h3 class="text-base font-semibold">{$t('Your tokens')}</h3>
					{#if mcpTokens.length === 0}<p class="text-sm text-ink-muted">{$t('No tokens created yet.')}</p>{/if}
					{#each mcpTokens as token}
						<div class="rounded-xl border border-line bg-page p-4">
							<div class="flex flex-wrap items-start justify-between gap-3">
								<div><p class="font-medium text-ink">{token.name}</p><p class="mt-1 text-xs text-ink-muted">{token.scopes.join(', ')}</p><p class="mt-1 text-xs text-ink-subtle">{token.expires_at ? `${$t('Expires')} ${formatDate(token.expires_at)}` : $t('Never expires')}{token.revoked_at ? ` · ${$t('revoked')}` : ''}</p></div>
								{#if !token.revoked_at && (!token.expires_at || new Date(token.expires_at).getTime() > Date.now())}<button type="button" onclick={() => { tokenToRevoke = token; mcpRevokeOpen = true; }} class="rounded-lg border border-danger/30 px-3 py-2 text-xs font-semibold text-danger hover:bg-danger/10">{$t('Revoke')}</button>{/if}
							</div>
						</div>
					{/each}
				</div>
			</div>
			{#if tokenToRevoke}<ConfirmModal bind:open={mcpRevokeOpen} title={$t('Revoke MCP token?')} message={$t('Revoke the token {name}?', { name: tokenToRevoke.name })} confirmLabel={$t('Revoke')} busy={mcpBusy} onConfirm={async () => { if (await revokeMcpToken(tokenToRevoke!.id)) { mcpRevokeOpen = false; tokenToRevoke = null; } }} />{/if}

			<div class="rounded-2xl border border-line glass-surface p-6 shadow-xs sm:p-8">
				<p class="text-sm font-medium text-ink-muted">{$t('Human approval')}</p>
				<h2 class="mt-2 text-2xl font-semibold">{$t('Pending MCP actions')}</h2>
				<p class="mt-3 text-sm leading-6 text-ink-secondary">{$t('A connected MCP client cannot approve its own writes. Review each change here before the client can save it.')}</p>
				<div class="mt-6 space-y-4">
					{#if mcpActions.length === 0}<p class="rounded-lg border border-line bg-page px-3 py-3 text-sm text-ink-muted">{$t('No pending actions.')}</p>{/if}
					{#each mcpActions as action}
						<article class="rounded-xl border border-line bg-page p-4">
							<div class="flex items-start justify-between gap-3"><h3 class="font-semibold text-ink">{action.meter ?? $t(action.action)}</h3><span class="rounded-full border border-accent-ink/20 bg-accent/10 px-2.5 py-1 text-xs text-accent-ink">{action.state}</span></div>
							{#if action.meter}<dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-ink-subtle">{$t('Date')}</dt><dd class="mt-1 text-ink-secondary">{formatDate(action.reading_date ?? '')}</dd></div><div><dt class="text-ink-subtle">{$t('Reading')}</dt><dd class="mt-1 text-ink-secondary">{formatMeasurement(action.value ?? '', action.unit ?? '')} {action.unit}</dd></div>{#if action.note}<div class="col-span-2"><dt class="text-ink-subtle">{$t('Note')}</dt><dd class="mt-1 whitespace-pre-wrap text-ink-secondary">{action.note}</dd></div>{/if}</dl>{:else}<dl class="mt-4 space-y-2 text-sm">{#each Object.entries(action.details ?? {}) as [key, value]}<div class="grid grid-cols-[minmax(0,8rem)_1fr] gap-3"><dt class="break-words text-ink-subtle">{approvalFieldLabel(key)}</dt><dd class="break-words text-ink-secondary">{typeof value === 'string' ? value : JSON.stringify(value)}</dd></div>{/each}</dl>{/if}
							<dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div class="col-span-2"><dt class="text-ink-subtle">{$t('Requested by')}</dt><dd class="mt-1 text-ink-secondary">{action.token_name}</dd></div><div class="col-span-2"><dt class="text-ink-subtle">{$t('Expires')}</dt><dd class="mt-1 text-ink-secondary">{formatDateTime(action.expires_at)}</dd></div></dl>
							{#if action.state === 'pending'}<div class="mt-5 flex flex-wrap gap-3"><button type="button" onclick={() => decideMcpAction(action.id, 'approve')} class="rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-on-accent hover:bg-accent/90">{$t('Approve')}</button><button type="button" onclick={() => decideMcpAction(action.id, 'reject')} class="rounded-lg border border-line-strong px-4 py-2.5 text-sm font-semibold text-ink-secondary hover:bg-surface-subtle">{$t('Reject')}</button></div>{:else}<p class="mt-4 text-sm text-positive">{$t('Approved. The connected MCP client can complete this action until it expires.')}</p><button type="button" onclick={() => decideMcpAction(action.id, 'reject')} class="mt-3 rounded-lg border border-line-strong px-4 py-2.5 text-sm font-semibold text-ink-secondary hover:bg-surface-subtle">{$t('Cancel approval')}</button>{/if}
						</article>
					{/each}
				</div>
			</div>
		</section>
	</div>
</section>
