<script lang="ts">
	import { onMount } from 'svelte';
	import AuthShell from '#lib/ui/AuthShell.svelte';
	import Button from '#lib/ui/Button.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import TextField from '#lib/ui/TextField.svelte';

	type SetupState = 'loading' | 'available' | 'complete' | 'created' | 'error';
	type SetupResponse = {
		message?: string;
		user?: { name: string };
		errors?: Record<string, string[]>;
	};

	let state: SetupState = 'loading';
	let name = '';
	let email = '';
	let password = '';
	let passwordConfirmation = '';
	let formError = '';
	let createdName = '';
	let busy = false;

	onMount(() => {
		void loadStatus();
	});

	async function loadStatus(): Promise<void> {
		try {
			const response = await fetch('/api/setup/status', {
				headers: { Accept: 'application/json' }
			});

			if (!response.ok) {
				throw new Error('Setup status is unavailable.');
			}

			const result = (await response.json()) as { setup_required: boolean };
			state = result.setup_required ? 'available' : 'complete';
		} catch {
			state = 'error';
		}
	}

	function csrfToken(): string {
		const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));

		return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
	}

	async function completeSetup(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		formError = '';

		const token = csrfToken();
		if (!token) {
			formError = 'Reload this page and try again.';
			return;
		}

		busy = true;
		try {
			const response = await fetch('/api/setup', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-XSRF-TOKEN': token
				},
				body: JSON.stringify({
					name,
					email,
					password,
					password_confirmation: passwordConfirmation
				})
			});
			const result = (await response.json()) as SetupResponse;

			if (response.status === 409) {
				state = 'complete';
				return;
			}

			if (!response.ok || !result.user) {
				const validationMessage = Object.values(result.errors ?? {}).flat()[0];
				formError = validationMessage ?? result.message ?? 'Setup could not be completed.';
				return;
			}

			createdName = result.user.name;
			state = 'created';
		} catch {
			formError = 'The server could not complete setup. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head>
	<title>Set up your household · Metricove</title>
	<meta name="description" content="Create the first administrator for this Metricove household." />
</svelte:head>

<AuthShell
	eyebrow="One-time household setup"
	title="Set up your household."
	description="Create the first administrator account for this installation. Setup closes after the first account is created."
	wide
>
	{#if state === 'loading'}
		<p class="setup-status" role="status">Checking household setup…</p>
	{:else if state === 'available'}
		<h2 class="setup-state-title">Create your administrator account</h2>
		<form class="setup-form" onsubmit={completeSetup} aria-busy={busy}>
			<TextField id="name" name="name" label="Your name" autocomplete="name" bind:value={name} required maxlength={255} />
			<TextField id="email" name="email" label="Email address" type="email" autocomplete="email" bind:value={email} required maxlength={255} />
			<div class="setup-password-field">
				<TextField id="password" name="password" label="Password" type="password" autocomplete="new-password" bind:value={password} minlength={12} required />
				<p>Use at least 12 characters.</p>
			</div>
			<TextField id="password_confirmation" name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" bind:value={passwordConfirmation} required />
			{#if formError}
				<Notice tone="error" message={formError} />
			{/if}
			<Button type="submit" fullWidth disabled={busy} class="setup-submit">
				{busy ? 'Creating account…' : 'Create administrator account'}
			</Button>
		</form>
	{:else if state === 'created'}
		<div class="setup-state" role="status" aria-live="polite">
			<p class="setup-state-kicker">Household ready</p>
			<h2 class="setup-state-title">Welcome, {createdName}.</h2>
			<p class="setup-state-description">
				Your administrator account is active. Choose the features your household wants to use; you can change them later.
			</p>
			<a href="/admin/modules" class="ui-button ui-button--primary ui-button--medium setup-action">
				Choose household features
			</a>
		</div>
	{:else if state === 'complete'}
		<div class="setup-state" role="status">
			<p class="setup-state-kicker">Setup is complete</p>
			<h2 class="setup-state-title">This household is already set up.</h2>
			<p class="setup-state-description">Initial registration is closed for this installation.</p>
			<a href="/login" class="ui-button ui-button--secondary ui-button--medium setup-action">
				Go to sign in
			</a>
		</div>
	{:else}
		<div class="setup-state">
			<p class="setup-state-title" role="alert">Setup status could not be loaded.</p>
			<Button variant="secondary" class="setup-action" onclick={loadStatus}>
				Try again
			</Button>
		</div>
	{/if}
</AuthShell>

<style>
	.setup-status,
	.setup-state-description {
		margin: 1.25rem 0 0;
		color: var(--text-muted);
		font-size: 0.9375rem;
		line-height: 1.7;
	}

	.setup-state-title {
		margin: 1rem 0 0;
		color: var(--text-primary);
		font-size: 1.125rem;
		font-weight: 650;
		letter-spacing: -0.02em;
		line-height: 1.35;
	}

	.setup-form {
		display: grid;
		gap: 0.875rem;
		margin-top: 0.875rem;
	}

	.setup-password-field p {
		margin: 0.5rem 0 0;
		color: var(--text-subtle);
		font-size: 0.75rem;
	}

	:global(.setup-submit) {
		min-height: 3rem;
		margin-top: 0.25rem;
		border-radius: 0.8rem;
		font-size: 0.9375rem;
	}

	.setup-state-kicker {
		margin: 1.75rem 0 0;
		color: var(--positive-text);
		font-size: 0.75rem;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
	}

	.setup-state-kicker + .setup-state-title {
		margin-top: 0.75rem;
		font-size: 1.75rem;
	}

	.setup-action {
		margin-top: 1.25rem;
	}

	@media (max-width: 760px) {
		.setup-state-title {
			margin-top: 0.875rem;
		}

		.setup-form {
			gap: 0.75rem;
			margin-top: 0.75rem;
		}
	}
</style>
