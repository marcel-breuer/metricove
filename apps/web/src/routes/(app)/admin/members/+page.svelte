<script lang="ts">
	import { t } from '#lib/i18n';
	import { formatDateTime } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse, type AuthUser } from '#lib/auth';

	type Member = AuthUser & {
		is_active: boolean;
		created_at: string;
	};

	type AccessState = {
		is_admin: boolean;
		is_active: boolean;
	};

	type AuditEvent = {
		id: number;
		actor_user_id: number | null;
		subject_user_id: number;
		action: string;
		details: {
			before?: AccessState | Record<string, boolean>;
			after?: AccessState | Record<string, boolean>;
		source?: string;
		module_key?: string;
		} | null;
		created_at: string;
	};

	type MemberResponse = ApiResponse & { members?: Member[]; member?: Member };
	type AuditResponse = ApiResponse & { events?: AuditEvent[] };
	type AccessChanges = Partial<Pick<Member, 'is_admin' | 'is_active'>>;

	let me: AuthUser | null = null;
	let members: Member[] = [];
	let events: AuditEvent[] = [];
	let loading = true;
	let busy = false;
	let message = '';
	let error = '';

	onMount(() => {
		void loadMembers();
	});

	async function loadMembers(): Promise<void> {
		try {
			const sessionResponse = await fetch('/api/auth/session', {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' }
			});
			if (sessionResponse.status === 401) {
				await goto('/login');
				return;
			}
			if (!sessionResponse.ok) throw new Error('Member information is unavailable.');
			me = ((await sessionResponse.json()) as ApiResponse).user ?? null;

			const [memberResponse, auditResponse] = await Promise.all([
				fetch('/api/admin/members', { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
				fetch('/api/admin/member-audit', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			]);
			if (memberResponse.status === 401 || auditResponse.status === 401) {
				await goto('/login');
				return;
			}
			if (memberResponse.status === 403 || auditResponse.status === 403) {
				throw new Error('Only an active household administrator can manage members.');
			}
			if (!memberResponse.ok || !auditResponse.ok) throw new Error('Member information is unavailable. Check your connection and try again.');

			members = ((await memberResponse.json()) as MemberResponse).members ?? [];
			events = ((await auditResponse.json()) as AuditResponse).events ?? [];
			error = '';
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Member information is unavailable. Check your connection and try again.';
		} finally {
			loading = false;
		}
	}

	async function updateMember(member: Member, changes: AccessChanges): Promise<void> {
		message = '';
		error = '';
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		busy = true;
		try {
			const response = await fetch(`/api/admin/members/${member.id}`, {
				method: 'PATCH',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify(changes)
			});
			const result = (await response.json()) as MemberResponse;
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (!response.ok) {
				error = firstError(result) || result.message || 'Member access could not be updated.';
				return;
			}

			message = result.message ?? 'Member access updated.';
			await loadMembers();
		} catch {
			error = 'Member access could not be updated. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}

	async function removeMember(member: Member): Promise<void> {
		if (!window.confirm(`Remove ${member.name} from this household? This cannot be undone.`)) return;
		message = '';
		error = '';
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		busy = true;
		try {
			const response = await fetch(`/api/admin/members/${member.id}`, {
				method: 'DELETE',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token }
			});
			const result = (await response.json()) as ApiResponse;
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (!response.ok) {
				error = result.message || 'Member could not be removed.';
				return;
			}

			if (member.id === me?.id) {
				await goto('/login');
				return;
			}
			message = result.message ?? 'Member removed.';
			await loadMembers();
		} catch {
			error = 'Member could not be removed. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}

	function eventLabel(action: string): string {
		const labels: Record<string, string> = {
			'member.promoted': 'Administrator access granted',
			'member.demoted': 'Administrator access removed',
			'member.activated': 'Member reactivated',
			'member.suspended': 'Member suspended',
			'member.access_changed': 'Member access changed',
			'member.module_permissions_changed': 'Module permissions changed',
			'member.removed': 'Member removed',
			'admin.recovered': 'Administrator access recovered'
		};
		return labels[action] ?? 'Member access updated';
	}

	function eventSummary(event: AuditEvent): string {
		if (event.action === 'member.removed') return `Member #${event.subject_user_id}`;
		if (event.action === 'admin.recovered') return `Member #${event.subject_user_id} was restored as an active administrator.`;
		if (event.action === 'member.module_permissions_changed') {
			return `Member #${event.subject_user_id} · ${event.details?.module_key ?? 'Module'} capabilities updated`;
		}

		const before = event.details?.before;
		const after = event.details?.after;
		if (!before || !after) return `Member #${event.subject_user_id}`;

		const changes: string[] = [];
		if (before.is_admin !== after.is_admin) changes.push(after.is_admin ? 'administrator' : 'member');
		if (before.is_active !== after.is_active) changes.push(after.is_active ? 'active' : 'suspended');
		return `Member #${event.subject_user_id} · ${changes.join(', ')}`;
	}
</script>

<svelte:head>
	<title>{$t("Household members")} · Metricove</title>
	<meta name="description" content="Manage member access for your Metricove household." />
</svelte:head>

<section class="mx-auto w-full max-w-screen-xl">
	<div class="mx-auto max-w-5xl">
		<section class="mt-12 max-w-2xl">
			<p class="mb-4 text-sm font-medium tracking-[0.18em] text-slate-400 uppercase">Household access</p>
			<h1 class="text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">{$t("Manage members.")}</h1>
			<p class="mt-5 text-base leading-7 text-slate-300">Grant administrator access, suspend accounts or remove members. At least one active administrator must remain.</p>
		</section>

		{#if message}
			<p role="status" class="mt-8 rounded-lg border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{message}</p>
		{/if}
		{#if error}
			<p role="alert" class="mt-8 rounded-lg border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">{error}</p>
		{/if}

		<section class="mt-10 rounded-2xl border border-slate-800 bg-slate-900/70 p-5 shadow-2xl shadow-black/20 sm:p-8" aria-labelledby="members-heading">
			<div class="flex flex-wrap items-center justify-between gap-3">
				<div>
					<h2 id="members-heading" class="text-xl font-semibold">{$t("Household members")}</h2>
					<p class="mt-1 text-sm text-slate-400">{members.filter((member) => member.is_admin && member.is_active).length} active administrator(s)</p>
				</div>
				<button type="button" disabled={busy} onclick={() => void loadMembers()} class="rounded-lg border border-slate-700 px-3 py-2 text-sm font-medium text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:opacity-50">Refresh</button>
			</div>

			{#if loading}
				<p role="status" class="mt-6 text-sm text-slate-300">Loading members…</p>
			{:else if members.length === 0}
				<p class="mt-6 text-sm text-slate-400">There are no household members to show.</p>
			{:else}
				<ul class="mt-5 divide-y divide-slate-800">
					{#each members as member (member.id)}
						<li class="flex flex-col gap-5 py-5 lg:flex-row lg:items-center lg:justify-between">
							<div class="min-w-0">
								<p class="break-words text-base font-semibold text-slate-100">{member.name}{member.id === me?.id ? ' · You' : ''}</p>
								<p class="mt-1 break-all text-sm text-slate-400">{member.email}</p>
								<div class="mt-3 flex flex-wrap gap-2">
									<span class="rounded-full border px-2.5 py-1 text-xs font-medium {member.is_admin ? 'border-cyan-300/30 bg-cyan-300/10 text-cyan-200' : 'border-slate-700 text-slate-300'}">{member.is_admin ? 'Administrator' : 'Member'}</span>
									<span class="rounded-full border px-2.5 py-1 text-xs font-medium {member.is_active ? 'border-emerald-300/30 bg-emerald-300/10 text-emerald-200' : 'border-amber-300/30 bg-amber-300/10 text-amber-200'}">{member.is_active ? 'Active' : 'Suspended'}</span>
								</div>
							</div>
							<div class="flex flex-wrap gap-2 lg:justify-end">
								<a href={`/admin/members/${member.id}/modules`} class="rounded-lg border border-cyan-300/30 px-3 py-2 text-sm font-medium text-cyan-200 transition hover:border-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">Module rights</a>
								<button type="button" disabled={busy} onclick={() => void updateMember(member, { is_admin: !member.is_admin })} class="rounded-lg border border-slate-700 px-3 py-2 text-sm font-medium text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:opacity-50">{member.is_admin ? 'Demote' : 'Promote'}</button>
								<button type="button" disabled={busy} onclick={() => void updateMember(member, { is_active: !member.is_active })} class="rounded-lg border border-slate-700 px-3 py-2 text-sm font-medium text-slate-200 transition hover:border-amber-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-300 disabled:opacity-50">{member.is_active ? 'Suspend' : 'Reactivate'}</button>
								<button type="button" disabled={busy} onclick={() => void removeMember(member)} class="rounded-lg border border-rose-400/30 px-3 py-2 text-sm font-medium text-rose-200 transition hover:border-rose-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-300 disabled:opacity-50">Remove</button>
							</div>
						</li>
					{/each}
				</ul>
			{/if}
		</section>

		<section class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/70 p-5 shadow-2xl shadow-black/20 sm:p-8" aria-labelledby="audit-heading">
			<div>
				<h2 id="audit-heading" class="text-xl font-semibold">{$t("Recent access history")}</h2>
				<p class="mt-1 text-sm text-slate-400">The latest 100 role and account status changes.</p>
			</div>
			{#if loading}
				<p role="status" class="mt-5 text-sm text-slate-300">Loading access history…</p>
			{:else if events.length === 0}
				<p class="mt-5 text-sm text-slate-400">No member changes have been recorded.</p>
			{:else}
				<ol class="mt-5 divide-y divide-slate-800">
					{#each events as event (event.id)}
						<li class="flex flex-col gap-1 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
							<div class="min-w-0">
								<p class="text-sm font-medium text-slate-100">{eventLabel(event.action)}</p>
								<p class="mt-1 text-sm text-slate-400">{eventSummary(event)} · {event.actor_user_id === null ? 'System recovery' : `Administrator #${event.actor_user_id}`}</p>
							</div>
							<time datetime={event.created_at} class="shrink-0 text-xs text-slate-500">{formatDateTime(event.created_at)}</time>
						</li>
					{/each}
				</ol>
			{/if}
		</section>

		<footer class="mt-8 border-t border-slate-800 pt-5 text-sm text-slate-500"><a href="/profile" class="hover:text-slate-300">Return to your profile</a></footer>
	</div>
</section>
