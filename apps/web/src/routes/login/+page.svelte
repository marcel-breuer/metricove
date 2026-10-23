<script lang="ts">
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import { csrfToken, type ApiResponse } from '#lib/auth';
	import AuthShell from '#lib/ui/AuthShell.svelte';
	import Button from '#lib/ui/Button.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import TextField from '#lib/ui/TextField.svelte';
	import { t } from '#lib/i18n';

	let email = '';
	let password = '';
	let error = '';
	let busy = false;

	onMount(() => {
		void fetch('/api/auth/session', { headers: { Accept: 'application/json' } })
			.then(async (response) => {
				if (!response.ok) return;
				const result = (await response.json()) as ApiResponse;
				if (result.user) void goto('/profile');
			})
			.catch(() => {});
	});

	async function signIn(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		error = '';
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		busy = true;
		try {
			const response = await fetch('/api/auth/login', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-XSRF-TOKEN': token
				},
				body: JSON.stringify({ email, password })
			});
			const result = (await response.json()) as ApiResponse;

			if (response.status === 429) {
				error = 'Too many sign-in attempts. Wait a minute and try again.';
				return;
			}
			if (!response.ok || !result.user) {
				error = 'Email address or password is incorrect.';
				return;
			}

			password = '';
			await goto('/profile');
		} catch {
			error = 'Sign-in is unavailable. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head>
	<title>{$t('Sign in')} {$t("· Metricove")}</title>
	<meta name="description" content={$t('Sign in to your Metricove household.')} />
</svelte:head>

<AuthShell
	eyebrow="Welcome back"
	title="Sign in"
	description="Enter the email address and password registered for this installation."
	note="Your account is tied to this Metricove installation."
>
	<form class="login-form" onsubmit={signIn} aria-busy={busy}>
		<TextField
			id="email"
			name="email"
			label="Email address"
			type="email"
			autocomplete="username"
			autocapitalize="none"
			spellcheck={false}
			bind:value={email}
			required
			maxlength={255}
		/>
		<div class="login-password-group">
			<TextField
				id="password"
				name="password"
				label="Password"
				type="password"
				autocomplete="current-password"
				bind:value={password}
				required
			/>
			<div class="login-forgot-row">
		<a href="/password/forgot" class="login-forgot-link">{$t('Forgot password?')}</a>
			</div>
		</div>
		{#if error}<Notice class="login-error" tone="error" message={$t(error)} />{/if}
		<Button type="submit" fullWidth disabled={busy} class="login-submit">
			{busy ? $t('Signing in…') : $t('Sign in')}
		</Button>
	</form>
</AuthShell>

<style>
	.login-form {
		display: grid;
		gap: 1.25rem;
		margin-top: 2rem;
	}

	.login-forgot-row {
		display: flex;
		justify-content: flex-end;
		margin-top: 0.75rem;
	}

	.login-forgot-link {
		color: var(--accent-text);
		font-size: 0.8125rem;
		font-weight: 650;
		text-decoration-thickness: 1px;
		text-underline-offset: 0.2em;
	}

	.login-forgot-link:focus-visible {
		border-radius: 0.25rem;
		outline: 2px solid var(--accent-text);
		outline-offset: 4px;
	}

	.login-forgot-link:hover {
		color: var(--text-primary);
	}

	:global(.login-error) {
		margin-top: -0.125rem;
	}

	:global(.login-submit) {
		min-height: 3rem;
		border-radius: 0.8rem;
		font-size: 0.9375rem;
	}
</style>
