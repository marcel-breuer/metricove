<script lang="ts">
	import Notice from '#lib/ui/Notice.svelte';
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

<main class="min-h-screen bg-page px-6 py-10 text-ink sm:px-10 sm:py-16">
	<div class="mx-auto flex min-h-[calc(100vh-5rem)] max-w-xl flex-col">
		<a href="/" class="flex items-center gap-3 text-sm font-semibold text-accent-ink">
			<span class="grid size-9 place-items-center rounded-xl bg-accent/10 text-lg">M</span>
			Metricove
		</a>

		<section class="my-auto rounded-[20px] border glass-surface p-6 sm:p-8">
			<p class="text-sm font-medium text-ink-muted">{$t("Account recovery")}</p>
			<h1 class="mt-3 text-3xl font-semibold tracking-tight">{$t("Reset your password")}</h1>
			<p class="mt-3 text-sm leading-6 text-ink-secondary">{$t("Enter your account email. If it matches an account, we will send a reset link.")}</p>

			<form class="mt-8 space-y-5" onsubmit={requestReset}>
				<div>
					<label for="email" class="mb-2 block text-sm font-medium text-ink-secondary">{$t("Email address")}</label>
					<input id="email" name="email" type="email" autocomplete="email" bind:value={email} required maxlength="255" class="w-full rounded-[10px] border border-line-strong bg-page px-3 py-2.5 text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20" />
				</div>
				{#if message}
					<Notice tone="success" message={message} />
				{/if}
				{#if error}
					<Notice tone="error" message={error} />
				{/if}
				<button type="submit" disabled={busy} class="inline-flex w-full items-center justify-center rounded-lg bg-accent px-4 py-3 text-sm font-semibold text-on-accent transition hover:bg-accent/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink disabled:cursor-wait disabled:opacity-60">
					{$t(busy ? 'Sending…' : 'Send reset link')}
				</button>
			</form>

			<a href="/login" class="mt-5 inline-flex text-sm font-semibold text-accent-ink hover:text-accent-ink">{$t("Return to sign in")}</a>
		</section>

		<footer class="border-t border-line pt-5 text-sm text-ink-subtle">{$t("Reset links are short-lived and can only be used once.")}</footer>
	</div>
</main>
