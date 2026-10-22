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
					<p class="auth-brand-kicker">{$t('YOUR HOUSEHOLD, IN FOCUS')}</p>
					<h2>{$t('One private place for your household.')}</h2>
					<p class="auth-brand-description">{$t('A simpler way to keep the things that matter close at hand.')}</p>
				</div>

				<div class="auth-art" aria-hidden="true">
					<div class="auth-art-glow"></div>
					<div class="auth-art-orbit auth-art-orbit--outer"></div>
					<div class="auth-art-orbit auth-art-orbit--inner"></div>
					<div class="auth-art-tile auth-art-tile--back">
						<span></span><span></span><span></span>
					</div>
					<div class="auth-art-tile auth-art-tile--front">
						<span class="auth-art-tile-mark"></span>
						<span class="auth-art-line auth-art-line--long"></span>
						<span class="auth-art-line"></span>
						<span class="auth-art-line auth-art-line--short"></span>
					</div>
				</div>

				<p class="auth-brand-footnote"><span></span>{$t('ONE HOUSEHOLD · YOUR SPACE')}</p>
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
		--auth-teal: #0f766e;
		min-height: 100svh;
		display: grid;
		place-items: center;
		padding: clamp(1rem, 4vh, 2.5rem) clamp(0.75rem, 3vw, 2.5rem);
		background:
			radial-gradient(ellipse at 8% 8%, color-mix(in srgb, var(--accent) 13%, transparent), transparent 34rem),
			radial-gradient(ellipse at 92% 88%, color-mix(in srgb, var(--auth-teal) 8%, transparent), transparent 30rem),
			var(--surface-page);
	}

	.auth-frame {
		width: min(70rem, 100%);
		min-height: min(46rem, calc(100svh - 3rem));
		overflow: hidden;
		border: 1px solid color-mix(in srgb, var(--border-default) 82%, transparent);
		border-radius: 1.75rem;
		background: var(--surface-raised);
		box-shadow: 0 2rem 6rem rgb(15 23 42 / 12%);
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
		font-weight: 720;
		letter-spacing: -0.025em;
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
		border-radius: 0.875rem;
		background: linear-gradient(145deg, #0f766e, #14b8a6);
		color: #f0fdfa;
		box-shadow: inset 0 1px rgb(255 255 255 / 20%), 0 0.375rem 1rem rgb(15 118 110 / 20%);
	}

	.auth-brand-mark svg {
		width: 1.75rem;
		height: 1.75rem;
	}

	:global(.auth-theme-picker) {
		width: 12rem;
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
		background:
			radial-gradient(circle at 82% 15%, rgb(45 212 191 / 15%), transparent 19rem),
			linear-gradient(150deg, #0d2635 0%, #123d4b 54%, #0d463f 100%);
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
		font-weight: 700;
		letter-spacing: 0.13em;
		text-transform: uppercase;
	}

	.auth-brand-kicker {
		color: #99f6e4;
	}

	.auth-brand-panel h2 {
		max-width: 23rem;
		margin: 1.15rem 0 0;
		font-size: clamp(2.25rem, 4.2vw, 3.65rem);
		font-weight: 620;
		letter-spacing: -0.052em;
		line-height: 1.04;
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
		overflow: hidden;
		border: 1px solid rgb(204 251 241 / 13%);
		border-radius: 1.5rem;
		background:
			linear-gradient(90deg, rgb(204 251 241 / 5%) 1px, transparent 1px),
			linear-gradient(rgb(204 251 241 / 5%) 1px, transparent 1px),
			rgb(3 24 34 / 18%);
		background-size: 1.75rem 1.75rem;
	}

	.auth-art-glow {
		position: absolute;
		top: 7%;
		left: 19%;
		width: 15rem;
		aspect-ratio: 1;
		border-radius: 50%;
		background: radial-gradient(circle, rgb(45 212 191 / 21%), transparent 68%);
	}

	.auth-art-orbit {
		position: absolute;
		aspect-ratio: 1;
		border: 1px solid rgb(204 251 241 / 18%);
		border-radius: 50%;
	}

	.auth-art-orbit--outer {
		top: -55%;
		left: 17%;
		width: 19rem;
	}

	.auth-art-orbit--inner {
		top: -18%;
		left: 32%;
		width: 12rem;
		border-color: rgb(204 251 241 / 13%);
	}

	.auth-art-tile {
		position: absolute;
		display: flex;
		width: 11rem;
		height: 8rem;
		flex-direction: column;
		justify-content: center;
		gap: 0.75rem;
		border: 1px solid rgb(204 251 241 / 24%);
		border-radius: 1.25rem;
		padding: 1.25rem;
		backdrop-filter: blur(12px);
	}

	.auth-art-tile--back {
		right: 35%;
		bottom: 24%;
		transform: rotate(8deg);
		background: rgb(226 255 251 / 8%);
	}

	.auth-art-tile--back span {
		width: 78%;
		height: 0.35rem;
		border-radius: 999px;
		background: rgb(204 251 241 / 35%);
	}

	.auth-art-tile--back span:nth-child(2) {
		width: 58%;
		background: rgb(204 251 241 / 23%);
	}

	.auth-art-tile--back span:nth-child(3) {
		width: 68%;
		background: rgb(204 251 241 / 18%);
	}

	.auth-art-tile--front {
		right: 9%;
		bottom: 7%;
		border-color: rgb(204 251 241 / 32%);
		transform: rotate(-7deg);
		background: linear-gradient(145deg, rgb(226 255 251 / 15%), rgb(226 255 251 / 7%));
		box-shadow: 0 1.25rem 3rem rgb(1 12 18 / 28%);
	}

	.auth-art-tile-mark {
		width: 1.7rem;
		height: 1.7rem;
		border: 1px solid rgb(153 246 228 / 35%);
		border-radius: 0.55rem;
		background: linear-gradient(145deg, rgb(45 212 191 / 72%), rgb(20 184 166 / 28%));
	}

	.auth-art-line {
		width: 56%;
		height: 0.32rem;
		border-radius: 999px;
		background: rgb(204 251 241 / 35%);
	}

	.auth-art-line--long {
		width: 83%;
		background: rgb(204 251 241 / 55%);
	}

	.auth-art-line--short {
		width: 39%;
		background: rgb(204 251 241 / 22%);
	}

	.auth-brand-footnote {
		display: flex;
		align-items: center;
		gap: 0.625rem;
		margin: 1.5rem 0 0;
		color: rgb(204 251 241 / 62%);
		font-size: 0.6875rem;
		font-weight: 700;
		letter-spacing: 0.12em;
	}

	.auth-brand-footnote span,
	.auth-account-dot {
		width: 0.45rem;
		height: 0.45rem;
		flex: 0 0 auto;
		border-radius: 50%;
		background: #5eead4;
		box-shadow: 0 0 0 0.25rem rgb(94 234 212 / 13%);
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
		font-weight: 650;
		letter-spacing: -0.055em;
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
		background: var(--positive-text);
		box-shadow: 0 0 0 0.2rem color-mix(in srgb, var(--positive-text) 13%, transparent);
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
			width: 11rem;
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
