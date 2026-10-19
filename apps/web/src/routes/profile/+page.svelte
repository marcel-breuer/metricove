<script lang="ts">
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse, type AuthUser } from '#lib/auth';

	let user: AuthUser | null = null;
	let name = '';
	let email = '';
	let loading = true;
	let busy = false;
	let message = '';
	let error = '';

	onMount(() => {
		void loadProfile();
	});

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

	async function signOut(): Promise<void> {
		error = '';
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		try {
			const response = await fetch('/api/auth/logout', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token }
			});
			if (!response.ok) throw new Error('Sign-out failed.');
			await goto('/login');
		} catch {
			error = 'Sign-out is unavailable. Check your connection and try again.';
		}
	}
</script>

<svelte:head>
	<title>Your profile · Metricove</title>
	<meta name="description" content="Update your Metricove household profile." />
</svelte:head>

<main class="min-h-screen bg-slate-950 px-6 py-10 text-slate-100 sm:px-10 sm:py-16">
	<div class="mx-auto flex min-h-[calc(100vh-5rem)] max-w-5xl flex-col">
		<header class="flex flex-wrap items-center justify-between gap-4">
			<a href="/" class="flex items-center gap-3 text-sm font-semibold tracking-[0.18em] text-cyan-300 uppercase">
				<span class="grid size-9 place-items-center rounded-xl bg-cyan-300/10 text-lg">M</span>
				Metricove
			</a>
			<button type="button" onclick={signOut} class="rounded-lg border border-slate-700 px-4 py-2 text-sm font-medium text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">
				Sign out
			</button>
			{#if user?.is_admin}
				<a href="/admin/members" class="rounded-lg border border-slate-700 px-4 py-2 text-sm font-medium text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">
					Manage members
				</a>
				<a href="/admin/invitations" class="rounded-lg border border-slate-700 px-4 py-2 text-sm font-medium text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">
					Manage invitations
				</a>
			{/if}
		</header>

		<section class="my-auto grid gap-10 py-12 lg:grid-cols-[0.9fr_1.1fr] lg:items-center lg:gap-16">
			<div class="max-w-xl">
				<p class="mb-5 text-sm font-medium tracking-[0.18em] text-slate-400 uppercase">Account settings</p>
				<h1 class="text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">Your Metricove profile.</h1>
				<p class="mt-6 text-base leading-7 text-slate-300">Update the name and email address used for this household account.</p>
				{#if user?.is_admin}
					<p class="mt-5 inline-flex rounded-full border border-cyan-300/20 bg-cyan-300/10 px-3 py-1 text-sm font-medium text-cyan-200">Household administrator</p>
				{/if}
			</div>

			<div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-6 shadow-2xl shadow-black/20 sm:p-8">
				{#if loading}
					<p role="status" class="text-sm text-slate-300">Loading your profile…</p>
				{:else if user}
					<h2 class="text-xl font-semibold">Profile details</h2>
					<form class="mt-6 space-y-5" onsubmit={saveProfile}>
						<div>
							<label for="name" class="mb-2 block text-sm font-medium text-slate-200">Your name</label>
							<input id="name" name="name" autocomplete="name" bind:value={name} required maxlength="255" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" />
						</div>
						<div>
							<label for="email" class="mb-2 block text-sm font-medium text-slate-200">Email address</label>
							<input id="email" name="email" type="email" autocomplete="email" bind:value={email} required maxlength="255" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" />
						</div>
						{#if message}
							<p role="status" class="rounded-lg border border-emerald-400/30 bg-emerald-400/10 px-3 py-2 text-sm text-emerald-200">{message}</p>
						{/if}
						{#if error}
							<p role="alert" class="rounded-lg border border-rose-400/30 bg-rose-400/10 px-3 py-2 text-sm text-rose-200">{error}</p>
						{/if}
						<button type="submit" disabled={busy} class="inline-flex w-full items-center justify-center rounded-lg bg-cyan-300 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:cursor-wait disabled:opacity-60">
							{busy ? 'Saving…' : 'Save profile'}
						</button>
					</form>
				{:else}
					<p role="alert" class="text-sm text-rose-200">{error || 'Profile is unavailable.'}</p>
					<a href="/login" class="mt-5 inline-flex text-sm font-semibold text-cyan-200">Sign in</a>
				{/if}
			</div>
		</section>

		<footer class="border-t border-slate-800 pt-5 text-sm text-slate-500">
			<a href="/" class="hover:text-slate-300">Return home</a>
		</footer>
	</div>
</main>
