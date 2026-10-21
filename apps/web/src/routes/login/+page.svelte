<script lang="ts">
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import { csrfToken, type ApiResponse } from '#lib/auth';
	import Button from '#lib/ui/Button.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import TextField from '#lib/ui/TextField.svelte';
	import ThemePicker from '#lib/ui/ThemePicker.svelte';

	let email = '';
	let password = '';
	let error = '';
	let busy = false;

	onMount(() => {
		void fetch('/api/auth/session', { headers: { Accept: 'application/json' } })
			.then(async (response) => {
				if (!response.ok) return;
				const result = (await response.json()) as ApiResponse;
				if (result.user) void goto('/profile');
			})
			.catch(() => {});
	});

	async function signIn(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		error = '';
		const token = csrfToken();
		if (!token) {
			error = 'Reload this page and try again.';
			return;
		}

		busy = true;
		try {
			const response = await fetch('/api/auth/login', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-XSRF-TOKEN': token
				},
				body: JSON.stringify({ email, password })
			});
			const result = (await response.json()) as ApiResponse;

			if (response.status === 429) {
				error = 'Too many sign-in attempts. Wait a minute and try again.';
				return;
			}
			if (!response.ok || !result.user) {
				error = 'Email address or password is incorrect.';
				return;
			}

			password = '';
			await goto('/profile');
		} catch {
			error = 'Sign-in is unavailable. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head>
	<title>Sign in · Metricove</title>
	<meta name="description" content="Sign in to your Metricove household." />
</svelte:head>

<main class="login-shell">
	<div class="login-frame">
		<header class="login-header">
			<a href="/" class="login-wordmark" aria-label="Metricove home">
				<span class="login-brand-mark" aria-hidden="true">
					<svg viewBox="0 0 32 32" fill="none">
						<path d="M6 23.5V9l10 8 10-8v14.5" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
						<path d="M11 23.5v-6.7m10 6.7v-6.7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
					</svg>
				</span>
				<span>Metricove</span>
			</a>
			<ThemePicker class="login-theme-picker" />
		</header>

		<div class="login-body">
			<aside class="login-brand-panel" aria-label="About Metricove">
				<div class="login-brand-copy">
					<p class="login-brand-kicker">YOUR HOUSEHOLD, IN FOCUS</p>
					<h2>One private place for your household.</h2>
					<p class="login-brand-description">A simpler way to keep the things that matter close at hand.</p>
				</div>

				<div class="login-art" aria-hidden="true">
					<div class="login-art-glow"></div>
					<div class="login-art-orbit login-art-orbit--outer"></div>
					<div class="login-art-orbit login-art-orbit--inner"></div>
					<div class="login-art-tile login-art-tile--back">
						<span></span><span></span><span></span>
					</div>
					<div class="login-art-tile login-art-tile--front">
						<span class="login-art-tile-mark"></span>
						<span class="login-art-line login-art-line--long"></span>
						<span class="login-art-line"></span>
						<span class="login-art-line login-art-line--short"></span>
					</div>
				</div>

				<p class="login-brand-footnote"><span></span>ONE HOUSEHOLD · YOUR SPACE</p>
			</aside>

			<section class="login-content" aria-labelledby="login-title">
				<div class="login-form-block">
					<p class="login-eyebrow">Welcome back</p>
					<h1 id="login-title">Sign in</h1>
					<p class="login-description">Enter the email address and password registered for this installation.</p>

					<form class="login-form" onsubmit={signIn} aria-busy={busy}>
						<TextField
							id="email"
							name="email"
							label="Email address"
							type="email"
							autocomplete="username"
							autocapitalize="none"
							spellcheck={false}
							bind:value={email}
							required
							maxlength={255}
						/>
						<div class="login-password-group">
							<TextField
								id="password"
								name="password"
								label="Password"
								type="password"
								autocomplete="current-password"
								bind:value={password}
								required
							/>
							<div class="login-forgot-row">
								<a href="/password/forgot" class="login-forgot-link">Forgot password?</a>
							</div>
						</div>
						{#if error}<Notice class="login-error" tone="error" message={error} />{/if}
						<Button type="submit" fullWidth disabled={busy} class="login-submit">
							{busy ? 'Signing in…' : 'Sign in'}
						</Button>
					</form>

					<p class="login-account-note"><span class="login-account-dot"></span>Your account is tied to this Metricove installation.</p>
				</div>
			</section>
		</div>
	</div>
</main>

<style>
	.login-shell {
		--login-teal: #0f766e;
		min-height: 100svh;
		display: grid;
		place-items: center;
		padding: clamp(1rem, 4vh, 2.5rem) clamp(0.75rem, 3vw, 2.5rem);
		background:
			radial-gradient(ellipse at 8% 8%, color-mix(in srgb, var(--accent) 13%, transparent), transparent 34rem),
			radial-gradient(ellipse at 92% 88%, color-mix(in srgb, var(--login-teal) 8%, transparent), transparent 30rem),
			var(--surface-page);
	}

	.login-frame {
		width: min(70rem, 100%);
		min-height: min(46rem, calc(100svh - 3rem));
		overflow: hidden;
		border: 1px solid color-mix(in srgb, var(--border-default) 82%, transparent);
		border-radius: 1.75rem;
		background: var(--surface-raised);
		box-shadow: 0 2rem 6rem rgb(15 23 42 / 12%);
	}

	.login-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 1.5rem;
		border-bottom: 1px solid var(--border-default);
		padding: 1.25rem clamp(1.5rem, 4vw, 3rem);
	}

	.login-wordmark {
		display: inline-flex;
		align-items: center;
		gap: 0.75rem;
		color: var(--text-primary);
		font-size: 1rem;
		font-weight: 720;
		letter-spacing: -0.025em;
		text-decoration: none;
	}

	.login-wordmark:focus-visible,
	.login-forgot-link:focus-visible {
		border-radius: 0.25rem;
		outline: 2px solid var(--accent-text);
		outline-offset: 4px;
	}

	.login-brand-mark {
		display: grid;
		width: 2.5rem;
		height: 2.5rem;
		place-items: center;
		border-radius: 0.875rem;
		background: linear-gradient(145deg, #0f766e, #14b8a6);
		color: #f0fdfa;
		box-shadow: inset 0 1px rgb(255 255 255 / 20%), 0 0.375rem 1rem rgb(15 118 110 / 20%);
	}

	.login-brand-mark svg {
		width: 1.75rem;
		height: 1.75rem;
	}

	:global(.login-theme-picker) {
		width: 12rem;
	}

	.login-body {
		display: grid;
		min-height: 40rem;
		grid-template-columns: minmax(0, 0.94fr) minmax(0, 1.06fr);
	}

	.login-brand-panel {
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

	.login-brand-copy,
	.login-brand-footnote {
		position: relative;
		z-index: 1;
	}

	.login-brand-kicker,
	.login-eyebrow {
		margin: 0;
		font-size: 0.75rem;
		font-weight: 700;
		letter-spacing: 0.13em;
		text-transform: uppercase;
	}

	.login-brand-kicker {
		color: #99f6e4;
	}

	.login-brand-panel h2 {
		max-width: 23rem;
		margin: 1.15rem 0 0;
		font-size: clamp(2.25rem, 4.2vw, 3.65rem);
		font-weight: 620;
		letter-spacing: -0.052em;
		line-height: 1.04;
	}

	.login-brand-description {
		max-width: 21rem;
		margin: 1.25rem 0 0;
		color: rgb(226 255 251 / 75%);
		font-size: 1rem;
		line-height: 1.7;
	}

	.login-art {
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

	.login-art-glow {
		position: absolute;
		top: 7%;
		left: 19%;
		width: 15rem;
		aspect-ratio: 1;
		border-radius: 50%;
		background: radial-gradient(circle, rgb(45 212 191 / 21%), transparent 68%);
	}

	.login-art-orbit {
		position: absolute;
		aspect-ratio: 1;
		border: 1px solid rgb(204 251 241 / 18%);
		border-radius: 50%;
	}

	.login-art-orbit--outer {
		top: -55%;
		left: 17%;
		width: 19rem;
	}

	.login-art-orbit--inner {
		top: -18%;
		left: 32%;
		width: 12rem;
		border-color: rgb(204 251 241 / 13%);
	}

	.login-art-tile {
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

	.login-art-tile--back {
		right: 35%;
		bottom: 24%;
		transform: rotate(8deg);
		background: rgb(226 255 251 / 8%);
	}

	.login-art-tile--back span {
		width: 78%;
		height: 0.35rem;
		border-radius: 999px;
		background: rgb(204 251 241 / 35%);
	}

	.login-art-tile--back span:nth-child(2) {
		width: 58%;
		background: rgb(204 251 241 / 23%);
	}

	.login-art-tile--back span:nth-child(3) {
		width: 68%;
		background: rgb(204 251 241 / 18%);
	}

	.login-art-tile--front {
		right: 9%;
		bottom: 7%;
		border-color: rgb(204 251 241 / 32%);
		transform: rotate(-7deg);
		background: linear-gradient(145deg, rgb(226 255 251 / 15%), rgb(226 255 251 / 7%));
		box-shadow: 0 1.25rem 3rem rgb(1 12 18 / 28%);
	}

	.login-art-tile-mark {
		width: 1.7rem;
		height: 1.7rem;
		border: 1px solid rgb(153 246 228 / 35%);
		border-radius: 0.55rem;
		background: linear-gradient(145deg, rgb(45 212 191 / 72%), rgb(20 184 166 / 28%));
	}

	.login-art-line {
		width: 56%;
		height: 0.32rem;
		border-radius: 999px;
		background: rgb(204 251 241 / 35%);
	}

	.login-art-line--long {
		width: 83%;
		background: rgb(204 251 241 / 55%);
	}

	.login-art-line--short {
		width: 39%;
		background: rgb(204 251 241 / 22%);
	}

	.login-brand-footnote {
		display: flex;
		align-items: center;
		gap: 0.625rem;
		margin: 1.5rem 0 0;
		color: rgb(204 251 241 / 62%);
		font-size: 0.6875rem;
		font-weight: 700;
		letter-spacing: 0.12em;
	}

	.login-brand-footnote span,
	.login-account-dot {
		width: 0.45rem;
		height: 0.45rem;
		flex: 0 0 auto;
		border-radius: 50%;
		background: #5eead4;
		box-shadow: 0 0 0 0.25rem rgb(94 234 212 / 13%);
	}

	.login-content {
		display: flex;
		min-width: 0;
		align-items: center;
		justify-content: center;
		padding: clamp(2.5rem, 6vw, 5rem);
		background: var(--surface-raised);
	}

	.login-form-block {
		width: min(100%, 25rem);
	}

	.login-eyebrow {
		color: var(--accent-text);
	}

	.login-content h1 {
		margin: 0.75rem 0 0;
		color: var(--text-primary);
		font-size: clamp(2.5rem, 5vw, 3.5rem);
		font-weight: 650;
		letter-spacing: -0.055em;
		line-height: 1.05;
	}

	.login-description {
		margin: 1rem 0 0;
		color: var(--text-muted);
		font-size: 0.9375rem;
		line-height: 1.7;
	}

	.login-form {
		display: grid;
		gap: 1.25rem;
		margin-top: 2rem;
	}

	.login-forgot-row {
		display: flex;
		justify-content: flex-end;
		margin-top: 0.75rem;
	}

	.login-forgot-link {
		color: var(--accent-text);
		font-size: 0.8125rem;
		font-weight: 650;
		text-decoration-thickness: 1px;
		text-underline-offset: 0.2em;
	}

	.login-forgot-link:hover {
		color: var(--text-primary);
	}

	:global(.login-error) {
		margin-top: -0.125rem;
	}

	:global(.login-submit) {
		min-height: 3rem;
		border-radius: 0.8rem;
		font-size: 0.9375rem;
	}

	.login-account-note {
		display: flex;
		align-items: center;
		gap: 0.7rem;
		margin: 2rem 0 0;
		color: var(--text-subtle);
		font-size: 0.75rem;
		line-height: 1.5;
	}

	.login-account-dot {
		width: 0.4rem;
		height: 0.4rem;
		background: var(--positive-text);
		box-shadow: 0 0 0 0.2rem color-mix(in srgb, var(--positive-text) 13%, transparent);
	}

	@media (max-width: 900px) {
		.login-body {
			grid-template-columns: minmax(0, 0.88fr) minmax(0, 1.12fr);
		}

		.login-brand-panel {
			padding: 2.5rem 2rem;
		}

		.login-art {
			margin-inline: -0.5rem;
		}

		.login-content {
			padding-inline: 2.5rem;
		}
	}

	@media (max-width: 760px) {
		.login-shell {
			align-items: start;
			padding: 0.75rem;
		}

		.login-frame {
			width: min(32rem, 100%);
			min-height: 0;
			border-radius: 1.25rem;
		}

		.login-header {
			flex-wrap: wrap;
			gap: 0.875rem 1rem;
			padding: 1rem 1.25rem;
		}

		:global(.login-theme-picker) {
			width: 11rem;
		}

		.login-body {
			min-height: 0;
			grid-template-columns: minmax(0, 1fr);
		}

		.login-brand-panel {
			display: none;
		}

		.login-content {
			padding: 2.5rem 1.5rem 2rem;
		}

		.login-content h1 {
			font-size: 2.75rem;
		}

		.login-account-note {
			margin-top: 1.5rem;
		}
	}

	@media (max-width: 360px) {
		.login-header {
			padding-inline: 1rem;
		}

		.login-content {
			padding-inline: 1.25rem;
		}
	}
</style>
