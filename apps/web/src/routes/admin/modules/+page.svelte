<script lang="ts">
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';

	type ModuleEntry = {
		key: string;
		label: string;
		description: string;
		version: string;
		dependencies: string[];
		always_enabled: boolean;
		enabled: boolean;
		can_enable: boolean;
		can_disable: boolean;
		missing_dependencies: string[];
		active_dependents: string[];
	};

	type ModuleResponse = ApiResponse & {
		modules?: ModuleEntry[];
		module?: ModuleEntry;
		module_keys?: string[];
	};

	let modules: ModuleEntry[] = [];
	let loading = true;
	let busyKey = '';
	let message = '';
	let error = '';

	onMount(() => {
		void loadModules();
	});

	async function loadModules(): Promise<void> {
		try {
			const response = await fetch('/api/admin/modules', {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' }
			});
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (response.status === 403) throw new Error('Only an active household administrator can manage modules.');
			if (!response.ok) throw new Error('Module settings are unavailable. Check your connection and try again.');

			modules = ((await response.json()) as ModuleResponse).modules ?? [];
			error = '';
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Module settings are unavailable. Check your connection and try again.';
		} finally {
			loading = false;
		}
	}

	async function setEnabled(module: ModuleEntry): Promise<void> {
		const enabled = !module.enabled;
		if (!enabled && !window.confirm(`Disable ${module.label}? Existing module data will be kept.`)) return;

		message = '';
		error = '';
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		busyKey = module.key;
		try {
			const response = await fetch(`/api/admin/modules/${module.key}`, {
				method: 'PATCH',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
				body: JSON.stringify({ enabled })
			});
			const result = (await response.json()) as ModuleResponse;
			if (response.status === 401) {
				await goto('/login');
				return;
			}
			if (!response.ok) {
				const labels = (result.module_keys ?? []).map((key) => modules.find((entry) => entry.key === key)?.label ?? key);
				error = [firstError(result) || result.message || 'Module state could not be changed.', ...labels].join(' ');
				return;
			}

			message = result.message ?? 'Module settings updated.';
			await loadModules();
		} catch {
			error = 'Module state could not be changed. Check your connection and try again.';
		} finally {
			busyKey = '';
		}
	}
</script>

<svelte:head>
	<title>Modules · Metricove</title>
	<meta name="description" content="Enable optional Metricove modules for this household." />
</svelte:head>

<main class="min-h-screen bg-slate-950 px-5 py-8 text-slate-100 sm:px-10 sm:py-12">
	<div class="mx-auto max-w-5xl">
		<header class="flex flex-wrap items-center justify-between gap-4">
			<a href="/profile" class="flex items-center gap-3 text-sm font-semibold tracking-[0.18em] text-cyan-300 uppercase">
				<span class="grid size-9 place-items-center rounded-xl bg-cyan-300/10 text-lg">M</span>
				Metricove
			</a>
			<nav aria-label="Account administration" class="flex flex-wrap gap-2">
				<a href="/admin/members" class="rounded-lg border border-slate-700 px-3 py-2 text-sm font-medium text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">Members</a>
				<a href="/admin/invitations" class="rounded-lg border border-slate-700 px-3 py-2 text-sm font-medium text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">Invitations</a>
				<a href="/profile" class="rounded-lg border border-slate-700 px-3 py-2 text-sm font-medium text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">Your profile</a>
			</nav>
		</header>

		<section class="mt-12 max-w-2xl">
			<p class="mb-4 text-sm font-medium tracking-[0.18em] text-slate-400 uppercase">Household settings</p>
			<h1 class="text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">Choose your modules.</h1>
			<p class="mt-5 text-base leading-7 text-slate-300">Optional modules are enabled for the whole household. You can turn them off later without deleting their saved data.</p>
		</section>

		{#if message}
			<p role="status" class="mt-8 rounded-lg border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{message}</p>
		{/if}
		{#if error}
			<p role="alert" class="mt-8 rounded-lg border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">{error}</p>
		{/if}

		<section class="mt-10" aria-labelledby="module-list-heading">
			<div class="flex flex-wrap items-end justify-between gap-3">
				<div>
					<h2 id="module-list-heading" class="text-xl font-semibold">Available modules</h2>
					<p class="mt-1 text-sm text-slate-400">{modules.filter((module) => module.enabled && !module.always_enabled).length} optional module(s) enabled</p>
				</div>
				<button type="button" disabled={busyKey !== ''} onclick={() => void loadModules()} class="rounded-lg border border-slate-700 px-3 py-2 text-sm font-medium text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:opacity-50">Refresh</button>
			</div>

			{#if loading}
				<p role="status" class="mt-6 text-sm text-slate-300">Loading modules…</p>
			{:else if modules.length === 0}
				<p class="mt-6 rounded-xl border border-slate-800 bg-slate-900/70 p-5 text-sm text-slate-400">No modules are registered.</p>
			{:else}
				<ul class="mt-5 grid gap-4 md:grid-cols-2">
					{#each modules as module (module.key)}
						<li class="flex min-h-52 flex-col justify-between rounded-2xl border border-slate-800 bg-slate-900/70 p-5 shadow-xl shadow-black/10 sm:p-6">
							<div>
							<div class="flex flex-wrap items-center justify-between gap-3">
								<h3 class="text-lg font-semibold">{module.label}</h3>
								<span class="rounded-full border px-2.5 py-1 text-xs font-medium {module.enabled ? 'border-emerald-300/30 bg-emerald-300/10 text-emerald-200' : 'border-slate-700 bg-slate-950/60 text-slate-300'}">{module.always_enabled ? 'Always on' : module.enabled ? 'Enabled' : 'Disabled'}</span>
							</div>
							<p class="mt-3 text-sm leading-6 text-slate-300">{module.description}</p>
							{#if module.dependencies.length > 0}
								<p class="mt-3 text-xs text-slate-500">Requires: {module.dependencies.map((key) => modules.find((entry) => entry.key === key)?.label ?? key).join(', ')}</p>
							{/if}
							{#if module.missing_dependencies.length > 0}
								<p class="mt-3 text-xs text-amber-200">Enable first: {module.missing_dependencies.map((key) => modules.find((entry) => entry.key === key)?.label ?? key).join(', ')}</p>
							{/if}
							{#if module.active_dependents.length > 0}
								<p class="mt-3 text-xs text-amber-200">Used by enabled modules: {module.active_dependents.map((key) => modules.find((entry) => entry.key === key)?.label ?? key).join(', ')}</p>
							{/if}
							</div>

							<div class="mt-6 flex items-center justify-between gap-3 border-t border-slate-800 pt-4">
								<p class="text-xs text-slate-500">Version {module.version}</p>
								{#if module.always_enabled}
									<button type="button" disabled aria-label="Core is always enabled" class="cursor-not-allowed rounded-lg border border-slate-700 px-3 py-2 text-sm font-semibold text-slate-500">Always on</button>
								{:else}
									<button
										type="button"
										disabled={busyKey !== '' || (module.enabled ? !module.can_disable : !module.can_enable)}
										aria-label={`${module.enabled ? 'Disable' : 'Enable'} ${module.label}`}
										onclick={() => void setEnabled(module)}
										class="rounded-lg px-3 py-2 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:cursor-not-allowed disabled:opacity-50 {module.enabled ? 'border border-amber-300/30 text-amber-200 hover:border-amber-200' : 'bg-cyan-300 text-slate-950 hover:bg-cyan-200'}"
									>
										{busyKey === module.key ? 'Saving…' : module.enabled ? 'Disable' : 'Enable'}
									</button>
								{/if}
							</div>
						</li>
					{/each}
				</ul>
			{/if}
		</section>

		<footer class="mt-8 border-t border-slate-800 pt-5 text-sm text-slate-500"><a href="/profile" class="hover:text-slate-300">Return to your profile</a></footer>
	</div>
</main>
