<script lang="ts">
	import { getContext } from 'svelte';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
</script>

<svelte:head>
	<title>Overview · Metricove</title>
	<meta name="description" content="Your household modules and everyday overview." />
</svelte:head>

<section class="mx-auto max-w-screen-xl">
	<div class="flex flex-wrap items-end justify-between gap-4 border-b border-slate-800 pb-6">
		<div>
			<p class="text-sm font-medium tracking-wide text-cyan-200">Your household, at a glance</p>
			<h1 class="mt-2 text-3xl font-semibold leading-tight tracking-tight text-white sm:text-4xl">A clearer view of everyday life.</h1>
		</div>
		<p class="max-w-md text-sm leading-6 text-slate-400">Private by design. Your enabled modules appear here when their records and dashboards are ready.</p>
	</div>

	{#if shell.loading}
		<div role="status" aria-live="polite" class="mx-auto flex min-h-72 max-w-2xl flex-col items-center justify-center py-12 text-center">
			<span class="size-8 animate-spin rounded-full border-2 border-slate-700 border-t-cyan-300" aria-hidden="true"></span>
			<p class="mt-4 text-sm text-slate-300">Loading your household…</p>
		</div>
	{:else if shell.modules.length === 0}
		<div class="mx-auto flex min-h-80 max-w-2xl flex-col items-center justify-center py-12 text-center">
			<div class="grid size-16 place-items-center rounded-2xl border border-slate-700 bg-slate-900 text-2xl text-slate-400" aria-hidden="true">⌂</div>
			<h2 class="mt-5 text-2xl font-semibold tracking-tight text-white">Your workspace is ready.</h2>
			<p class="mt-3 max-w-lg text-sm leading-6 text-slate-400">No modules are available to your account yet. An administrator can enable household modules and grant each member their own capabilities.</p>
			{#if shell.currentUser?.is_admin}
				<a href="/admin/modules" class="mt-6 inline-flex rounded-xl bg-cyan-300 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">Manage modules</a>
			{/if}
		</div>
	{:else}
		<div class="grid min-h-72 place-items-center py-10">
			<div class="flex max-w-xl flex-col items-center text-center">
				<div class="grid size-16 place-items-center rounded-2xl border border-slate-700 bg-slate-900 text-2xl text-slate-400" aria-hidden="true">▤</div>
				<h2 class="mt-5 text-2xl font-semibold tracking-tight text-white">Your dashboard is taking shape.</h2>
				<p class="mt-3 text-sm leading-6 text-slate-400">Your modules are enabled and ready. Their dashboards and data summaries will appear here as those features are delivered.</p>
			</div>
		</div>

		<section class="border-t border-slate-800 pt-7" aria-labelledby="your-modules-heading">
			<div class="flex flex-wrap items-end justify-between gap-3">
				<div><h2 id="your-modules-heading" class="text-xl font-semibold text-white">Your modules</h2><p class="mt-1 text-sm text-slate-400">{shell.modules.length} module{shell.modules.length === 1 ? '' : 's'} available to you</p></div>
				{#if shell.currentUser?.is_admin}<a href="/admin/modules" class="rounded-lg border border-slate-700 px-3 py-2 text-sm font-medium text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">Manage modules</a>{/if}
			</div>
			<ul class="mt-5 divide-y divide-slate-800 rounded-2xl border border-slate-800 bg-slate-900/40">
				{#each shell.modules as module (module.key)}
					<li>
						<a href={`/modules/${module.key}`} class="group flex min-h-20 items-center gap-4 px-4 py-4 transition hover:bg-slate-900 focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-cyan-300 sm:px-6">
							<span class="grid size-11 shrink-0 place-items-center rounded-xl border border-slate-700 bg-slate-950 text-lg text-cyan-200" aria-hidden="true">◇</span>
							<span class="min-w-0 flex-1"><span class="block font-semibold text-slate-100">{module.label}</span><span class="mt-1 block text-sm leading-5 text-slate-400">{module.description}</span></span>
							<span class="shrink-0 text-xs font-medium text-slate-500">{module.capabilities.view ? 'View access' : module.capabilities.create ? 'Create access' : module.capabilities.edit ? 'Edit access' : 'Manage access'}</span>
							<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5 shrink-0 text-slate-500 transition group-hover:translate-x-0.5 group-hover:text-cyan-200"><path d="m9 5 7 7-7 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
						</a>
					</li>
				{/each}
			</ul>
		</section>
	{/if}
</section>
