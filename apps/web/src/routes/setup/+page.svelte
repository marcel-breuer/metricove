<script lang="ts">
	import { onMount } from 'svelte';
	import Button from '#lib/ui/Button.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import Panel from '#lib/ui/Panel.svelte';
	import TextField from '#lib/ui/TextField.svelte';
	import ThemePicker from '#lib/ui/ThemePicker.svelte';

	type SetupState = 'loading' | 'available' | 'complete' | 'created' | 'error';
	type SetupResponse = {
		message?: string;
		user?: { name: string };
		errors?: Record<string, string[]>;
	};

	let state: SetupState = 'loading';
	let name = '';
	let email = '';
	let password = '';
	let passwordConfirmation = '';
	let formError = '';
	let createdName = '';
	let busy = false;

	onMount(() => {
		void loadStatus();
	});

	async function loadStatus(): Promise<void> {
		try {
			const response = await fetch('/api/setup/status', {
				headers: { Accept: 'application/json' }
			});

			if (!response.ok) {
				throw new Error('Setup status is unavailable.');
			}

			const result = (await response.json()) as { setup_required: boolean };
			state = result.setup_required ? 'available' : 'complete';
		} catch {
			state = 'error';
		}
	}

	function csrfToken(): string {
		const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));

		return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
	}

	async function completeSetup(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		formError = '';

		const token = csrfToken();
		if (!token) {
			formError = 'Reload this page and try again.';
			return;
		}

		busy = true;
		try {
			const response = await fetch('/api/setup', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-XSRF-TOKEN': token
				},
				body: JSON.stringify({
					name,
					email,
					password,
					password_confirmation: passwordConfirmation
				})
			});
			const result = (await response.json()) as SetupResponse;

			if (response.status === 409) {
				state = 'complete';
				return;
			}

			if (!response.ok || !result.user) {
				const validationMessage = Object.values(result.errors ?? {}).flat()[0];
				formError = validationMessage ?? result.message ?? 'Setup could not be completed.';
				return;
			}

			createdName = result.user.name;
			state = 'created';
		} catch {
			formError = 'The server could not complete setup. Check your connection and try again.';
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head>
	<title>Set up Metricove</title>
	<meta name="description" content="Create the first administrator for this Metricove household." />
</svelte:head>

<main class="min-h-screen bg-slate-950 px-6 py-10 text-slate-100 sm:px-10 sm:py-16">
	<div class="mx-auto flex min-h-[calc(100vh-5rem)] max-w-5xl flex-col">
		<header class="flex flex-wrap items-center justify-between gap-5">
			<a href="/" class="flex items-center gap-3 text-sm font-semibold tracking-[0.18em] text-cyan-300 uppercase">
				<span class="grid size-9 place-items-center rounded-xl bg-cyan-300/10 text-lg">M</span>
				Metricove
			</a>
			<ThemePicker class="w-48" />
		</header>

		<section class="my-auto grid gap-10 py-12 lg:grid-cols-[0.9fr_1.1fr] lg:items-center lg:gap-16">
			<PageHeading class="max-w-xl" eyebrow="One household, one private space" title="Start with your household administrator." description="Create the first account for this installation. It becomes the active household administrator." />

			<Panel padding="large" class="sm:p-8">
				{#if state === 'loading'}
					<p class="text-sm text-slate-300" role="status">Checking household setup…</p>
				{:else if state === 'available'}
					<h2 class="text-xl font-semibold">Create your administrator account</h2>
					<form class="mt-6 space-y-5" onsubmit={completeSetup}>
						<TextField id="name" name="name" label="Your name" autocomplete="name" bind:value={name} required maxlength={255} />
						<TextField id="email" name="email" label="Email address" type="email" autocomplete="email" bind:value={email} required maxlength={255} />
						<div class="space-y-2">
							<TextField id="password" name="password" label="Password" type="password" autocomplete="new-password" bind:value={password} minlength={12} required />
							<p class="text-xs text-slate-400">Use at least 12 characters.</p>
						</div>
						<TextField id="password_confirmation" name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" bind:value={passwordConfirmation} required />
						{#if formError}
							<Notice tone="error" message={formError} />
						{/if}
						<Button type="submit" fullWidth disabled={busy}>
							{busy ? 'Creating account…' : 'Create administrator account'}
						</Button>
					</form>
				{:else if state === 'created'}
					<div role="status" aria-live="polite">
						<p class="text-sm font-medium text-emerald-300">Household ready</p>
						<h2 class="mt-2 text-2xl font-semibold">Welcome, {createdName}.</h2>
						<p class="mt-3 text-sm leading-6 text-slate-300">
							Your administrator account is active. Choose the features your household wants to use; you can change them later.
						</p>
						<a
							href="/admin/modules"
							class="mt-6 inline-flex items-center rounded-lg bg-cyan-300 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300"
						>
							Choose household features
						</a>
					</div>
				{:else if state === 'complete'}
					<div>
						<p class="text-sm font-medium text-emerald-300">Setup is complete</p>
						<h2 class="mt-2 text-2xl font-semibold">This household is already set up.</h2>
						<p class="mt-3 text-sm leading-6 text-slate-300">
							Initial registration is closed for this installation.
						</p>
						<a href="/" class="mt-6 inline-flex text-sm font-semibold text-cyan-200 hover:text-cyan-100">
							Return home
						</a>
					</div>
				{:else}
					<div>
						<p class="text-sm font-medium text-rose-200" role="alert">Setup status could not be loaded.</p>
						<Button variant="secondary" class="mt-4" onclick={loadStatus}>
							Try again
						</Button>
					</div>
				{/if}
			</Panel>
		</section>

		<footer class="border-t border-slate-800 pt-5 text-sm text-slate-500">
			The first account is the active administrator for this installation.
		</footer>
	</div>
</main>
