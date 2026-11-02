<script lang="ts">
	import type { Snippet } from 'svelte';
	import ThemePicker from './ThemePicker.svelte';
	import { t } from '#lib/i18n';

	let {
		eyebrow,
		title,
		description,
		note,
		wide = false,
		children
	}: {
		eyebrow: string;
		title: string;
		description: string;
		note?: string;
		wide?: boolean;
		children: Snippet;
	} = $props();
</script>

<main class="auth-shell">
	<div class="auth-frame">
		<header class="auth-header">
			<a href="/" class="auth-wordmark" aria-label={$t('Metricove home')}>
				<span class="auth-brand-mark" aria-hidden="true">
					<svg viewBox="0 0 32 32" fill="none">
						<path d="M6 23.5V9l10 8 10-8v14.5" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
						<path d="M11 23.5v-6.7m10 6.7v-6.7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
					</svg>
				</span>
				<span>Metricove</span>
			</a>
			<ThemePicker class="auth-theme-picker" />
		</header>

		<div class="auth-body">
			<aside class="auth-brand-panel" aria-label={$t('About Metricove')}>
				<div class="auth-brand-copy">
					<p class="auth-brand-kicker">{$t('Your household, in focus')}</p>
					<h2>{$t('One private place for your household.')}</h2>
					<p class="auth-brand-description">{$t('A simpler way to keep the things that matter close at hand.')}</p>
				</div>

				<div class="auth-art" aria-hidden="true">
					<div class="auth-art-index">
						<div class="auth-art-heading">
							<span class="auth-art-heading-mark" aria-hidden="true"></span>
							<span>{$t('Your modules')}</span>
						</div>
						<div class="auth-art-row auth-art-row--active">
							<span class="auth-art-row-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 7.5h16M6.5 4h11A2.5 2.5 0 0 1 20 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5v-11A2.5 2.5 0 0 1 6.5 4Z" stroke="currentColor" stroke-width="1.6"/><path d="M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>
							<span>{$t('Finance')}</span><span class="auth-art-row-end" aria-hidden="true"></span>
						</div>
						<div class="auth-art-row">
							<span class="auth-art-row-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M12 3v18M5 7h14M7 7l-4 7h8L7 7Zm10 0-4 7h8l-4-7Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span>
							<span>{$t('Utilities')}</span><span class="auth-art-row-end" aria-hidden="true"></span>
						</div>
						<div class="auth-art-row">
							<span class="auth-art-row-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M3 13.5 5.2 8a2 2 0 0 1 1.9-1.3h9.8A2 2 0 0 1 18.8 8l2.2 5.5v4h-2v-2H5v2H3v-4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="7" cy="13" r="1" fill="currentColor"/><circle cx="17" cy="13" r="1" fill="currentColor"/></svg></span>
							<span>{$t('Vehicles')}</span><span class="auth-art-row-end" aria-hidden="true"></span>
						</div>
					</div>
				</div>

				<p class="auth-brand-footnote"><span></span>{$t('One household · your space')}</p>
			</aside>

			<section class="auth-content" class:auth-content--wide={wide} aria-labelledby="auth-title">
				<div class="auth-form-block" class:auth-form-block--wide={wide}>
					<p class="auth-eyebrow">{$t(eyebrow)}</p>
					<h1 id="auth-title">{$t(title)}</h1>
					<p class="auth-description">{$t(description)}</p>
					{@render children()}
					{#if note}<p class="auth-account-note"><span class="auth-account-dot"></span>{$t(note)}</p>{/if}
				</div>
			</section>
		</div>
	</div>
</main>

<style>
	.auth-shell {
		min-height: 100svh;
		display: grid;
		place-items: center;
		padding: clamp(1rem, 4vh, 2.5rem) clamp(0.75rem, 3vw, 2.5rem);
		background: var(--surface-page);
	}

	.auth-frame {
		width: min(70rem, 100%);
		min-height: min(46rem, calc(100svh - 3rem));
		overflow: hidden;
		border: 1px solid color-mix(in srgb, var(--border-default) 82%, transparent);
		border-radius: 1.5rem;
		background: var(--surface-raised);
		box-shadow: 0 1.5rem 4rem rgb(15 23 42 / 9%);
	}

	.auth-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 1.5rem;
		border-bottom: 1px solid var(--border-default);
		padding: 1.25rem clamp(1.5rem, 4vw, 3rem);
	}

	.auth-wordmark {
		display: inline-flex;
		align-items: center;
		gap: 0.75rem;
		color: var(--text-primary);
		font-size: 1rem;
		font-weight: 600;
		text-decoration: none;
	}

	.auth-wordmark:focus-visible {
		border-radius: 0.25rem;
		outline: 2px solid var(--accent-text);
		outline-offset: 4px;
	}

	.auth-brand-mark {
		display: grid;
		width: 2.5rem;
		height: 2.5rem;
		place-items: center;
		border-radius: 0.75rem;
		background: #0f766e;
		color: #f0fdfa;
	}

	.auth-brand-mark svg {
		width: 1.75rem;
		height: 1.75rem;
	}

	:global(.auth-theme-picker) {
		width: 15rem;
	}

	.auth-body {
		display: grid;
		min-height: 40rem;
		grid-template-columns: minmax(0, 0.94fr) minmax(0, 1.06fr);
	}

	.auth-brand-panel {
		position: relative;
		display: flex;
		min-width: 0;
		flex-direction: column;
		justify-content: space-between;
		overflow: hidden;
		padding: clamp(2.5rem, 5vw, 4.25rem);
		background: #123d4b;
		color: #f0fdfa;
	}

	.auth-brand-copy,
	.auth-brand-footnote {
		position: relative;
		z-index: 1;
	}

	.auth-brand-kicker,
	.auth-eyebrow {
		margin: 0;
		font-size: 0.75rem;
		font-weight: 500;
	}

	.auth-brand-kicker {
		color: #99f6e4;
	}

	.auth-brand-panel h2 {
		max-width: 23rem;
		margin: 1.15rem 0 0;
		font-size: clamp(2rem, 3.8vw, 3rem);
		font-weight: 500;
		letter-spacing: -0.035em;
		line-height: 1.08;
	}

	.auth-brand-description {
		max-width: 21rem;
		margin: 1.25rem 0 0;
		color: rgb(226 255 251 / 75%);
		font-size: 1rem;
		line-height: 1.7;
	}

	.auth-art {
		position: relative;
		z-index: 0;
		min-height: 14rem;
		margin: 1.25rem -1.25rem 0;
		display: grid;
		place-items: center;
	}

	.auth-art-index {
		width: min(100%, 25rem);
		border: 1px solid rgb(204 251 241 / 17%);
		border-radius: 1rem;
		padding: 0.5rem;
		background: rgb(3 24 34 / 20%);
	}

	.auth-art-heading,
	.auth-art-row {
		display: flex;
		min-height: 2.5rem;
		align-items: center;
		gap: 0.75rem;
		padding-inline: 0.75rem;
	}

	.auth-art-heading {
		min-height: 2.25rem;
		margin-bottom: 0.25rem;
		color: rgb(226 255 251 / 72%);
		font-size: 0.75rem;
		font-weight: 500;
	}

	.auth-art-heading-mark {
		width: 0.25rem;
		height: 1rem;
		border-radius: 2px;
		background: #5eead4;
	}

	.auth-art-row {
		border-top: 1px solid rgb(204 251 241 / 10%);
		color: rgb(240 253 250 / 78%);
		font-size: 0.8125rem;
	}

	.auth-art-row--active {
		border-radius: 0.5rem;
		background: rgb(204 251 241 / 8%);
		color: #f0fdfa;
	}

	.auth-art-row-icon {
		display: grid;
		width: 1.25rem;
		height: 1.25rem;
		flex: 0 0 auto;
		place-items: center;
		color: inherit;
	}

	.auth-art-row-icon svg {
		width: 1.125rem;
		height: 1.125rem;
	}

	.auth-art-row-end {
		width: 0.375rem;
		height: 0.375rem;
		margin-left: auto;
		border-top: 1px solid currentColor;
		border-right: 1px solid currentColor;
		opacity: 0.55;
		transform: rotate(45deg);
	}

	.auth-brand-footnote {
		display: flex;
		align-items: center;
		gap: 0.625rem;
		margin: 1.5rem 0 0;
		color: rgb(204 251 241 / 62%);
		font-size: 0.6875rem;
		font-weight: 400;
	}

	.auth-brand-footnote span,
	.auth-account-dot {
		width: 0.45rem;
		height: 0.45rem;
		flex: 0 0 auto;
		border-radius: 50%;
		background: #5eead4;
		box-shadow: none;
	}

	.auth-content {
		display: flex;
		min-width: 0;
		align-items: center;
		justify-content: center;
		padding: clamp(2.5rem, 6vw, 5rem);
		background: var(--surface-raised);
	}

	.auth-content--wide {
		padding-block: clamp(1.75rem, 4vh, 3rem);
	}

	.auth-form-block {
		width: min(100%, 25rem);
	}

	.auth-form-block--wide {
		width: min(100%, 29rem);
	}

	.auth-eyebrow {
		color: var(--accent-text);
	}

	.auth-content h1 {
		margin: 0.75rem 0 0;
		color: var(--text-primary);
		font-size: clamp(2.5rem, 5vw, 3.5rem);
		font-weight: 500;
		letter-spacing: -0.035em;
		line-height: 1.05;
	}

	.auth-description {
		margin: 1rem 0 0;
		color: var(--text-muted);
		font-size: 0.9375rem;
		line-height: 1.7;
	}

	.auth-account-note {
		display: flex;
		align-items: center;
		gap: 0.7rem;
		margin: 2rem 0 0;
		color: var(--text-subtle);
		font-size: 0.75rem;
		line-height: 1.5;
	}

	.auth-account-dot {
		width: 0.4rem;
		height: 0.4rem;
		background: var(--accent-text);
		box-shadow: none;
	}

	@media (max-width: 900px) {
		.auth-body {
			grid-template-columns: minmax(0, 0.88fr) minmax(0, 1.12fr);
		}

		.auth-brand-panel {
			padding: 2.5rem 2rem;
		}

		.auth-art {
			margin-inline: -0.5rem;
		}

		.auth-content {
			padding-inline: 2.5rem;
		}
	}

	@media (max-width: 760px) {
		.auth-shell {
			align-items: start;
			padding: 0.75rem;
		}

		.auth-frame {
			width: min(32rem, 100%);
			min-height: 0;
			border-radius: 1.25rem;
		}

		.auth-header {
			flex-wrap: wrap;
			gap: 0.875rem 1rem;
			padding: 1rem 1.25rem;
		}

		:global(.auth-theme-picker) {
			width: 100%;
			max-width: 16rem;
		}

		.auth-body {
			min-height: 0;
			grid-template-columns: minmax(0, 1fr);
		}

		.auth-brand-panel {
			display: none;
		}

		.auth-content {
			padding: 2.5rem 1.5rem 2rem;
		}

		.auth-content--wide {
			padding-block: 1.25rem 1.5rem;
		}

		.auth-content h1 {
			font-size: 2.75rem;
		}

		.auth-account-note {
			margin-top: 1.5rem;
		}
	}

	@media (max-width: 360px) {
		.auth-header {
			padding-inline: 1rem;
		}

		.auth-content {
			padding-inline: 1.25rem;
		}
	}
</style>
