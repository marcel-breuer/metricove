<script lang="ts">
	import { t } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import Button from '#lib/ui/Button.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import Panel from '#lib/ui/Panel.svelte';
	import Switch from '#lib/ui/Switch.svelte';
	import { getContext } from 'svelte';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);

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
	let pendingDisable = $state<ModuleEntry | null>(null);
	let disableOpen = $state(false);
	let disableAllOpen = $state(false);

	function requiredModuleLabels(module: ModuleEntry): string[] {
		return module.dependencies
			.filter((key) => !modules.find((entry) => entry.key === key)?.always_enabled)
			.map((key) => modules.find((entry) => entry.key === key)?.label ?? key);
	}

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

	async function setEnabled(module: ModuleEntry, confirmed = false): Promise<void> {
		const enabled = !module.enabled;
		if (!enabled && !confirmed) { pendingDisable = module; disableOpen = true; return; }

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
			await shell.refresh();
		} catch {
			error = 'Module state could not be changed. Check your connection and try again.';
		} finally {
			busyKey = '';
		}
	}

	function modulesInDependencyOrder(entries: ModuleEntry[]): ModuleEntry[] {
		const ordered: ModuleEntry[] = [];
		const visited = new Set<string>();
		const byKey = new Map(entries.map((module) => [module.key, module]));

		function visit(module: ModuleEntry): void {
			if (visited.has(module.key)) return;
			visited.add(module.key);
			for (const dependency of module.dependencies) {
				const required = byKey.get(dependency);
				if (required && !required.always_enabled) visit(required);
			}
			ordered.push(module);
		}

		for (const module of entries) {
			if (!module.always_enabled) visit(module);
		}
		return ordered;
	}

	async function toggleAllModules(confirmed = false): Promise<void> {
		const optionalModules = modules.filter((module) => !module.always_enabled);
		if (optionalModules.length === 0) return;

		const enabled = optionalModules.some((module) => !module.enabled);
		if (!enabled && !confirmed) { disableAllOpen = true; return; }

		message = '';
		error = '';
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		busyKey = 'all';
		let failure = '';
		try {
			const orderedModules = modulesInDependencyOrder(optionalModules);
			const pendingModules = orderedModules.filter((module) => module.enabled !== enabled);
			if (!enabled) pendingModules.reverse();

			for (const module of pendingModules) {
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
					failure = [firstError(result) || result.message || `${$t(module.label)} could not be ${enabled ? 'enabled' : 'disabled'}.`, ...labels].join(' ');
					break;
				}
			}

			await loadModules();
			await shell.refresh();
			if (failure) error = failure;
			else message = enabled ? 'All optional modules are enabled.' : 'All optional modules are disabled.';
		} catch {
			failure = 'Module settings could not be changed. Check your connection and try again.';
			await loadModules();
			await shell.refresh();
			error = failure;
		} finally {
			busyKey = '';
		}
	}
</script>

<svelte:head>
	<title>{$t("Modules")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Enable optional Metricove modules for this household.")} />
</svelte:head>

<section class="mx-auto w-full max-w-screen-xl">
	<div class="mx-auto max-w-5xl">
		<PageHeading class="max-w-3xl" eyebrow={$t('Household settings')} title={$t('Manage modules.')} description={$t('Optional modules start disabled. Enabling one gives every active member all four module capabilities; you can narrow access per member afterward. Disabling a module preserves its data.')} />

		{#if message}
			<Notice class="mt-8" tone="success" message={$t(message)} />
		{/if}
		{#if error}
			<Notice class="mt-8" tone="error" message={$t(error)} />
		{/if}

		<section class="mt-10" aria-labelledby="module-list-heading">
			<div class="flex flex-wrap items-end justify-between gap-3">
				<div>
					<h2 id="module-list-heading" class="text-xl font-semibold">{$t("Available modules")}</h2>
					<p class="mt-1 text-sm text-slate-400">{modules.filter((module) => module.enabled && !module.always_enabled).length} {$t("optional module(s) enabled")}</p>
				</div>
				<div class="flex flex-wrap gap-2">
					{#if modules.some((module) => !module.always_enabled)}
						{@const allEnabled = modules.filter((module) => !module.always_enabled).every((module) => module.enabled)}
						<Button variant={allEnabled ? 'danger' : 'secondary'} size="small" disabled={busyKey !== ''} onclick={() => void toggleAllModules()}>
							{busyKey === 'all' ? 'Updating modules…' : allEnabled ? 'Disable all modules' : 'Enable all modules'}
						</Button>
					{/if}
					<Button variant="secondary" size="small" disabled={busyKey !== ''} onclick={() => void loadModules()}>{$t("Refresh")}</Button>
				</div>
			</div>

			{#if loading}
				<p role="status" class="mt-6 text-sm text-slate-300">{$t("Loading modules…")}</p>
			{:else if modules.length === 0}
				<p class="mt-6 rounded-xl border border-slate-800 bg-slate-900/70 p-5 text-sm text-slate-400">{$t("No modules are registered.")}</p>
			{:else}
					<Panel padding="none" class="mt-5 overflow-hidden">
					<ul class="divide-y divide-slate-800 px-4 sm:px-6">
					{#each modules as module (module.key)}
							<li class="grid gap-4 py-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-8">
								<div class="min-w-0">
									<div class="flex flex-wrap items-center gap-3">
										<h3 class="text-base font-semibold text-slate-100">{$t(module.label)}</h3>
									</div>
									<p class="mt-1.5 text-sm leading-6 text-slate-400">{$t(module.description)}</p>
								{#if requiredModuleLabels(module).length > 0}
									<p class="mt-2 text-xs text-slate-500">{$t("Requires:")} {requiredModuleLabels(module).join(', ')}</p>
							{/if}
							{#if module.missing_dependencies.length > 0}
									<p class="mt-2 text-xs text-amber-200">{$t("Enable first:")} {module.missing_dependencies.map((key) => modules.find((entry) => entry.key === key)?.label ?? key).join(', ')}</p>
							{/if}
							{#if module.active_dependents.length > 0}
									<p class="mt-2 text-xs text-amber-200">{$t("Used by enabled modules:")} {module.active_dependents.map((key) => modules.find((entry) => entry.key === key)?.label ?? key).join(', ')}</p>
							{/if}
								</div>

								<div class="flex items-center justify-between gap-3 sm:justify-end">
								{#if module.always_enabled}
										<span class="text-sm font-medium text-cyan-200">{$t("Always on")}</span>
								{:else}
									<Switch
										checked={module.enabled}
										label={$t("{module} module", { module: $t(module.label) })}
										disabled={busyKey !== '' || (module.enabled ? !module.can_disable : !module.can_enable)}
										pending={busyKey === module.key}
										onchange={() => void setEnabled(module)}
									/>
								{/if}
							</div>
						</li>
					{/each}
				</ul>
					</Panel>
			{/if}
		</section>

	</div>
</section>
{#if pendingDisable}<ConfirmModal bind:open={disableOpen} title={$t('Disable module?')} message={$t('Disable ') + $t(pendingDisable.label) + $t('? Existing data and member rights will be kept.')} confirmLabel={$t('Disable')} onConfirm={() => void setEnabled(pendingDisable!, true)} />{/if}
<ConfirmModal bind:open={disableAllOpen} title={$t('Disable all optional modules?')} message={$t('Existing data and member rights will be kept. If modules are enabled again, every active member will receive all module rights.')} confirmLabel={$t('Disable all')} onConfirm={() => void toggleAllModules(true)} />
