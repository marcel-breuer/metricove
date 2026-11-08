<script lang="ts">
	import Button from '#lib/ui/Button.svelte';
	import ModuleIcon from '#lib/ui/ModuleIcon.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
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
	<div role="status" class="rounded-2xl border border-line glass-surface p-6 text-sm text-ink-secondary">{$t("Loading module access…")}</div>
{:else if module}
	<section class="mx-auto max-w-4xl">
		<PageHeading eyebrow="Your module" title={module.label} description={module.description} />
		<div class="mt-8 rounded-2xl border border-line glass-surface p-6 sm:p-8">
			<span class="grid size-11 place-items-center rounded-xl border border-line-strong text-accent-ink"><ModuleIcon name={module.key} /></span>
			<h2 class="mt-5 text-xl font-semibold text-ink">{$t("This module is ready for its data tools.")}</h2>
			<p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted">{$t("The module is enabled and you have access. Its records and entry forms are being delivered in the upcoming module work.")}</p>
			<p class="mt-5 text-xs text-ink-subtle">{$t("Available capabilities:")} {Object.entries(module.capabilities).filter(([, allowed]) => allowed).map(([capability]) => capability).join(', ')}</p>
		</div>
		<Button href="/" variant="secondary" class="mt-6">{$t("Back to overview")}</Button>
	</section>
{:else}
	<section class="mx-auto max-w-2xl rounded-2xl border border-line glass-surface p-6 sm:p-8">
		<p class="text-sm font-medium text-warning">{$t("Module unavailable")}</p>
		<h1 class="mt-2 text-2xl font-semibold tracking-tight text-ink">{$t("You do not have access to this module.")}</h1>
		<p class="mt-3 text-sm leading-6 text-ink-muted">{$t("An active household administrator can enable modules and grant member capabilities.")}</p>
		<Button href="/" class="mt-6">{$t("Return to overview")}</Button>
	</section>
{/if}
