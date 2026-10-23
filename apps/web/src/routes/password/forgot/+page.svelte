<script lang="ts">
	import { t } from '#lib/i18n';
	import { onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';

	let email = '';
	let message = '';
	let error = '';
	let busy = false;

	onMount(() => {
		void fetch('/api/auth/session', { headers: { Accept: 'application/json' } }).catch(() => {});
	});

	async function requestReset(event: SubmitEvent): Promise<void> {
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
			const response = await fetch('/api/auth/password/email', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-XSRF-TOKEN': token
				},
				body: JSON.stringify({ email })
			});
			const result = (await response.json()) as ApiResponse;

			if (response.status === 429) {
				error = 'Too many requests. Wait a minute and try again.';
			} else if (response.status === 422) {
				error = firstError(result) || 'Enter a valid email address.';
			} else if (!response.ok) {
				error = 'Password reset is unavailable. Try again later.';
			} else {
				message = result.message ?? 'If an account exists for that address, instructions have been sent.';
			}
		} catch {
			error = 'Password reset is unavailable. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head>
	<title>{$t("Reset password")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Request a password reset for your Metricove account.")} />
</svelte:head>

<main class="min-h-screen bg-slate-950 px-6 py-10 text-slate-100 sm:px-10 sm:py-16">
	<div class="mx-auto flex min-h-[calc(100vh-5rem)] max-w-xl flex-col">
		<a href="/" class="flex items-center gap-3 text-sm font-semibold tracking-[0.18em] text-cyan-300 uppercase">
			<span class="grid size-9 place-items-center rounded-xl bg-cyan-300/10 text-lg">M</span>
			Metricove
		</a>

		<section class="my-auto rounded-2xl border border-slate-800 bg-slate-900/70 p-6 shadow-2xl shadow-black/20 sm:p-8">
			<p class="text-sm font-medium tracking-[0.18em] text-slate-400 uppercase">{$t("Account recovery")}</p>
			<h1 class="mt-3 text-3xl font-semibold tracking-tight">{$t("Reset your password")}</h1>
			<p class="mt-3 text-sm leading-6 text-slate-300">{$t("Enter your account email. If it matches an account, we will send a reset link.")}</p>

			<form class="mt-8 space-y-5" onsubmit={requestReset}>
				<div>
					<label for="email" class="mb-2 block text-sm font-medium text-slate-200">{$t("Email address")}</label>
					<input id="email" name="email" type="email" autocomplete="email" bind:value={email} required maxlength="255" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20" />
				</div>
				{#if message}
					<p role="status" class="rounded-lg border border-emerald-400/30 bg-emerald-400/10 px-3 py-2 text-sm text-emerald-200">{$t(message)}</p>
				{/if}
				{#if error}
					<p role="alert" class="rounded-lg border border-rose-400/30 bg-rose-400/10 px-3 py-2 text-sm text-rose-200">{$t(error)}</p>
				{/if}
				<button type="submit" disabled={busy} class="inline-flex w-full items-center justify-center rounded-lg bg-cyan-300 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:cursor-wait disabled:opacity-60">
					{$t(busy ? 'Sending…' : 'Send reset link')}
				</button>
			</form>

			<a href="/login" class="mt-5 inline-flex text-sm font-semibold text-cyan-200 hover:text-cyan-100">{$t("Return to sign in")}</a>
		</section>

		<footer class="border-t border-slate-800 pt-5 text-sm text-slate-500">{$t("Reset links are short-lived and can only be used once.")}</footer>
	</div>
</main>
