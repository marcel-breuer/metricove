<script lang="ts">
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import { csrfToken, type ApiResponse } from '#lib/auth';
	import Button from '#lib/ui/Button.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import Panel from '#lib/ui/Panel.svelte';
	import TextField from '#lib/ui/TextField.svelte';
	import ThemePicker from '#lib/ui/ThemePicker.svelte';

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
	<title>Sign in · Metricove</title>
	<meta name="description" content="Sign in to your Metricove household." />
</svelte:head>

<main class="min-h-screen bg-slate-950 px-6 py-10 text-slate-100 sm:px-10 sm:py-16">
	<div class="mx-auto flex min-h-[calc(100vh-5rem)] max-w-xl flex-col">
		<header class="flex flex-wrap items-center justify-between gap-5">
			<a href="/" class="flex items-center gap-3 text-sm font-semibold tracking-[0.18em] text-cyan-300 uppercase">
				<span class="grid size-9 place-items-center rounded-xl bg-cyan-300/10 text-lg">M</span>
				Metricove
			</a>
			<ThemePicker class="w-48" />
		</header>

		<Panel class="my-auto sm:p-8" padding="large">
			<p class="text-sm font-medium tracking-[0.18em] text-slate-400 uppercase">Your household</p>
			<PageHeading class="mt-3" title="Sign in" description="Use the email address registered for this installation." />

			<form class="mt-8 space-y-5" onsubmit={signIn}>
				<TextField id="email" name="email" label="Email address" type="email" autocomplete="username" bind:value={email} required maxlength={255} />
				<TextField id="password" name="password" label="Password" type="password" autocomplete="current-password" bind:value={password} required />
				{#if error}<Notice tone="error" message={error} />{/if}
				<Button type="submit" fullWidth disabled={busy}>
					{busy ? 'Signing in…' : 'Sign in'}
				</Button>
			</form>

			<a href="/password/forgot" class="mt-5 inline-flex text-sm font-semibold text-cyan-200 hover:text-cyan-100">Forgot your password?</a>
		</Panel>

		<footer class="border-t border-slate-800 pt-5 text-sm text-slate-500">One private household per installation.</footer>
	</div>
</main>
