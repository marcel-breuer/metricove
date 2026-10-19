<script lang="ts">
	import { onMount } from 'svelte';

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
		<header class="flex items-center gap-3 text-sm font-semibold tracking-[0.18em] text-cyan-300 uppercase">
			<span class="grid size-9 place-items-center rounded-xl bg-cyan-300/10 text-lg">M</span>
			Metricove
		</header>

		<section class="my-auto grid gap-10 py-12 lg:grid-cols-[0.9fr_1.1fr] lg:items-center lg:gap-16">
			<div class="max-w-xl">
				<p class="mb-5 text-sm font-medium tracking-[0.18em] text-slate-400 uppercase">
					One household, one private space
				</p>
				<h1 class="text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">
					Start with your household administrator.
				</h1>
				<p class="mt-6 text-base leading-7 text-slate-300">
					Create the first account for this installation. It becomes the active household administrator.
				</p>
			</div>

			<div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-6 shadow-2xl shadow-black/20 sm:p-8">
				{#if state === 'loading'}
					<p class="text-sm text-slate-300" role="status">Checking household setup…</p>
				{:else if state === 'available'}
					<h2 class="text-xl font-semibold">Create your administrator account</h2>
					<form class="mt-6 space-y-5" onsubmit={completeSetup}>
						<div>
							<label for="name" class="mb-2 block text-sm font-medium text-slate-200">Your name</label>
							<input
								id="name"
								name="name"
								autocomplete="name"
								bind:value={name}
								required
								maxlength="255"
								class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"
							/>
						</div>
						<div>
							<label for="email" class="mb-2 block text-sm font-medium text-slate-200">Email address</label>
							<input
								id="email"
								name="email"
								type="email"
								autocomplete="email"
								bind:value={email}
								required
								maxlength="255"
								class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"
							/>
						</div>
						<div>
							<label for="password" class="mb-2 block text-sm font-medium text-slate-200">Password</label>
							<input
								id="password"
								name="password"
								type="password"
								autocomplete="new-password"
								bind:value={password}
								minlength="12"
								required
								class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"
							/>
							<p class="mt-2 text-xs text-slate-400">Use at least 12 characters.</p>
						</div>
						<div>
							<label for="password_confirmation" class="mb-2 block text-sm font-medium text-slate-200">
								Confirm password
							</label>
							<input
								id="password_confirmation"
								name="password_confirmation"
								type="password"
								autocomplete="new-password"
								bind:value={passwordConfirmation}
								required
								class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20"
							/>
						</div>
						{#if formError}
							<p class="rounded-lg border border-rose-400/30 bg-rose-400/10 px-3 py-2 text-sm text-rose-200" role="alert">
								{formError}
							</p>
						{/if}
						<button
							type="submit"
							disabled={busy}
							class="inline-flex w-full items-center justify-center rounded-lg bg-cyan-300 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:cursor-wait disabled:opacity-60"
						>
							{busy ? 'Creating account…' : 'Create administrator account'}
						</button>
					</form>
				{:else if state === 'created'}
					<div role="status" aria-live="polite">
						<p class="text-sm font-medium text-emerald-300">Household ready</p>
						<h2 class="mt-2 text-2xl font-semibold">Welcome, {createdName}.</h2>
						<p class="mt-3 text-sm leading-6 text-slate-300">
							Your administrator account is active and this browser session is signed in.
						</p>
						<a
							href="/profile"
							class="mt-6 inline-flex items-center rounded-lg bg-cyan-300 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300"
						>
							Continue
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
						<button
							type="button"
							onclick={loadStatus}
							class="mt-4 rounded-lg border border-slate-700 px-4 py-2 text-sm font-medium text-slate-200 hover:border-cyan-300"
						>
							Try again
						</button>
					</div>
				{/if}
			</div>
		</section>

		<footer class="border-t border-slate-800 pt-5 text-sm text-slate-500">
			The first account is the active administrator for this installation.
		</footer>
	</div>
</main>
