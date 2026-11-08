<script lang="ts">
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import { t } from '#lib/i18n';
	import { formatDateTime } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import Modal from '#lib/ui/Modal.svelte';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';

	type Invitation = {
		id: number;
		email: string;
		created_at: string;
		updated_at: string;
		expires_at: string;
		is_expired: boolean;
	};

	type InvitationResponse = ApiResponse & {
		invitations?: Invitation[];
		invitation?: Invitation;
	};

	let email = $state('');
	let invitations = $state<Invitation[]>([]);
	let loading = $state(true);
	let busy = $state(false);
	let message = $state('');
	let error = $state('');
	let createOpen = $state(false);
	let revokeOpen = $state(false);
	let invitationToRevoke = $state<Invitation | null>(null);

	onMount(() => {
		void loadInvitations();
	});

	async function loadInvitations(): Promise<void> {
		try {
			const response = await fetch('/api/admin/invitations', {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' }
			});
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (response.status === 403) throw new Error('Only an active household administrator can manage invitations.');
			if (!response.ok) throw new Error('Invitations are unavailable. Check your connection and try again.');

			const result = (await response.json()) as InvitationResponse;
			invitations = result.invitations ?? [];
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Invitations are unavailable. Check your connection and try again.';
		} finally {
			loading = false;
		}
	}

	async function createInvitation(event: SubmitEvent): Promise<void> {
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
			const response = await fetch('/api/admin/invitations', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-XSRF-TOKEN': token
				},
				body: JSON.stringify({ email })
			});
			const result = (await response.json()) as InvitationResponse;
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (response.status === 429) {
				error = 'Too many invitation emails were requested. Wait a minute and try again.';
				return;
			}
			if (!response.ok) {
				error = firstError(result) || result.message || 'The invitation could not be sent.';
				await loadInvitations();
				return;
			}

			email = '';
			createOpen = false;
			message = result.message ?? 'Invitation email sent.';
			await loadInvitations();
		} catch {
			error = 'The invitation could not be sent. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}

	async function updateInvitation(invitation: Invitation, action: 'resend' | 'revoke'): Promise<void> {
		message = '';
		error = '';
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		busy = true;
		try {
			const response = await fetch(
				action === 'resend'
					? `/api/admin/invitations/${invitation.id}/resend`
					: `/api/admin/invitations/${invitation.id}`,
				{
					method: action === 'resend' ? 'POST' : 'DELETE',
					credentials: 'same-origin',
					headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token }
				}
			);
			const result = (await response.json()) as InvitationResponse;
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (response.status === 429) {
				error = 'Too many invitation emails were requested. Wait a minute and try again.';
				return;
			}
			if (!response.ok) {
				error = result.message || 'The invitation could not be updated.';
				return;
			}

			message = result.message ?? (action === 'resend' ? 'Invitation email sent again.' : 'Invitation revoked.');
			await loadInvitations();
			if (action === 'revoke') {
				revokeOpen = false;
				invitationToRevoke = null;
			}
		} catch {
			error = 'The invitation could not be updated. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head>
	<title>{$t("Invitations")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Invite members to your Metricove household.")} />
</svelte:head>

<section class="mx-auto w-full max-w-screen-xl">
	<div class="mx-auto max-w-5xl">
		<section class="grid gap-8 lg:grid-cols-[0.9fr_1.1fr] lg:items-start lg:gap-16">
			<div class="max-w-xl">
				<PageHeading eyebrow="Household access" title="Invite a member" description="Send an invitation using only their email address. They will choose their own name and password." />
			</div>

			<div class="space-y-6">
				<section class="rounded-2xl border border-line glass-surface p-6 shadow-xs sm:p-8" aria-labelledby="invite-heading">
					<h2 id="invite-heading" class="text-xl font-semibold">{$t("Send an invitation")}</h2>
					<button type="button" onclick={() => (createOpen = true)} class="mt-5 inline-flex items-center justify-center rounded-lg bg-accent px-4 py-3 text-sm font-semibold text-on-accent transition hover:bg-accent/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink">{$t("Send an invitation")}</button>
					{#if message}<Notice tone="success" message={message} class="mt-4" />{/if}
					{#if error}<Notice tone="error" message={error} class="mt-4" />{/if}
				</section>
				<Modal bind:open={createOpen} title={$t("Send an invitation")} description={$t("Send an invitation using only their email address. They will choose their own name and password.")}>
					<form class="space-y-5" onsubmit={createInvitation}>
						<div>
							<label for="email" class="mb-2 block text-sm font-medium text-ink-secondary">{$t("Recipient email")}</label>
							<input id="email" name="email" type="email" autocomplete="email" bind:value={email} required maxlength="255" class="w-full rounded-[10px] border border-line-strong bg-page px-3 py-2.5 text-ink outline-none focus:border-accent-ink focus:ring-2 focus:ring-accent-ink/20" />
						</div>
						{#if error}<Notice tone="error" message={error} />{/if}
						<button type="submit" disabled={busy} class="inline-flex w-full items-center justify-center rounded-lg bg-accent px-4 py-3 text-sm font-semibold text-on-accent transition hover:bg-accent/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink disabled:cursor-wait disabled:opacity-60">
							{$t(busy ? 'Sending…' : 'Send invitation')}
						</button>
					</form>
				</Modal>

				<section class="rounded-2xl border border-line glass-surface p-6 shadow-xs sm:p-8" aria-labelledby="pending-heading">
					<div class="flex flex-wrap items-center justify-between gap-3">
						<h2 id="pending-heading" class="text-xl font-semibold">{$t("Pending invitations")}</h2>
						<button type="button" onclick={() => void loadInvitations()} class="rounded-lg border border-line-strong px-3 py-2 text-sm font-medium text-ink-secondary transition hover:border-accent-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink">{$t("Refresh")}</button>
					</div>
					{#if loading}
						<p role="status" class="mt-5 text-sm text-ink-secondary">{$t("Loading invitations…")}</p>
					{:else if invitations.length === 0}
						<p class="mt-5 text-sm text-ink-muted">{$t("There are no pending invitations.")}</p>
					{:else}
						<ul class="mt-5 divide-y divide-line">
							{#each invitations as invitation (invitation.id)}
								<li class="flex flex-col gap-4 py-4 sm:flex-row sm:items-center sm:justify-between">
									<div class="min-w-0">
										<p class="break-all text-sm font-medium text-ink">{invitation.email}</p>
									<p class="mt-1 text-xs text-ink-muted">{$t(invitation.is_expired ? 'Expired' : 'Expires')} {formatDateTime(invitation.expires_at)}</p>
									</div>
									<div class="flex shrink-0 gap-2">
									<button type="button" disabled={busy} onclick={() => void updateInvitation(invitation, 'resend')} aria-label={$t('Resend invitation to {email}', { email: invitation.email })} class="rounded-lg border border-line-strong px-3 py-2 text-sm font-medium text-ink-secondary transition hover:border-accent-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink disabled:opacity-50">{$t("Resend")}</button>
									<button type="button" disabled={busy} onclick={() => { invitationToRevoke = invitation; revokeOpen = true; }} aria-label={$t('Revoke invitation to {email}', { email: invitation.email })} class="rounded-lg border border-line-strong px-3 py-2 text-sm font-medium text-danger transition hover:border-danger focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-danger disabled:opacity-50">{$t("Revoke")}</button>
									</div>
								</li>
							{/each}
						</ul>
					{/if}
				</section>
				{#if invitationToRevoke}<ConfirmModal bind:open={revokeOpen} title={$t('Revoke invitation?')} message={$t('Revoke the invitation sent to {email}?', { email: invitationToRevoke.email })} confirmLabel={$t('Revoke')} busy={busy} onConfirm={() => void updateInvitation(invitationToRevoke!, 'revoke')} />{/if}
			</div>
		</section>

		<footer class="border-t border-line pt-5 text-sm text-ink-subtle">{$t("Each invitation shows its expiry date and works once.")}</footer>
	</div>
</section>
