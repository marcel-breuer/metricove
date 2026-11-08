<script lang="ts">
	import Notice from '#lib/ui/Notice.svelte';
	import { goto } from '$app/navigation';
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
				void goto('/');
			}
		} catch {
			error = 'The invitation could not be claimed. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head>
	<title>{$t("Accept invitation")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Choose your name and password to join a Metricove household.")} />
</svelte:head>

<main class="min-h-screen bg-page px-6 py-10 text-ink sm:px-10 sm:py-16">
	<div class="mx-auto flex min-h-[calc(100vh-5rem)] max-w-xl flex-col">
		<a href="/" class="flex items-center gap-3 text-sm font-semibold text-accent-ink">
			<span class="grid size-9 place-items-center rounded-xl bg-accent/10 text-lg">M</span>
			Metricove
		</a>

		<section class="my-auto rounded-2xl border border-line glass-surface p-6 shadow-xs sm:p-8">
			<p class="text-sm font-medium text-ink-muted">{$t("Household invitation")}</p>
			<h1 id="invitation-form-heading" class="mt-3 text-3xl font-semibold tracking-tight">{$t("Create your account")}</h1>
			<p class="mt-3 text-sm leading-6 text-ink-secondary">{$t("Choose the name and password you want to use for your Metricove account.")}</p>
			<form class="mt-8 space-y-5" onsubmit={claimInvitation} aria-labelledby="invitation-form-heading">
				<div>
					<label for="name" class="mb-2 block text-sm font-medium text-ink-secondary">{$t("Your name")}</label>
					<input id="name" name="name" autocomplete="name" bind:value={name} required maxlength="255" class="w-full rounded-[10px] border border-line-strong bg-page px-3 py-2.5 text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20" />
				</div>
				<div>
					<label for="password" class="mb-2 block text-sm font-medium text-ink-secondary">{$t("Password")}</label>
					<input id="password" name="password" type="password" autocomplete="new-password" aria-describedby="invitation-password-guidance" bind:value={password} minlength="12" required class="w-full rounded-[10px] border border-line-strong bg-page px-3 py-2.5 text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20" />
					<p id="invitation-password-guidance" class="mt-2 text-xs text-ink-muted">{$t("Use at least 12 characters.")}</p>
				</div>
				<div>
					<label for="password_confirmation" class="mb-2 block text-sm font-medium text-ink-secondary">{$t("Confirm password")}</label>
					<input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" aria-describedby="invitation-password-guidance" bind:value={confirmation} required class="w-full rounded-[10px] border border-line-strong bg-page px-3 py-2.5 text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20" />
				</div>
				{#if message}
					<Notice tone="success" message={message} />
				{/if}
				{#if error}
					<Notice tone="error" message={error} />
				{/if}
				<button type="submit" disabled={busy || Boolean(message) || !invitationToken} class="inline-flex w-full items-center justify-center rounded-lg bg-accent px-4 py-3 text-sm font-semibold text-on-accent transition hover:bg-accent/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink disabled:cursor-wait disabled:opacity-60">
					{$t(busy ? 'Creating account…' : 'Accept invitation')}
				</button>
			</form>

			{#if message}
				<a href="/" class="mt-5 inline-flex text-sm font-semibold text-accent-ink hover:text-accent-ink">{$t("Open dashboard")}</a>
			{:else}
				<a href="/login" class="mt-5 inline-flex text-sm font-semibold text-accent-ink hover:text-accent-ink">{$t("Return to sign in")}</a>
			{/if}
		</section>

		<footer class="border-t border-line pt-5 text-sm text-ink-subtle">{$t("The invitation link expires according to this household's settings and cannot be reused.")}</footer>
	</div>
</main>
