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
				if (result.user) void goto('/');
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
			void goto('/');
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
	<form class="mt-8 grid gap-5" onsubmit={signIn} aria-busy={busy}>
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
		<div>
			<TextField
				id="password"
				name="password"
				label="Password"
				type="password"
				autocomplete="current-password"
				bind:value={password}
				required
			/>
			<div class="mt-3 flex justify-end">
		<a href="/password/forgot" class="text-[0.8125rem] font-[650] text-accent-ink decoration-1 underline-offset-[0.2em] hover:text-ink focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent-ink">{$t('Forgot password?')}</a>
			</div>
		</div>
		{#if error}<Notice class="-mt-0.5" tone="error" message={$t(error)} />{/if}
		<Button type="submit" fullWidth disabled={busy} size="large">
			{busy ? $t('Signing in…') : $t('Sign in')}
		</Button>
	</form>
</AuthShell>
