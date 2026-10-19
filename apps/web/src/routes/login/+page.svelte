<script lang="ts">
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import { csrfToken, type ApiResponse } from '#lib/auth';

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
		<a href="/" class="flex items-center gap-3 text-sm font-semibold tracking-[0.18em] text-cyan-300 uppercase">
			<span class="grid size-9 place-items-center rounded-xl bg-cyan-300/10 text-lg">M</span>
			Metricove
		</a>

		<section class="my-auto rounded-2xl border border-slate-800 bg-slate-900/70 p-6 shadow-2xl shadow-black/20 sm:p-8">
			<p class="text-sm font-medium tracking-[0.18em] text-slate-400 uppercase">Your household</p>
			<h1 class="mt-3 text-3xl font-semibold tracking-tight">Sign in</h1>
			<p class="mt-3 text-sm leading-6 text-slate-300">Use the email address registered for this installation.</p>

			<form class="mt-8 space-y-5" onsubmit={signIn}>
				<div>
					<label for="email" class="mb-2 block text-sm font-medium text-slate-200">Email address</label>
					<input id="email" name="email" type="email" autocomplete="username" bind:value={email} required maxlength="255" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" />
				</div>
				<div>
					<label for="password" class="mb-2 block text-sm font-medium text-slate-200">Password</label>
					<input id="password" name="password" type="password" autocomplete="current-password" bind:value={password} required class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" />
				</div>
				{#if error}
					<p role="alert" class="rounded-lg border border-rose-400/30 bg-rose-400/10 px-3 py-2 text-sm text-rose-200">{error}</p>
				{/if}
				<button type="submit" disabled={busy} class="inline-flex w-full items-center justify-center rounded-lg bg-cyan-300 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:cursor-wait disabled:opacity-60">
					{busy ? 'Signing in…' : 'Sign in'}
				</button>
			</form>

			<a href="/password/forgot" class="mt-5 inline-flex text-sm font-semibold text-cyan-200 hover:text-cyan-100">Forgot your password?</a>
		</section>

		<footer class="border-t border-slate-800 pt-5 text-sm text-slate-500">One private household per installation.</footer>
	</div>
</main>
