<script lang="ts">
	import Notice from '#lib/ui/Notice.svelte';
	import { t } from '#lib/i18n';
	import { onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';

	let resetToken = '';
	let email = '';
	let password = '';
	let confirmation = '';
	let message = '';
	let error = '';
	let busy = false;

	onMount(() => {
		resetToken = new URLSearchParams(window.location.hash.slice(1)).get('token') ?? '';
		window.history.replaceState(
			window.history.state,
			'',
			window.location.pathname + window.location.search
		);
		if (!resetToken) error = 'This reset link is invalid or has expired. Request a new one.';
		void fetch('/api/auth/session', { headers: { Accept: 'application/json' } }).catch(() => {});
	});

	async function resetPassword(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		message = '';
		error = '';
		if (!resetToken) {
			error = 'This reset link is invalid or has expired. Request a new one.';
			return;
		}
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		busy = true;
		try {
			const response = await fetch('/api/auth/password/reset', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-XSRF-TOKEN': token
				},
				body: JSON.stringify({
					token: resetToken,
					email,
					password,
					password_confirmation: confirmation
				})
			});
			const result = (await response.json()) as ApiResponse;

			if (response.status === 429) {
				error = 'Too many requests. Wait a minute and try again.';
			} else if (response.status === 422) {
				error = firstError(result) || 'This reset link is invalid or has expired.';
			} else if (!response.ok) {
				error = 'Password reset is unavailable. Try again later.';
			} else {
				password = '';
				confirmation = '';
				message = result.message ?? 'Your password has been reset.';
			}
		} catch {
			error = 'Password reset is unavailable. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head>
	<title>{$t("Choose a new password")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Choose a new password for your Metricove account.")} />
</svelte:head>

<main class="min-h-screen bg-page px-6 py-10 text-ink sm:px-10 sm:py-16">
	<div class="mx-auto flex min-h-[calc(100vh-5rem)] max-w-xl flex-col">
		<a href="/" class="flex items-center gap-3 text-sm font-semibold text-accent-ink">
			<span class="grid size-9 place-items-center rounded-xl bg-accent/10 text-lg">M</span>
			Metricove
		</a>

		<section class="my-auto rounded-[20px] border glass-surface p-6 sm:p-8">
			<p class="text-sm font-medium text-ink-muted">{$t("Account recovery")}</p>
			<h1 class="mt-3 text-3xl font-semibold tracking-tight">{$t("Choose a new password")}</h1>
			<form class="mt-8 space-y-5" onsubmit={resetPassword}>
				<div>
					<label for="email" class="mb-2 block text-sm font-medium text-ink-secondary">{$t("Email address")}</label>
					<input id="email" name="email" type="email" autocomplete="email" bind:value={email} required maxlength="255" aria-describedby="email-help" class="w-full rounded-[10px] border border-line-strong bg-page px-3 py-2.5 text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20" />
					<p id="email-help" class="mt-2 text-xs text-ink-muted">{$t("Enter the email address that received the reset link.")}</p>
				</div>
				<div>
					<label for="password" class="mb-2 block text-sm font-medium text-ink-secondary">{$t("New password")}</label>
					<input id="password" name="password" type="password" autocomplete="new-password" bind:value={password} minlength="12" required class="w-full rounded-[10px] border border-line-strong bg-page px-3 py-2.5 text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20" />
					<p class="mt-2 text-xs text-ink-muted">{$t("Use at least 12 characters.")}</p>
				</div>
				<div>
					<label for="password_confirmation" class="mb-2 block text-sm font-medium text-ink-secondary">{$t("Confirm new password")}</label>
					<input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" bind:value={confirmation} required class="w-full rounded-[10px] border border-line-strong bg-page px-3 py-2.5 text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20" />
				</div>
				{#if message}
					<Notice tone="success" message={message} />
				{/if}
				{#if error}
					<Notice tone="error" message={error} />
				{/if}
				<button type="submit" disabled={busy || Boolean(message) || !resetToken} class="inline-flex w-full items-center justify-center rounded-lg bg-accent px-4 py-3 text-sm font-semibold text-on-accent transition hover:bg-accent/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink disabled:cursor-wait disabled:opacity-60">
					{$t(busy ? 'Saving…' : 'Set new password')}
				</button>
			</form>

			<a href="/login" class="mt-5 inline-flex text-sm font-semibold text-accent-ink hover:text-accent-ink">{$t("Return to sign in")}</a>
		</section>

		<footer class="border-t border-line pt-5 text-sm text-ink-subtle">{$t("The reset link expires and cannot be reused after a successful reset.")}</footer>
	</div>
</main>
