<script lang="ts">
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { getContext } from 'svelte';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);

	type Capability = 'view' | 'create' | 'edit' | 'manage';
	type MemberModule = {
		key: string;
		label: string;
		description: string;
		enabled: boolean;
		capabilities: Record<Capability, boolean>;
	};
	type PermissionResponse = ApiResponse & {
		member?: { id: number; name: string };
		modules?: MemberModule[];
		module?: MemberModule;
	};

	const capabilities: { key: Capability; label: string; description: string }[] = [
		{ key: 'view', label: 'View', description: 'Read module records the member may access.' },
		{ key: 'create', label: 'Create', description: 'Add new module records.' },
		{ key: 'edit', label: 'Edit', description: 'Change permitted module records.' },
		{ key: 'manage', label: 'Manage', description: 'Manage module-level settings and explicit record shares.' }
	];

	const memberId = $derived(page.params.memberId);
	let member = $state<{ id: number; name: string } | null>(null);
	let modules = $state<MemberModule[]>([]);
	let loading = $state(true);
	let busyKey = $state('');
	let message = $state('');
	let error = $state('');

	onMount(() => {
		void loadPermissions();
	});

	async function loadPermissions(): Promise<void> {
		try {
			const response = await fetch(`/api/admin/members/${memberId}/module-permissions`, {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' }
			});
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (response.status === 403) throw new Error('Only an active household administrator can manage module rights.');
			if (!response.ok) throw new Error('Module rights are unavailable. Check your connection and try again.');

			const result = (await response.json()) as PermissionResponse;
			member = result.member ?? null;
			modules = result.modules ?? [];
			error = '';
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Module rights are unavailable. Check your connection and try again.';
		} finally {
			loading = false;
		}
	}

	async function updateCapability(module: MemberModule, capability: Capability, event: Event): Promise<void> {
		const input = event.currentTarget;
		if (!(input instanceof HTMLInputElement) || member === null) return;

		message = '';
		error = '';
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		const nextCapabilities = { ...module.capabilities, [capability]: input.checked };
		busyKey = module.key;
		try {
			const response = await fetch(`/api/admin/members/${member.id}/module-permissions/${module.key}`, {
				method: 'PUT',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ capabilities: nextCapabilities })
			});
			const result = (await response.json()) as PermissionResponse;
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (!response.ok || !result.module) {
				error = firstError(result) || result.message || 'Module rights could not be updated.';
				input.checked = module.capabilities[capability];
				return;
			}

			modules = modules.map((entry) => entry.key === module.key ? result.module! : entry);
			message = result.message ?? 'Module rights updated.';
			await shell.refresh();
		} catch {
			input.checked = module.capabilities[capability];
			error = 'Module rights could not be updated. Check your connection and try again.';
		} finally {
			busyKey = '';
		}
	}
</script>

<svelte:head>
	<title>Module rights · Metricove</title>
	<meta name="description" content="Manage module capabilities for a household member." />
</svelte:head>

<section class="mx-auto w-full max-w-screen-xl">
	<div class="mx-auto max-w-5xl">
		<section class="mt-12 max-w-2xl">
			<p class="mb-4 text-sm font-medium tracking-[0.18em] text-slate-400 uppercase">Member access</p>
			<h1 class="text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">Module rights.</h1>
			<p class="mt-5 text-base leading-7 text-slate-300">{member ? `Choose the capabilities available to ${member.name}.` : 'Choose the capabilities available to this household member.'} Administrators need explicit module rights too; administrator status does not grant access to private records.</p>
		</section>

		{#if message}
			<p role="status" class="mt-8 rounded-lg border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{message}</p>
		{/if}
		{#if error}
			<p role="alert" class="mt-8 rounded-lg border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">{error}</p>
		{/if}

		<section class="mt-10" aria-labelledby="module-rights-heading">
			<div class="flex flex-wrap items-end justify-between gap-3">
				<div>
					<h2 id="module-rights-heading" class="text-xl font-semibold">Optional module capabilities</h2>
					<p class="mt-1 text-sm text-slate-400">Module rights are stored while a module is disabled and take effect only while it is enabled.</p>
				</div>
				<button type="button" disabled={busyKey !== ''} onclick={() => void loadPermissions()} class="rounded-lg border border-slate-700 px-3 py-2 text-sm font-medium text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:opacity-50">Refresh</button>
			</div>

			{#if loading}
				<p role="status" class="mt-6 text-sm text-slate-300">Loading module rights…</p>
			{:else if modules.length === 0}
				<p class="mt-6 rounded-xl border border-slate-800 bg-slate-900/70 p-5 text-sm text-slate-400">No optional modules are registered.</p>
			{:else}
				<ul class="mt-5 grid gap-4 md:grid-cols-2">
					{#each modules as module (module.key)}
						<li class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5 shadow-xl shadow-black/10 sm:p-6">
							<div class="flex flex-wrap items-start justify-between gap-3">
								<div>
									<h3 class="text-lg font-semibold">{module.label}</h3>
									<p class="mt-2 text-sm leading-6 text-slate-300">{module.description}</p>
								</div>
								<span class="rounded-full border px-2.5 py-1 text-xs font-medium {module.enabled ? 'border-emerald-300/30 bg-emerald-300/10 text-emerald-200' : 'border-slate-700 bg-slate-950/60 text-slate-300'}">{module.enabled ? 'Enabled' : 'Disabled'}</span>
							</div>
							{#if !module.enabled}
								<p class="mt-3 text-xs text-slate-500">Capabilities remain saved and apply if an administrator enables this module.</p>
							{/if}
							<fieldset class="mt-5 grid gap-3 border-t border-slate-800 pt-4 sm:grid-cols-2">
								<legend class="sr-only">{module.label} capabilities</legend>
								{#each capabilities as capability (capability.key)}
									<label class="flex min-w-0 items-start gap-3 rounded-xl border border-slate-800 px-3 py-3">
										<input
											type="checkbox"
											checked={module.capabilities[capability.key]}
											disabled={busyKey !== ''}
											aria-label={`${capability.label} permission for ${module.label}`}
											onchange={(event) => void updateCapability(module, capability.key, event)}
											class="mt-0.5 size-4 shrink-0 accent-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300"
										/>
										<span class="min-w-0">
											<span class="block text-sm font-medium text-slate-100">{capability.label}</span>
											<span class="mt-1 block text-xs leading-5 text-slate-400">{capability.description}</span>
										</span>
									</label>
								{/each}
							</fieldset>
						</li>
					{/each}
				</ul>
			{/if}
		</section>

		<footer class="mt-8 border-t border-slate-800 pt-5 text-sm text-slate-500"><a href="/admin/members" class="hover:text-slate-300">Return to household members</a></footer>
	</div>
</section>
