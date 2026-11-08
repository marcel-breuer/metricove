<script lang="ts">
	import Button from '#lib/ui/Button.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import { getContext } from 'svelte';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { t } from '#lib/i18n';
	import ModuleIcon from '#lib/ui/ModuleIcon.svelte';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const analyticsAvailable = $derived(shell.modules.some((module) => module.key === 'analytics' && module.capabilities.view));
</script>

<svelte:head>
	<title>{$t('Overview')} {$t("· Metricove")}</title>
	<meta name="description" content={$t('Your household modules and everyday overview.')} />
</svelte:head>

<section class="mx-auto max-w-screen-xl">
	<PageHeading eyebrow="Your household, at a glance" title="A clearer view of everyday life" description="Private by design. Your enabled modules appear here when their records and dashboards are ready." />

	{#if shell.loading}
		<div role="status" aria-live="polite" class="mx-auto flex min-h-72 max-w-2xl flex-col items-center justify-center py-12 text-center">
			<span class="size-8 animate-spin rounded-full border-2 border-line-strong border-t-accent-ink" aria-hidden="true"></span>
			<p class="mt-4 text-sm text-ink-secondary">{$t('Loading your household…')}</p>
		</div>
	{:else if shell.modules.length === 0}
		<div class="mx-auto flex min-h-80 max-w-2xl flex-col items-center justify-center py-12 text-center">
			<div class="grid size-16 place-items-center rounded-2xl border border-line-strong glass-surface text-2xl text-ink-muted" aria-hidden="true"><ModuleIcon name="overview" class="size-6" /></div>
			<h2 class="mt-5 text-2xl font-semibold tracking-tight text-ink">{$t('Your workspace is ready.')}</h2>
			<p class="mt-3 max-w-lg text-sm leading-6 text-ink-muted">{$t('No modules are available to your account yet. An administrator can enable household modules and grant each member their own capabilities.')}</p>
			{#if shell.currentUser?.is_admin}
			<Button href="/admin/modules" class="mt-6">{$t('Manage modules')}</Button>
			{/if}
		</div>
	{:else}
		<div class="grid min-h-72 place-items-center py-10">
			<div class="flex max-w-xl flex-col items-center text-center">
				<div class="grid size-16 place-items-center rounded-2xl border border-line-strong glass-surface text-2xl text-ink-muted" aria-hidden="true"><ModuleIcon name="analytics" class="size-6" /></div>
				<h2 class="mt-5 text-2xl font-semibold tracking-tight text-ink">{$t('Your dashboard is taking shape.')}</h2>
				<p class="mt-3 text-sm leading-6 text-ink-muted">{$t('Your modules are enabled and ready. Open Analytics to configure charts from the data you can access.')}</p>
				{#if analyticsAvailable}<Button href="/modules/analytics" class="mt-5">{$t('Open Analytics')}</Button>{/if}
			</div>
		</div>

		<section class="border-t border-line pt-7" aria-labelledby="your-modules-heading">
			<div class="flex flex-wrap items-end justify-between gap-3">
				<div><h2 id="your-modules-heading" class="text-xl font-semibold text-ink">{$t('Your modules')}</h2><p class="mt-1 text-sm text-ink-muted">{shell.modules.length} {$t(shell.modules.length === 1 ? 'module available to you' : 'modules available to you')}</p></div>
				{#if shell.currentUser?.is_admin}<Button href="/admin/modules" variant="secondary" size="small">{$t('Manage modules')}</Button>{/if}
			</div>
			<ul class="mt-5 divide-y divide-line rounded-2xl border border-line glass-surface">
				{#each shell.modules as module (module.key)}
					<li>
						<a href={`/modules/${module.key}`} class="group flex min-h-20 items-center gap-4 px-4 py-4 transition hover:bg-surface focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-accent-ink sm:px-6">
							<span class="grid size-11 shrink-0 place-items-center rounded-xl border border-line-strong bg-page text-accent-ink"><ModuleIcon name={module.key} class="size-5" /></span>
							<span class="min-w-0 flex-1"><span class="block font-semibold text-ink">{$t(module.label)}</span><span class="mt-1 block text-sm leading-5 text-ink-muted">{$t(module.description)}</span></span>
							<span class="hidden shrink-0 text-xs font-medium text-ink-subtle sm:inline">{$t(module.capabilities.view ? 'View access' : module.capabilities.create ? 'Create access' : module.capabilities.edit ? 'Edit access' : 'Manage access')}</span>
							<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5 shrink-0 text-ink-subtle transition-colors group-hover:text-accent-ink"><path d="m9 5 7 7-7 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
						</a>
					</li>
				{/each}
			</ul>
		</section>
	{/if}
</section>
