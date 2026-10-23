<script lang="ts">
	import { t } from '#lib/i18n';
	import { onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';

	let invitationToken = '';
	let name = '';
	let password = '';
	let confirmation = '';
	let message = '';
	let error = '';
	let busy = false;

	onMount(() => {
		invitationToken = new URLSearchParams(window.location.hash.slice(1)).get('token') ?? '';
		window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search);
		if (!invitationToken) error = 'This invitation link is invalid or expired. Ask your household administrator for a new one.';
		void fetch('/api/auth/session', { headers: { Accept: 'application/json' } }).catch(() => {});
	});

	async function claimInvitation(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		message = '';
		error = '';
		if (!invitationToken) {
			error = 'This invitation link is invalid or expired. Ask your household administrator for a new one.';
			return;
		}
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		busy = true;
		try {
			const response = await fetch('/api/invitations/claim', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-XSRF-TOKEN': token
				},
				body: JSON.stringify({
					token: invitationToken,
					name,
					password,
					password_confirmation: confirmation
				})
			});
			const result = (await response.json()) as ApiResponse;
			if (response.status === 429) {
				error = 'Too many attempts. Wait a minute and try again.';
			} else if (!response.ok) {
				error = result.errors ? firstError(result) : (result.message ?? 'This invitation could not be claimed.');
			} else {
				password = '';
				confirmation = '';
				invitationToken = '';
				message = result.message ?? 'Your account is ready. You are signed in.';
			}
		} catch {
			error = 'The invitation could not be claimed. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head>
	<title>{$t("Accept invitation")} · Metricove</title>
	<meta name="description" content="Choose your name and password to join a Metricove household." />
</svelte:head>

<main class="min-h-screen bg-slate-950 px-6 py-10 text-slate-100 sm:px-10 sm:py-16">
	<div class="mx-auto flex min-h-[calc(100vh-5rem)] max-w-xl flex-col">
		<a href="/" class="flex items-center gap-3 text-sm font-semibold tracking-[0.18em] text-cyan-300 uppercase">
			<span class="grid size-9 place-items-center rounded-xl bg-cyan-300/10 text-lg">M</span>
			Metricove
		</a>

		<section class="my-auto rounded-2xl border border-slate-800 bg-slate-900/70 p-6 shadow-2xl shadow-black/20 sm:p-8">
			<p class="text-sm font-medium tracking-[0.18em] text-slate-400 uppercase">Household invitation</p>
			<h1 id="invitation-form-heading" class="mt-3 text-3xl font-semibold tracking-tight">{$t("Create your account")}</h1>
			<p class="mt-3 text-sm leading-6 text-slate-300">Choose the name and password you want to use for your Metricove account.</p>
			<form class="mt-8 space-y-5" onsubmit={claimInvitation} aria-labelledby="invitation-form-heading">
				<div>
					<label for="name" class="mb-2 block text-sm font-medium text-slate-200">Your name</label>
					<input id="name" name="name" autocomplete="name" bind:value={name} required maxlength="255" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" />
				</div>
				<div>
					<label for="password" class="mb-2 block text-sm font-medium text-slate-200">Password</label>
					<input id="password" name="password" type="password" autocomplete="new-password" aria-describedby="invitation-password-guidance" bind:value={password} minlength="12" required class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" />
					<p id="invitation-password-guidance" class="mt-2 text-xs text-slate-400">Use at least 12 characters.</p>
				</div>
				<div>
					<label for="password_confirmation" class="mb-2 block text-sm font-medium text-slate-200">Confirm password</label>
					<input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" aria-describedby="invitation-password-guidance" bind:value={confirmation} required class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" />
				</div>
				{#if message}
					<p role="status" class="rounded-lg border border-emerald-400/30 bg-emerald-400/10 px-3 py-2 text-sm text-emerald-200">{message}</p>
				{/if}
				{#if error}
					<p role="alert" class="rounded-lg border border-rose-400/30 bg-rose-400/10 px-3 py-2 text-sm text-rose-200">{error}</p>
				{/if}
				<button type="submit" disabled={busy || Boolean(message) || !invitationToken} class="inline-flex w-full items-center justify-center rounded-lg bg-cyan-300 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:cursor-wait disabled:opacity-60">
					{busy ? 'Creating account…' : 'Accept invitation'}
				</button>
			</form>

			{#if message}
				<a href="/profile" class="mt-5 inline-flex text-sm font-semibold text-cyan-200 hover:text-cyan-100">Continue to your profile</a>
			{:else}
				<a href="/login" class="mt-5 inline-flex text-sm font-semibold text-cyan-200 hover:text-cyan-100">Return to sign in</a>
			{/if}
		</section>

		<footer class="border-t border-slate-800 pt-5 text-sm text-slate-500">The invitation link expires according to this household's settings and cannot be reused.</footer>
	</div>
</main>
