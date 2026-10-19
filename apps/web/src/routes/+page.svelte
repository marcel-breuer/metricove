<script lang="ts">
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';

	onMount(() => {
		let cancelled = false;

		void fetch('/api/setup/status', { headers: { Accept: 'application/json' } })
			.then((response) => (response.ok ? response.json() : null))
			.then((status: { setup_required?: boolean } | null) => {
				if (!cancelled && status?.setup_required) {
					void goto('/setup');
				}
			})
			.catch(() => {});

		return () => {
			cancelled = true;
		};
	});
</script>

<svelte:head>
	<title>Metricove</title>
	<meta
		name="description"
		content="A self-hosted home for household metrics and everyday organization."
	/>
</svelte:head>

<main class="min-h-screen bg-slate-950 px-6 py-16 text-slate-100 sm:px-10">
	<div class="mx-auto flex min-h-[calc(100vh-8rem)] max-w-5xl flex-col">
		<header class="flex items-center gap-3 text-sm font-semibold tracking-[0.18em] text-cyan-300 uppercase">
			<span class="grid size-9 place-items-center rounded-xl bg-cyan-300/10 text-lg">M</span>
			Metricove
		</header>

		<section class="my-auto max-w-3xl py-20">
			<p class="mb-5 text-sm font-medium tracking-[0.18em] text-slate-400 uppercase">
				Your household, at a glance
			</p>
			<h1 class="text-4xl font-semibold leading-tight tracking-tight sm:text-6xl">
				Make everyday numbers easier to understand.
			</h1>
			<p class="mt-6 max-w-2xl text-lg leading-8 text-slate-300">
				Metricove brings household measurements and everyday organization together in one
				self-hosted place. The application foundation is ready for development.
			</p>
			<a
				href="/health"
				class="mt-10 inline-flex items-center rounded-lg border border-slate-700 px-4 py-2 text-sm font-medium text-slate-200 transition hover:border-cyan-300 hover:text-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300"
			>
				Check service health
			</a>
			<a
				href="/setup"
				class="ml-3 mt-10 inline-flex items-center rounded-lg bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300"
			>
				Set up this household
			</a>
		</section>

		<footer class="border-t border-slate-800 pt-5 text-sm text-slate-500">
			Private by design. Built for one household per installation.
		</footer>
	</div>
</main>
