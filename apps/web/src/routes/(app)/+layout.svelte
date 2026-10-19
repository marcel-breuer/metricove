<script lang="ts">
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { onMount, setContext, tick } from 'svelte';
	import type { LayoutProps } from './$types';
	import { csrfToken, type ApiResponse, type AuthUser } from '#lib/auth';
	import {
		APP_SHELL_CONTEXT,
		type AppShellContext,
		type AvailableModule
	} from '#lib/app-shell';

	let { children }: LayoutProps = $props();

	let currentUser = $state<AuthUser | null>(null);
	let modules = $state<AvailableModule[]>([]);
	let loading = $state(true);
	let shellError = $state('');
	let signOutError = $state('');
	let drawerOpen = $state(false);
	let quickAddOpen = $state(false);
	let drawer: HTMLElement | null = $state(null);
	let quickAddDialog: HTMLElement | null = $state(null);
	let drawerTrigger: HTMLButtonElement | null = $state(null);
	let quickAddTrigger: HTMLButtonElement | null = $state(null);

	const shellContext: AppShellContext = {
		get currentUser() {
			return currentUser;
		},
		get modules() {
			return modules;
		},
		get loading() {
			return loading;
		},
		refresh: loadShell
	};
	setContext(APP_SHELL_CONTEXT, shellContext);

	const pathname = $derived(page.url.pathname);
	const pageTitle = $derived(titleForPath(pathname));

	onMount(() => {
		void loadShell();
	});

	function titleForPath(path: string): string {
		if (path === '/') return 'Overview';
		if (path === '/profile') return 'Profile';
		if (path === '/admin/members') return 'Members';
		if (path.startsWith('/admin/members/')) return 'Module rights';
		if (path === '/admin/modules') return 'Modules';
		if (path === '/admin/invitations') return 'Invitations';
		if (path === '/modules/tracking') return 'Tracking';
		if (path.startsWith('/modules/tracking/')) return 'Tracker history';
		if (path.startsWith('/modules/')) {
			return modules.find((module) => path === `/modules/${module.key}`)?.label ?? 'Module';
		}
		return 'Metricove';
	}

	function isActive(path: string): boolean {
		if (path === '/') return pathname === '/';
		if (path === '/admin/members') return pathname.startsWith('/admin/members');
		return pathname === path || pathname.startsWith(`${path}/`);
	}

	async function loadShell(): Promise<void> {
		loading = true;
		shellError = '';

		try {
			const setupResponse = await fetch('/api/setup/status', {
				headers: { Accept: 'application/json' }
			});
			if (setupResponse.ok) {
				const setup = (await setupResponse.json()) as { setup_required?: boolean };
				if (setup.setup_required) {
					await goto('/setup');
					return;
				}
			}

			const sessionResponse = await fetch('/api/auth/session', {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' }
			});
			if (sessionResponse.status === 401) {
				await goto('/login');
				return;
			}
			if (!sessionResponse.ok) throw new Error('Your session is unavailable. Try again.');

			const session = (await sessionResponse.json()) as ApiResponse;
			if (!session.user) {
				await goto('/login');
				return;
			}
			currentUser = session.user;

			const modulesResponse = await fetch('/api/modules', {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' }
			});
			if (modulesResponse.status === 401) {
				await goto('/login');
				return;
			}
			if (!modulesResponse.ok) throw new Error('Module navigation is unavailable. Try again.');

			const result = (await modulesResponse.json()) as { available_modules?: AvailableModule[] };
			modules = result.available_modules ?? [];
		} catch (cause) {
			shellError = cause instanceof Error
				? cause.message
				: 'Metricove is unavailable. Check your connection and try again.';
		} finally {
			loading = false;
		}
	}

	async function openDrawer(): Promise<void> {
		drawerOpen = true;
		await tick();
		drawer?.querySelector<HTMLElement>('a, button')?.focus();
	}

	async function closeDrawer(): Promise<void> {
		drawerOpen = false;
		await tick();
		drawerTrigger?.focus();
	}

	async function openQuickAdd(): Promise<void> {
		quickAddOpen = true;
		await tick();
		quickAddDialog?.focus();
	}

	async function closeQuickAdd(): Promise<void> {
		quickAddOpen = false;
		await tick();
		quickAddTrigger?.focus();
	}

	function handleWindowKeydown(event: KeyboardEvent): void {
		if (event.key === 'Escape') {
			if (quickAddOpen) void closeQuickAdd();
			else if (drawerOpen) void closeDrawer();
			return;
		}

		if (event.key !== 'Tab') return;

		const container = quickAddOpen ? quickAddDialog : drawerOpen ? drawer : null;
		if (!container) return;

		const items = Array.from(
			container.querySelectorAll<HTMLElement>(
				'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
			)
		);
		if (items.length === 0) return;

		const first = items[0];
		const last = items[items.length - 1];
		if (document.activeElement === container) {
			event.preventDefault();
			(event.shiftKey ? last : first).focus();
		} else if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}

	async function signOut(): Promise<void> {
		signOutError = '';
		const token = csrfToken();
		if (!token) {
			signOutError = 'Reload this page and try again.';
			return;
		}

		try {
			const response = await fetch('/api/auth/logout', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token }
			});
			if (!response.ok) {
				signOutError = 'Sign-out is unavailable. Try again.';
				return;
			}

			currentUser = null;
			await goto('/login');
		} catch {
			signOutError = 'Sign-out is unavailable. Check your connection and try again.';
		}
	}
</script>

<svelte:head>
	<meta name="color-scheme" content="dark" />
</svelte:head>

<svelte:window onkeydown={handleWindowKeydown} />

<div class="app-shell min-h-screen bg-slate-950 text-slate-100 lg:flex">
	<aside class="hidden w-64 shrink-0 border-r border-slate-800 bg-slate-950 px-4 py-6 lg:flex lg:flex-col">
		<a href="/" class="mb-9 flex items-center gap-3 px-2 text-lg font-semibold tracking-tight text-white" aria-label="Metricove overview">
			<span class="grid size-9 place-items-center rounded-xl bg-cyan-300/10 text-lg text-cyan-300" aria-hidden="true">M</span>
			<span>Metric<span class="text-cyan-300">ove</span></span>
		</a>
		<nav aria-label="Main navigation" class="flex-1 space-y-7">
			<div class="space-y-1">
				<a href="/" aria-current={isActive('/') ? 'page' : undefined} class="nav-item {isActive('/') ? 'nav-item-active' : ''}">
					<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1V10Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" /></svg>
					Overview
				</a>
			</div>
			{#if modules.length > 0}
				<div>
					<p class="nav-section-label">Your modules</p>
					<div class="mt-2 space-y-1">
						{#each modules as module (module.key)}
							<a href={`/modules/${module.key}`} aria-current={isActive(`/modules/${module.key}`) ? 'page' : undefined} class="nav-item {isActive(`/modules/${module.key}`) ? 'nav-item-active' : ''}">
								<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><rect x="4" y="4" width="16" height="16" rx="3" stroke="currentColor" stroke-width="1.7" /><path d="M8 9h8M8 13h8M8 17h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" /></svg>
								{module.label}
							</a>
						{/each}
					</div>
				</div>
			{/if}
			{#if currentUser?.is_admin}
				<div class="border-t border-slate-800 pt-5">
					<p class="nav-section-label">Administration</p>
					<div class="mt-2 space-y-1">
						<a href="/admin/members" aria-current={isActive('/admin/members') ? 'page' : undefined} class="nav-item {isActive('/admin/members') ? 'nav-item-active' : ''}">
							<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-4A4.5 4.5 0 0 0 3 18.5V20M9.5 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM17 11a3 3 0 1 0 0-6M17 14h.5a3.5 3.5 0 0 1 3.5 3.5V20" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" /></svg>
							Members
						</a>
						<a href="/admin/modules" aria-current={isActive('/admin/modules') ? 'page' : undefined} class="nav-item {isActive('/admin/modules') ? 'nav-item-active' : ''}">
							<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><path d="M12 3 20 7.5v9L12 21l-8-4.5v-9L12 3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" /><path d="m4.5 7.8 7.5 4.4 7.5-4.4M12 12.5V21" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" /></svg>
							Modules
						</a>
						<a href="/admin/invitations" aria-current={isActive('/admin/invitations') ? 'page' : undefined} class="nav-item {isActive('/admin/invitations') ? 'nav-item-active' : ''}">
							<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.7" /><path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" /></svg>
							Invitations
						</a>
					</div>
				</div>
			{/if}
		</nav>
		<div class="mt-8 border-t border-slate-800 pt-4">
			<a href="/profile" class="flex min-w-0 items-center gap-3 rounded-xl px-3 py-3 transition hover:bg-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">
				<span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-800 text-sm font-semibold text-cyan-200" aria-hidden="true">{currentUser?.name.slice(0, 1).toUpperCase() ?? '…'}</span>
				<span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium">{currentUser?.name ?? 'Loading account'}</span><span class="mt-0.5 block text-xs text-slate-400">Profile & account</span></span>
			</a>
			<button type="button" onclick={signOut} class="mt-1 w-full rounded-xl px-3 py-2 text-left text-sm text-slate-400 transition hover:bg-slate-900 hover:text-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">Sign out</button>
		</div>
	</aside>

	<div class="min-w-0 flex-1">
		<header class="sticky top-0 z-20 flex min-h-16 items-center justify-between gap-4 border-b border-slate-800 bg-slate-950/95 px-4 backdrop-blur sm:px-7 lg:px-10">
			<div class="flex min-w-0 items-center gap-3">
				<button bind:this={drawerTrigger} type="button" aria-label="Open navigation" aria-expanded={drawerOpen} aria-controls="mobile-navigation" onclick={() => void openDrawer()} class="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-800 text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 lg:hidden">
					<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
				</button>
				<div class="min-w-0">
					<p class="truncate text-base font-semibold tracking-tight text-slate-100">{pageTitle}</p>
					<p class="hidden text-xs text-slate-500 sm:block">Your household, in one private place</p>
				</div>
			</div>
			<button bind:this={quickAddTrigger} type="button" disabled={loading || currentUser === null} onclick={() => void openQuickAdd()} aria-haspopup="dialog" class="inline-flex shrink-0 items-center gap-2 rounded-xl bg-cyan-300 px-3 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:cursor-wait disabled:opacity-60 sm:px-4">
				<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
				<span>Quick Add</span>
			</button>
		</header>

		<main id="main-content" class="mx-auto w-full max-w-screen-2xl px-4 py-6 sm:px-7 sm:py-8 lg:px-10 lg:py-10">
			<a href="#main-content" class="sr-only focus:not-sr-only focus:mb-5 focus:inline-flex focus:rounded-lg focus:bg-cyan-300 focus:px-3 focus:py-2 focus:text-sm focus:font-semibold focus:text-slate-950">Skip to content</a>
			{#if shellError}
				<div role="alert" class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-100">
					<p>{shellError}</p>
					<button type="button" onclick={() => void loadShell()} class="rounded-lg border border-rose-200/30 px-3 py-2 font-semibold hover:border-rose-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-200">Try again</button>
				</div>
			{/if}
			{#if signOutError}<p role="alert" class="mb-6 rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-100">{signOutError}</p>{/if}
			{@render children()}
		</main>
	</div>
</div>

{#if drawerOpen}
	<div class="fixed inset-0 z-40 lg:hidden">
		<button type="button" aria-label="Close navigation" class="absolute inset-0 bg-slate-950/75 backdrop-blur-sm" onclick={() => void closeDrawer()}></button>
		<div bind:this={drawer} id="mobile-navigation" role="dialog" aria-modal="true" aria-label="Main navigation" tabindex="-1" class="mobile-drawer absolute inset-y-0 left-0 flex w-[min(21rem,88vw)] flex-col border-r border-slate-800 bg-slate-950 px-4 py-5 shadow-2xl shadow-black/40 outline-none">
			<div class="mb-8 flex items-center justify-between px-2">
				<a href="/" aria-label="Metricove overview" onclick={() => (drawerOpen = false)} class="flex items-center gap-3 text-lg font-semibold tracking-tight text-white"><span class="grid size-9 place-items-center rounded-xl bg-cyan-300/10 text-cyan-300" aria-hidden="true">M</span><span>Metric<span class="text-cyan-300">ove</span></span></a>
				<button type="button" aria-label="Close navigation" onclick={() => void closeDrawer()} class="grid size-10 place-items-center rounded-xl border border-slate-800 text-slate-300 hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300"><span aria-hidden="true" class="text-xl">×</span></button>
			</div>
			<nav aria-label="Mobile main navigation" class="flex-1 space-y-7 overflow-y-auto">
				<div class="space-y-1">
					<a
						href="/"
						onclick={() => (drawerOpen = false)}
						aria-current={isActive('/') ? 'page' : undefined}
						class="nav-item {isActive('/') ? 'nav-item-active' : ''}"
					>
						<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5">
							<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1V10Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
						</svg>
						Overview
					</a>
				</div>
				{#if modules.length > 0}
					<div>
						<p class="nav-section-label">Your modules</p>
						<div class="mt-2 space-y-1">
							{#each modules as module (module.key)}
								<a
									href={`/modules/${module.key}`}
									onclick={() => (drawerOpen = false)}
									aria-current={isActive(`/modules/${module.key}`) ? 'page' : undefined}
									class="nav-item {isActive(`/modules/${module.key}`) ? 'nav-item-active' : ''}"
								>
									<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5">
										<rect x="4" y="4" width="16" height="16" rx="3" stroke="currentColor" stroke-width="1.7" />
										<path d="M8 9h8M8 13h8M8 17h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
									</svg>
									{module.label}
								</a>
							{/each}
						</div>
					</div>
				{/if}
				{#if currentUser?.is_admin}
					<div class="border-t border-slate-800 pt-5">
						<p class="nav-section-label">Administration</p>
						<div class="mt-2 space-y-1">
							<a
								href="/admin/members"
								onclick={() => (drawerOpen = false)}
								aria-current={isActive('/admin/members') ? 'page' : undefined}
								class="nav-item {isActive('/admin/members') ? 'nav-item-active' : ''}"
							>
								<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5">
									<path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-4A4.5 4.5 0 0 0 3 18.5V20M9.5 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM17 11a3 3 0 1 0 0-6M17 14h.5a3.5 3.5 0 0 1 3.5 3.5V20" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
								</svg>
								Members
							</a>
							<a
								href="/admin/modules"
								onclick={() => (drawerOpen = false)}
								aria-current={isActive('/admin/modules') ? 'page' : undefined}
								class="nav-item {isActive('/admin/modules') ? 'nav-item-active' : ''}"
							>
								<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5">
									<path d="M12 3 20 7.5v9L12 21l-8-4.5v-9L12 3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
									<path d="m4.5 7.8 7.5 4.4 7.5-4.4M12 12.5V21" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
								</svg>
								Modules
							</a>
							<a
								href="/admin/invitations"
								onclick={() => (drawerOpen = false)}
								aria-current={isActive('/admin/invitations') ? 'page' : undefined}
								class="nav-item {isActive('/admin/invitations') ? 'nav-item-active' : ''}"
							>
								<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5">
									<rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.7" />
									<path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
								</svg>
								Invitations
							</a>
						</div>
					</div>
				{/if}
			</nav>
			<div class="mt-6 border-t border-slate-800 pt-4"><a href="/profile" onclick={() => (drawerOpen = false)} class="block rounded-xl px-3 py-3 text-sm font-medium text-slate-100 hover:bg-slate-900">{currentUser?.name ?? 'Profile'}<span class="mt-1 block text-xs text-slate-400">Profile & account</span></a><button type="button" onclick={signOut} class="mt-1 w-full rounded-xl px-3 py-2 text-left text-sm text-slate-400 hover:bg-slate-900 hover:text-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">Sign out</button></div>
		</div>
	</div>
{/if}

{#if quickAddOpen}
	<div class="fixed inset-0 z-50 grid place-items-center p-4">
		<button type="button" aria-label="Close Quick Add" class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" onclick={() => void closeQuickAdd()}></button>
		<div bind:this={quickAddDialog} role="dialog" aria-modal="true" aria-labelledby="quick-add-title" tabindex="-1" class="relative w-full max-w-lg rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl shadow-black/50 outline-none sm:p-8">
			<div class="flex items-start justify-between gap-4">
				<div><p class="text-sm font-medium text-cyan-200">Quick Add</p><h2 id="quick-add-title" class="mt-2 text-2xl font-semibold tracking-tight">Add something to your household.</h2></div>
				<button type="button" aria-label="Close Quick Add" onclick={() => void closeQuickAdd()} class="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-700 text-slate-300 hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300"><span aria-hidden="true" class="text-xl">×</span></button>
			</div>
			<div class="mt-6 rounded-xl border border-slate-800 bg-slate-950/70 p-4">
				<p class="text-sm leading-6 text-slate-300">Quick Add actions will appear as the household modules add their entry forms. No data-entry forms are available yet, so there is nothing to save here today.</p>
				{#if modules.some((module) => module.capabilities.create)}<p class="mt-3 text-sm text-slate-400">You have create access to {modules.filter((module) => module.capabilities.create).map((module) => module.label).join(', ')}.</p>{/if}
			</div>
			<div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
				<button type="button" onclick={() => void closeQuickAdd()} class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-200 hover:border-slate-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">Close</button>
				{#if currentUser?.is_admin}<a href="/admin/modules" onclick={() => (quickAddOpen = false)} class="rounded-xl bg-cyan-300 px-4 py-2.5 text-center text-sm font-semibold text-slate-950 hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">Manage modules</a>{/if}
			</div>
		</div>
	</div>
{/if}
