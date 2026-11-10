<script lang="ts">
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import { formatDate, t } from '#lib/i18n';
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import Modal from '#lib/ui/Modal.svelte';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';
	import { formatMeasurement, formatNumber } from '#lib/number-format';
	import {
		analyticsRanges,
		type AnalyticsChartType,
		type AnalyticsMetric,
		type AnalyticsRange,
		type AnalyticsResponse,
		type AnalyticsWidget
	} from '#lib/analytics';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'analytics'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreate = $derived(access?.capabilities.create ?? false);
	const canEdit = $derived(access?.capabilities.edit ?? false);

	let catalog = $state<AnalyticsMetric[]>([]);
	let widgets = $state<AnalyticsWidget[]>([]);
	let loading = $state(true);
	let saving = $state(false);
	let error = $state('');
	let message = $state('');
	let metricKey = $state('');
	let title = $state('');
	let rangeKey = $state<AnalyticsRange>('30d');
	let chartType = $state<AnalyticsChartType>('line');
	let editingId = $state<number | null>(null);
	let createOpen = $state(false);
	let editOpen = $state(false);
	let widgetToRemove = $state<AnalyticsWidget | null>(null);
	let removeOpen = $state(false);
	let editTitle = $state('');
	let editRange = $state<AnalyticsRange>('30d');
	let editChart = $state<AnalyticsChartType>('line');

	$effect(() => {
		if (!shell.loading && canView) void loadDashboard();
		else if (!shell.loading) loading = false;
	});

	async function api<T extends AnalyticsResponse>(path: string, method = 'GET', body?: object): Promise<T> {
		const headers: Record<string, string> = { Accept: 'application/json' };
		if (method !== 'GET') {
			const token = csrfToken();
			if (!token) throw new Error('Reload this page and try again.');
			headers['X-XSRF-TOKEN'] = token;
			headers['Content-Type'] = 'application/json';
		}
		const response = await fetch(path, {
			method,
			credentials: 'same-origin',
			headers,
			...(body ? { body: JSON.stringify(body) } : {})
		});
		if (response.status === 401) {
			await goto('/login');
			throw new Error('Your session has expired.');
		}
		const result = (await response.json()) as T & ApiResponse;
		if (!response.ok) throw new Error(firstError(result) || result.message || 'Analytics could not complete that request.');
		return result as T;
	}

	async function loadDashboard(): Promise<void> {
		loading = true;
		error = '';
		try {
			const result = await api<AnalyticsResponse>('/api/analytics/dashboard');
			catalog = result.catalog ?? [];
			widgets = result.widgets ?? [];
			if (!catalog.some((metric) => metric.key === metricKey)) metricKey = catalog[0]?.key ?? '';
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Analytics could not be loaded.';
		} finally {
			loading = false;
		}
	}

	async function createWidget(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!metricKey || saving) return;
		saving = true;
		error = '';
		message = '';
		try {
			await api<AnalyticsResponse>('/api/analytics/widgets', 'POST', {
				metric_key: metricKey,
				title: title.trim() || undefined,
				range_key: rangeKey,
				chart_type: chartType
			});
			title = '';
			message = 'Widget added to your dashboard.';
			createOpen = false;
			await loadDashboard();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The widget could not be added.';
		} finally {
			saving = false;
		}
	}

	function beginEdit(widget: AnalyticsWidget): void {
		editOpen = true;
		editingId = widget.id;
		editTitle = widget.title;
		editRange = widget.range_key;
		editChart = widget.chart_type;
	}

	async function saveWidget(widget: AnalyticsWidget): Promise<void> {
		if (saving || editingId !== widget.id) return;
		saving = true;
		error = '';
		message = '';
		try {
			await api<AnalyticsResponse>(`/api/analytics/widgets/${widget.id}`, 'PATCH', {
				expected_version: widget.version,
				title: editTitle.trim(),
				range_key: editRange,
				chart_type: editChart
			});
			editingId = null;
			editOpen = false;
			message = 'Widget settings saved.';
			await loadDashboard();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The widget could not be updated.';
		} finally {
			saving = false;
		}
	}

	async function removeWidget(widget: AnalyticsWidget): Promise<void> {
		if (saving) return;
		saving = true;
		error = '';
		message = '';
		try {
			await api<AnalyticsResponse>(`/api/analytics/widgets/${widget.id}`, 'DELETE');
			if (editingId === widget.id) editingId = null;
			message = 'Widget removed.';
			widgetToRemove = null;
			removeOpen = false;
			await loadDashboard();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The widget could not be removed.';
		} finally {
			saving = false;
		}
	}

	function periodLabel(value: string, range: AnalyticsRange): string {
		if (range === '12m') return formatDate(new Date(`${value}-01T00:00:00Z`), { month: 'short', year: '2-digit', timeZone: 'UTC' });
		const date = new Date(`${value}T00:00:00Z`);
		return formatDate(date, { month: 'short', day: 'numeric', timeZone: 'UTC' });
	}

	function displayedValue(value: string, currency: boolean): string {
		return currency
			? formatNumber(value, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
			: formatMeasurement(value);
	}
</script>

<svelte:head>
	<title>{$t("Analytics")} {$t("· Metricove")}</title>
	<meta name="description" content={$t("Build private charts from the household data you can access.")} />
</svelte:head>

<section class="mx-auto max-w-screen-xl">
	<PageHeading eyebrow="Your data, at a glance" title="Analytics dashboard" description="Choose charts from modules you can view. Currency and measurement units stay in separate series; source records remain private." />

	{#if error}<Notice tone="error" message={error} class="mt-5" />{/if}
	{#if message}<Notice tone="success" message={message} class="mt-5" />{/if}

	{#if canCreate}
		<div class="mt-6 flex justify-end"><button type="button" onclick={() => (createOpen = true)} class="min-h-11 rounded-xl bg-accent px-5 text-sm font-semibold text-on-accent">{$t('Add a chart')}</button></div>
		<Modal bind:open={createOpen} title={$t('Add a chart')} description={$t('Available charts follow your current module access.')}>
		<section class="rounded-2xl border glass-surface p-4 sm:p-6" aria-labelledby="add-widget-heading">
			<div class="flex flex-wrap items-start justify-between gap-3">
				<div><h2 id="add-widget-heading" class="text-lg font-semibold text-ink">{$t("Add a chart")}</h2><p class="mt-1 text-sm text-ink-muted">{$t("Available charts follow your current module access.")}</p></div>
				<span class="rounded-full border border-line-strong px-3 py-1 text-xs font-medium text-ink-muted">{$t('{count} / 12 widgets', { count: widgets.length })}</span>
			</div>
			{#if catalog.length === 0}
				<p class="mt-5 rounded-xl border border-line bg-page p-4 text-sm leading-6 text-ink-muted">{$t("No data sources are available yet. Ask an administrator to enable a source module and grant you view access.")}</p>
			{:else}
				<form class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-[1.5fr_1.2fr_1fr_1fr_auto]" onsubmit={createWidget}>
					<label class="block text-xs font-medium text-ink-secondary">{$t("Data source")}
						<select bind:value={metricKey} class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base">
							{#each catalog as metric (metric.key)}<option value={metric.key}>{$t(metric.label)} · {$t(metric.source_label)}</option>{/each}
						</select>
					</label>
					<label class="block text-xs font-medium text-ink-secondary">{$t("Widget title")}
						<input bind:value={title} maxlength="80" placeholder={$t(catalog.find((metric) => metric.key === metricKey)?.label ?? 'A clear title')} class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink placeholder:text-ink-subtle focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base" />
					</label>
					<label class="block text-xs font-medium text-ink-secondary">{$t("Time range")}
						<select bind:value={rangeKey} class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base">
							{#each analyticsRanges as range (range.value)}<option value={range.value}>{$t(range.label)}</option>{/each}
						</select>
					</label>
					<label class="block text-xs font-medium text-ink-secondary">{$t("Chart type")}
						<select bind:value={chartType} class="mt-1.5 min-h-11 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:outline-none focus:ring-2 focus:ring-accent-ink/20 max-sm:text-base">
							<option value="line">{$t("Line")}</option><option value="bar">{$t("Bars")}</option>
						</select>
					</label>
					<button disabled={saving || widgets.length >= 12} class="min-h-11 self-end rounded-lg bg-accent px-4 text-sm font-semibold text-on-accent transition hover:bg-accent/90 disabled:cursor-not-allowed disabled:opacity-50">{$t(saving ? 'Saving…' : 'Add widget')}</button>
				</form>
			{/if}
		</section>
		</Modal>
	{/if}

	{#if loading}
		<div role="status" aria-live="polite" class="mx-auto flex min-h-64 max-w-2xl flex-col items-center justify-center py-12 text-center">
			<span class="size-8 animate-spin rounded-full border-2 border-line-strong border-t-accent-ink" aria-hidden="true"></span>
			<p class="mt-4 text-sm text-ink-secondary">{$t("Loading your charts…")}</p>
		</div>
	{:else if widgets.length === 0}
		<div class="mt-6 rounded-[20px] border border-dashed glass-surface px-5 py-14 text-center">
			<div class="mx-auto grid size-14 place-items-center rounded-2xl border border-line-strong bg-page text-xl text-accent-ink" aria-hidden="true">⌁</div>
			<h2 class="mt-4 text-xl font-semibold text-ink">{$t("Your dashboard is ready to configure.")}</h2>
			<p class="mx-auto mt-2 max-w-lg text-sm leading-6 text-ink-muted">{$t("Add a chart to follow expenses, utility consumption or distance travelled from the sources you can view.")}</p>
		</div>
	{:else}
		<div class="mt-6 grid gap-4 xl:grid-cols-2">
			{#each widgets as widget (widget.id)}
				<article class="min-w-0 rounded-[20px] border glass-surface p-4 sm:p-6">
					<div class="flex flex-wrap items-start justify-between gap-3">
						<div class="min-w-0"><p class="text-xs font-medium text-accent-ink">{catalog.find((metric) => metric.key === widget.metric_key)?.source_label ?? 'Source unavailable'} · {analyticsRanges.find((range) => range.value === widget.range_key)?.label}</p><h2 class="mt-1 truncate text-xl font-semibold text-ink">{widget.title}</h2></div>
						{#if canEdit}<button type="button" onclick={() => beginEdit(widget)} class="shrink-0 rounded-lg border border-line-strong px-3 py-2 text-xs font-semibold text-ink-secondary transition hover:border-accent-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink">{$t("Configure")}</button>{/if}
					</div>

					{#if !widget.available}
						<p class="mt-5 rounded-xl border border-line bg-page p-4 text-sm leading-6 text-ink-muted">{$t("This source is no longer available to your account. Its records are not loaded. The widget will return when view access is restored.")}</p>
					{:else if widget.series.length === 0}
						<p class="mt-5 grid min-h-48 place-items-center rounded-xl border border-line bg-page p-5 text-center text-sm text-ink-muted">{$t("No visible records match this time range.")}</p>
					{:else}
						<div class="mt-5 grid gap-4">
							{#each widget.series as series (series.label)}
								<section class="min-w-0 rounded-xl border border-line bg-page p-3 sm:p-4" aria-label={$t('{series} series', { series: series.label })}>
									<div class="flex flex-wrap items-baseline justify-between gap-2"><p class="text-sm font-semibold text-ink">{series.label}</p><p class="text-xs text-ink-muted">{$t("Total")} <span class="font-semibold tabular-nums text-ink-secondary">{displayedValue(series.total, widget.metric_key === 'finance.expenses')} {series.unit}</span></p></div>
									<div class="mt-3 min-w-0">
										<svg viewBox="0 0 720 210" preserveAspectRatio="none" class="h-44 w-full overflow-visible sm:h-48" role="img" aria-label={`${widget.title}, ${series.label}, ${$t('range')} ${analyticsRanges.find((range) => range.value === widget.range_key)?.label}`}>
											<line x1="28" y1="174" x2="692" y2="174" stroke="#334155" stroke-width="1" />
											{#if widget.chart_type === 'line'}
												{@const values = series.values.map((value) => Math.max(0, Number(value) || 0))}
												{@const maximum = Math.max(1, ...values)}
												{@const points = values.map((value, index) => `${values.length === 1 ? 360 : 34 + index * (652 / (values.length - 1))},${166 - (value / maximum) * 132}`).join(' ')}
												{#if values.length > 1}<polyline points={points} fill="none" stroke="#67e8f9" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />{/if}
												{#each values as value, index (index)}
													{@const x = values.length === 1 ? 360 : 34 + index * (652 / (values.length - 1))}
													{@const y = 166 - (value / maximum) * 132}
													<circle cx={x} cy={y} r="3.5" fill="#67e8f9"><title>{widget.periods[index]}: {displayedValue(series.values[index], widget.metric_key === 'finance.expenses')} {series.unit}</title></circle>
												{/each}
											{:else}
												{@const values = series.values.map((value) => Math.max(0, Number(value) || 0))}
												{@const maximum = Math.max(1, ...values)}
												{@const slot = 652 / Math.max(1, values.length)}
												{#each values as value, index (index)}
													{@const height = value === 0 ? 0 : Math.max(2, (value / maximum) * 132)}
													<rect x={34 + index * slot + slot * 0.16} y={166 - height} width={Math.max(1, slot * 0.68)} height={height} rx="3" fill="#67e8f9" opacity="0.88"><title>{widget.periods[index]}: {displayedValue(series.values[index], widget.metric_key === 'finance.expenses')} {series.unit}</title></rect>
												{/each}
											{/if}
											<text x="30" y="198" fill="#94a3b8" font-size="11">{widget.periods.length ? periodLabel(widget.periods[0], widget.range_key) : ''}</text>
											<text x="690" y="198" text-anchor="end" fill="#94a3b8" font-size="11">{widget.periods.length ? periodLabel(widget.periods[widget.periods.length - 1], widget.range_key) : ''}</text>
										</svg>
									</div>
								</section>
							{/each}
						</div>
					{/if}

					{#if editingId === widget.id}
						<Modal bind:open={editOpen} title={$t('Edit widget')}>
						<form class="mt-4 grid gap-3 rounded-xl border border-accent-ink/20 bg-accent/5 p-4 sm:grid-cols-2" onsubmit={(event) => { event.preventDefault(); void saveWidget(widget); }}>
							<label class="block text-xs font-medium text-ink-secondary">{$t("Widget title")}<input bind:value={editTitle} maxlength="80" required class="mt-1.5 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:outline-none max-sm:text-base" /></label>
							<label class="block text-xs font-medium text-ink-secondary">{$t("Time range")}<select bind:value={editRange} class="mt-1.5 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:outline-none max-sm:text-base">{#each analyticsRanges as range (range.value)}<option value={range.value}>{$t(range.label)}</option>{/each}</select></label>
							<label class="block text-xs font-medium text-ink-secondary">{$t("Chart type")}<select bind:value={editChart} class="mt-1.5 min-h-10 w-full rounded-[10px] border border-line-strong bg-page px-3 text-sm text-ink focus:border-accent-ink focus:outline-none max-sm:text-base"><option value="line">{$t("Line")}</option><option value="bar">{$t("Bars")}</option></select></label>
							<div class="flex items-end gap-2"><button disabled={saving} class="min-h-10 rounded-lg bg-accent px-3 text-xs font-semibold text-on-accent disabled:opacity-50">{$t("Save")}</button><button type="button" onclick={() => { editingId = null; editOpen = false; }} class="min-h-10 rounded-lg border border-line-strong px-3 text-xs font-semibold text-ink-secondary">{$t("Cancel")}</button><button type="button" disabled={saving} onclick={() => { widgetToRemove = widget; removeOpen = true; }} class="ml-auto min-h-10 rounded-lg border border-danger/30 px-3 text-xs font-semibold text-danger hover:bg-danger/10 disabled:opacity-50">{$t("Remove")}</button></div>
						</form>
						</Modal>
					{/if}
				</article>
			{/each}
		</div>
	{/if}
	{#if widgetToRemove}<ConfirmModal bind:open={removeOpen} title={$t('Remove widget?')} message={$t('Remove this chart from your dashboard?')} onConfirm={() => void removeWidget(widgetToRemove!)} />{/if}
</section>
