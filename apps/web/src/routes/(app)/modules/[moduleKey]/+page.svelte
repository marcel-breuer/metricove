<script lang="ts">
	import { t } from '#lib/i18n';
	import { page } from '$app/state';
	import { getContext } from 'svelte';
	import type { AppShellContext } from '#lib/app-shell';
	import { APP_SHELL_CONTEXT } from '#lib/app-shell';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const moduleKey = $derived(page.params.moduleKey);
	const module = $derived(shell.modules.find((entry) => entry.key === moduleKey) ?? null);
</script>

<svelte:head>
	<title>{module ? `${$t(module.label)} · Metricove` : $t('Module · Metricove')}</title>
	<meta name="description" content={$t(module?.description ?? 'Available household modules.')} />
</svelte:head>

{#if shell.loading}
	<div role="status" class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 text-sm text-slate-300">{$t("Loading module access…")}</div>
{:else if module}
	<section class="mx-auto max-w-4xl">
		<p class="text-sm font-medium text-cyan-200">{$t("Your module")}</p>
		<h1 class="mt-2 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{$t(module.label)}</h1>
		<p class="mt-3 max-w-2xl text-base leading-7 text-slate-300">{$t(module.description)}</p>
		<div class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/60 p-6 sm:p-8">
			<div class="grid size-12 place-items-center rounded-xl bg-cyan-300/10 text-2xl text-cyan-200" aria-hidden="true">◇</div>
			<h2 class="mt-5 text-xl font-semibold text-slate-100">{$t("This module is ready for its data tools.")}</h2>
			<p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">{$t("The module is enabled and you have access. Its records and entry forms are being delivered in the upcoming module work.")}</p>
			<p class="mt-5 text-xs text-slate-500">{$t("Available capabilities:")} {Object.entries(module.capabilities).filter(([, allowed]) => allowed).map(([capability]) => capability).join(', ')}</p>
		</div>
		<a href="/" class="mt-6 inline-flex rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{$t("Back to overview")}</a>
	</section>
{:else}
	<section class="mx-auto max-w-2xl rounded-2xl border border-slate-800 bg-slate-900/60 p-6 sm:p-8">
		<p class="text-sm font-medium text-amber-200">{$t("Module unavailable")}</p>
		<h1 class="mt-2 text-2xl font-semibold tracking-tight text-white">{$t("You do not have access to this module.")}</h1>
		<p class="mt-3 text-sm leading-6 text-slate-400">{$t("An active household administrator can enable modules and grant member capabilities.")}</p>
		<a href="/" class="mt-6 inline-flex rounded-xl bg-cyan-300 px-4 py-2.5 text-sm font-semibold text-slate-950 hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{$t("Return to overview")}</a>
	</section>
{/if}
