<script lang="ts">
	import { goto } from '$app/navigation';
	import { t } from '#lib/i18n';
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
			void goto('/');
		} catch {
			formError = 'The server could not complete setup. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head>
	<title>{$t("Set up your household")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Create the first administrator for this Metricove household.")} />
</svelte:head>

<AuthShell
	eyebrow="One-time household setup"
	title="Set up your household."
	description="Create the first administrator account for this installation. Setup closes after the first account is created."
	wide
>
	{#if state === 'loading'}
		<p class="mt-5 text-[0.9375rem] leading-[1.7] text-ink-muted" role="status">{$t("Checking household setup…")}</p>
	{:else if state === 'available'}
		<h2 id="setup-form-heading" class="mt-4 text-lg leading-[1.35] font-[650] tracking-[-0.02em] text-ink max-[760px]:mt-3.5">{$t("Create your administrator account")}</h2>
		<form class="mt-3.5 grid gap-3.5 max-[760px]:mt-3 max-[760px]:gap-3" onsubmit={completeSetup} aria-labelledby="setup-form-heading" aria-busy={busy}>
			<TextField id="name" name="name" label="Your name" autocomplete="name" bind:value={name} required maxlength={255} />
			<TextField id="email" name="email" label="Email address" type="email" autocomplete="email" bind:value={email} required maxlength={255} />
			<div>
				<TextField id="password" name="password" label="Password" type="password" autocomplete="new-password" aria-describedby="setup-password-guidance" bind:value={password} minlength={12} required />
				<p id="setup-password-guidance" class="mt-2 text-xs text-ink-subtle">{$t("Use at least 12 characters.")}</p>
			</div>
			<TextField id="password_confirmation" name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" aria-describedby="setup-password-guidance" bind:value={passwordConfirmation} required />
			{#if formError}
				<Notice tone="error" message={$t(formError)} />
			{/if}
			<Button type="submit" fullWidth disabled={busy} size="large" class="mt-1">
				{$t(busy ? 'Creating account…' : 'Create administrator account')}
			</Button>
		</form>
	{:else if state === 'created'}
		<div role="status" aria-live="polite">
			<p class="mt-7 text-xs font-semibold text-positive">{$t("Household ready")}</p>
			<h2 class="mt-3 text-[1.75rem] leading-[1.35] font-[650] tracking-[-0.02em] text-ink">{$t("Welcome,")} {createdName}.</h2>
			<p class="mt-5 text-[0.9375rem] leading-[1.7] text-ink-muted">
				{$t("Your administrator account is active. Choose the features your household wants to use; you can change them later.")}
			</p>
			<Button href="/" class="mt-5">
				{$t("Open dashboard")}
			</Button>
		</div>
	{:else if state === 'complete'}
		<div role="status">
			<p class="mt-7 text-xs font-semibold text-positive">{$t("Setup is complete")}</p>
			<h2 class="mt-3 text-[1.75rem] leading-[1.35] font-[650] tracking-[-0.02em] text-ink">{$t("This household is already set up.")}</h2>
			<p class="mt-5 text-[0.9375rem] leading-[1.7] text-ink-muted">{$t("Initial registration is closed for this installation.")}</p>
			<Button href="/login" variant="secondary" class="mt-5">
				{$t("Go to sign in")}
			</Button>
		</div>
	{:else}
		<div>
			<p class="mt-4 text-lg leading-[1.35] font-[650] tracking-[-0.02em] text-ink max-[760px]:mt-3.5" role="alert">{$t("Setup status could not be loaded.")}</p>
			<Button variant="secondary" class="mt-5" onclick={loadStatus}>
				{$t("Try again")}
			</Button>
		</div>
	{/if}
</AuthShell>
