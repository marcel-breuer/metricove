<script lang="ts">
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { onMount, setContext, tick } from 'svelte';
	import type { LayoutProps } from './$types';
	import { csrfToken, type ApiResponse, type AuthUser } from '#lib/auth';
	import Button from '#lib/ui/Button.svelte';
	import { setUserPreferences, t } from '#lib/i18n';
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
	let assistantSearch = $state('');
	let drawer: HTMLElement | null = $state(null);
	let drawerTrigger: HTMLButtonElement | null = $state(null);

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
	const pageTitle = $derived($t(titleForPath(pathname)));
	const trackingModule = $derived(modules.find((module) => module.key === 'tracking'));
	const canCreateTracker = $derived(
		trackingModule?.capabilities.create === true ||
		(currentUser?.is_admin === true && trackingModule?.capabilities.manage === true)
	);

	onMount(() => {
		void loadShell();
	});

	function titleForPath(path: string): string {
		if (path === '/') return 'Overview';
		if (path === '/profile') return 'Profile';
		if (path === '/reminders') return 'Reminders';
		if (path === '/admin/members') return 'Members';
		if (path.startsWith('/admin/members/')) return 'Module rights';
		if (path === '/admin/modules') return 'Modules';
		if (path === '/admin/settings') return 'General settings';
		if (path === '/admin/invitations') return 'Invitations';
		if (path === '/assistant') return 'Assistant';
		if (path === '/mcp-docs') return 'MCP documentation';
		if (path === '/modules/tracking') return 'Tracking';
		if (path.startsWith('/modules/tracking/')) return 'Tracker history';
		if (path.startsWith('/modules/')) {
			const moduleKey = path.split('/')[2];
			return modules.find((module) => module.key === moduleKey)?.label ?? 'Module';
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
			if (setupResponse.status === 403) throw new Error('This network is not allowed to access this installation.');
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
			if (sessionResponse.status === 403) throw new Error('This network is not allowed to access this installation.');
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
			setUserPreferences(session.user);

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

	function submitAssistantSearch(event: SubmitEvent): void {
		event.preventDefault();
		const question = assistantSearch.trim();
		assistantSearch = '';
		drawerOpen = false;
		void goto('/assistant', {
			state: question
				? { assistantQuestion: question, assistantSearchRequestId: crypto.randomUUID() }
				: {}
		});
	}

	function handleWindowKeydown(event: KeyboardEvent): void {
		if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
			event.preventDefault();
			drawerOpen = false;
			void goto('/assistant');
			return;
		}

		if (event.key === 'Escape') {
			if (drawerOpen) void closeDrawer();
			return;
		}

		if (event.key !== 'Tab') return;

		const container = drawerOpen ? drawer : null;
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
	<meta name="color-scheme" content="light dark" />
</svelte:head>

<svelte:window onkeydown={handleWindowKeydown} />

<div class="app-shell min-h-screen bg-slate-950 text-slate-100 lg:flex">
	<aside class="hidden w-64 shrink-0 border-r border-slate-800 bg-slate-950 px-4 py-6 lg:flex lg:flex-col">
		<a href="/" class="mb-9 flex items-center gap-3 px-2 text-lg font-semibold tracking-tight text-white" aria-label={$t("Metricove overview")}>
			<span class="grid size-9 place-items-center rounded-xl bg-cyan-300/10 text-lg text-cyan-300" aria-hidden="true">M</span>
			<span>Metric<span class="text-cyan-300">ove</span></span>
		</a>
		<nav aria-label={$t("Main navigation")} class="flex-1 space-y-7">
			<div class="space-y-1">
				<a href="/" aria-current={isActive('/') ? 'page' : undefined} class="nav-item {isActive('/') ? 'nav-item-active' : ''}">
					<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1V10Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" /></svg>
					{$t('Overview')}
				</a>
				<a href="/reminders" aria-current={isActive('/reminders') ? 'page' : undefined} class="nav-item {isActive('/reminders') ? 'nav-item-active' : ''}">{$t('Reminders')}</a>
				<a href="/mcp-docs" aria-current={isActive('/mcp-docs') ? 'page' : undefined} class="nav-item {isActive('/mcp-docs') ? 'nav-item-active' : ''}">{$t('MCP documentation')}</a>
			</div>
			{#if modules.length > 0}
				<div>
					<p class="nav-section-label">{$t('Your modules')}</p>
					<div class="mt-2 space-y-1">
						{#each modules as module (module.key)}
							<a href={`/modules/${module.key}`} aria-current={isActive(`/modules/${module.key}`) ? 'page' : undefined} class="nav-item {isActive(`/modules/${module.key}`) ? 'nav-item-active' : ''}">
								<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><rect x="4" y="4" width="16" height="16" rx="3" stroke="currentColor" stroke-width="1.7" /><path d="M8 9h8M8 13h8M8 17h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" /></svg>
								{$t(module.label)}
							</a>
						{/each}
					</div>
				</div>
			{/if}
			{#if currentUser?.is_admin}
				<div class="border-t border-slate-800 pt-5">
					<p class="nav-section-label">{$t('Administration')}</p>
					<div class="mt-2 space-y-1">
						<a href="/admin/members" aria-current={isActive('/admin/members') ? 'page' : undefined} class="nav-item {isActive('/admin/members') ? 'nav-item-active' : ''}">
							<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-4A4.5 4.5 0 0 0 3 18.5V20M9.5 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM17 11a3 3 0 1 0 0-6M17 14h.5a3.5 3.5 0 0 1 3.5 3.5V20" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" /></svg>
							{$t('Members')}
						</a>
						<a href="/admin/modules" aria-current={isActive('/admin/modules') ? 'page' : undefined} class="nav-item {isActive('/admin/modules') ? 'nav-item-active' : ''}">
							<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><path d="M12 3 20 7.5v9L12 21l-8-4.5v-9L12 3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" /><path d="m4.5 7.8 7.5 4.4 7.5-4.4M12 12.5V21" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" /></svg>
							{$t('Modules')}
						</a>
						<a href="/admin/settings" aria-current={isActive('/admin/settings') ? 'page' : undefined} class="nav-item {isActive('/admin/settings') ? 'nav-item-active' : ''}">
							<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Z" stroke="currentColor" stroke-width="1.7"/><path d="m19.4 15 .1.1a1.8 1.8 0 0 1-2.5 2.5l-.1-.1a1.8 1.8 0 0 0-3 .9v.2a1.8 1.8 0 0 1-3.6 0v-.2a1.8 1.8 0 0 0-3-.9l-.1.1a1.8 1.8 0 0 1-2.5-2.5l.1-.1a1.8 1.8 0 0 0-.9-3h-.2a1.8 1.8 0 0 1 0-3.6h.2a1.8 1.8 0 0 0 .9-3l-.1-.1a1.8 1.8 0 0 1 2.5-2.5l.1.1a1.8 1.8 0 0 0 3-.9v-.2a1.8 1.8 0 0 1 3.6 0v.2a1.8 1.8 0 0 0 3 .9l.1-.1a1.8 1.8 0 0 1 2.5 2.5l-.1.1a1.8 1.8 0 0 0 .9 3h.2a1.8 1.8 0 0 1 0 3.6h-.2a1.8 1.8 0 0 0-.9 3Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
							{$t('General settings')}
						</a>
						<a href="/admin/invitations" aria-current={isActive('/admin/invitations') ? 'page' : undefined} class="nav-item {isActive('/admin/invitations') ? 'nav-item-active' : ''}">
							<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.7" /><path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" /></svg>
							{$t('Invitations')}
						</a>
					</div>
				</div>
			{/if}
		</nav>
		<div class="mt-8 border-t border-slate-800 pt-4">
			<a href="/profile" class="flex min-w-0 items-center gap-3 rounded-xl px-3 py-3 transition hover:bg-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">
				<span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-800 text-sm font-semibold text-cyan-200" aria-hidden="true">{currentUser?.name.slice(0, 1).toUpperCase() ?? '…'}</span>
				<span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium">{currentUser?.name ?? $t('Loading account')}</span><span class="mt-0.5 block text-xs text-slate-400">{$t('Profile & account')}</span></span>
			</a>
			<Button variant="ghost" size="small" onclick={signOut} class="mt-1 w-full justify-start">{$t('Sign out')}</Button>
		</div>
	</aside>

	<div class="min-w-0 flex-1">
		<header class="sticky top-0 z-20 flex min-h-16 items-center justify-between gap-4 border-b border-slate-800 bg-slate-950/95 px-4 backdrop-blur sm:px-7 lg:px-10">
			<div class="flex min-w-0 items-center gap-3">
				<button bind:this={drawerTrigger} type="button" aria-label={$t("Open navigation")} aria-expanded={drawerOpen} aria-controls="mobile-navigation" onclick={() => void openDrawer()} class="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-800 text-slate-200 transition hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 lg:hidden">
					<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
				</button>
				<div class="min-w-0">
					<p class="truncate text-base font-semibold tracking-tight text-slate-100">{pageTitle}</p>
					<p class="hidden text-xs text-slate-500 sm:block">{$t('Your household, in one private place')}</p>
				</div>
			</div>
			<form
				role="search"
				onsubmit={submitAssistantSearch}
				class="mx-auto flex min-w-0 flex-1 items-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 focus-within:border-cyan-300 focus-within:ring-2 focus-within:ring-cyan-300/20 sm:max-w-2xl"
			>
				<label for="header-assistant-search" class="sr-only">{$t('Search your household with AI')}</label>
				<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5 shrink-0 text-slate-400">
					<circle cx="10.8" cy="10.8" r="6.8" stroke="currentColor" stroke-width="1.8" />
					<path d="m16 16 4.2 4.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
				</svg>
				<input
					id="header-assistant-search"
					type="search"
					bind:value={assistantSearch}
					maxlength="4000"
					disabled={loading || currentUser === null}
					aria-keyshortcuts="Meta+K Control+K"
					placeholder={$t('Search your household with AI…')}
					class="min-w-0 flex-1 bg-transparent text-sm text-slate-100 outline-none placeholder:text-slate-500 disabled:cursor-not-allowed"
				/>
				<kbd aria-hidden="true" class="hidden shrink-0 rounded border border-slate-700 px-1.5 py-0.5 text-[0.65rem] text-slate-400 md:inline">⌘ K</kbd>
				<button
					type="submit"
					disabled={loading || currentUser === null}
					class="grid size-10 shrink-0 place-items-center rounded-lg bg-cyan-300 text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:cursor-not-allowed disabled:opacity-50"
					aria-label={$t('Search with AI')}
				>
					<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4"><path d="m5 12 14 0m-6-6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
				</button>
			</form>
			{#if canCreateTracker}
				<a href="/modules/tracking?create=1" aria-label={$t('Create tracker')} class="inline-flex min-h-10 shrink-0 items-center gap-2 rounded-lg bg-cyan-300 px-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-200 sm:px-4">
					<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
					<span class="hidden sm:inline">{$t('Create tracker')}</span>
					<span class="sm:hidden">{$t('Create')}</span>
				</a>
			{/if}
		</header>

		<main id="main-content" class="mx-auto w-full max-w-screen-2xl px-4 py-6 sm:px-7 sm:py-8 lg:px-10 lg:py-10">
			<a href="#main-content" class="sr-only focus:not-sr-only focus:mb-5 focus:inline-flex focus:rounded-lg focus:bg-cyan-300 focus:px-3 focus:py-2 focus:text-sm focus:font-semibold focus:text-slate-950">{$t('Skip to content')}</a>
			{#if shellError}
				<div role="alert" class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-100">
					<p>{$t(shellError)}</p>
					<button type="button" onclick={() => void loadShell()} class="rounded-lg border border-rose-200/30 px-3 py-2 font-semibold hover:border-rose-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-200">{$t('Try again')}</button>
				</div>
			{/if}
			{#if signOutError}<p role="alert" class="mb-6 rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-100">{$t(signOutError)}</p>{/if}
			{#if loading}
				<p role="status" class="text-sm text-slate-400">{$t('Loading account')}…</p>
			{:else if !shellError && currentUser}
				{@render children()}
			{/if}
		</main>
	</div>
</div>

{#if drawerOpen}
	<div class="fixed inset-0 z-40 lg:hidden">
		<button type="button" aria-label={$t("Close navigation")} class="absolute inset-0 bg-slate-950/75 backdrop-blur-sm" onclick={() => void closeDrawer()}></button>
		<div bind:this={drawer} id="mobile-navigation" role="dialog" aria-modal="true" aria-label={$t("Main navigation")} tabindex="-1" class="mobile-drawer absolute inset-y-0 left-0 flex w-[min(21rem,88vw)] flex-col border-r border-slate-800 bg-slate-950 px-4 py-5 shadow-2xl shadow-black/40 outline-none">
			<div class="mb-8 flex items-center justify-between px-2">
				<a href="/" aria-label={$t("Metricove overview")} onclick={() => (drawerOpen = false)} class="flex items-center gap-3 text-lg font-semibold tracking-tight text-white"><span class="grid size-9 place-items-center rounded-xl bg-cyan-300/10 text-cyan-300" aria-hidden="true">M</span><span>Metric<span class="text-cyan-300">ove</span></span></a>
				<button type="button" aria-label={$t("Close navigation")} onclick={() => void closeDrawer()} class="grid size-10 place-items-center rounded-xl border border-slate-800 text-slate-300 hover:border-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300"><span aria-hidden="true" class="text-xl">×</span></button>
			</div>
			<nav aria-label={$t("Mobile main navigation")} class="flex-1 space-y-7 overflow-y-auto">
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
						{$t('Overview')}
					</a>
					<a href="/mcp-docs" onclick={() => (drawerOpen = false)} aria-current={isActive('/mcp-docs') ? 'page' : undefined} class="nav-item {isActive('/mcp-docs') ? 'nav-item-active' : ''}">{$t('MCP documentation')}</a>
					<a href="/reminders" onclick={() => (drawerOpen = false)} aria-current={isActive('/reminders') ? 'page' : undefined} class="nav-item {isActive('/reminders') ? 'nav-item-active' : ''}">{$t('Reminders')}</a>
				</div>
				{#if modules.length > 0}
					<div>
						<p class="nav-section-label">{$t("Your modules")}</p>
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
								{$t(module.label)}
								</a>
							{/each}
						</div>
					</div>
				{/if}
				{#if currentUser?.is_admin}
					<div class="border-t border-slate-800 pt-5">
						<p class="nav-section-label">{$t("Administration")}</p>
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
								{$t("Members")}
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
								{$t("Modules")}
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
								{$t("Invitations")}
							</a>
			<a href="/admin/settings" onclick={() => (drawerOpen = false)} aria-current={isActive('/admin/settings') ? 'page' : undefined} class="nav-item {isActive('/admin/settings') ? 'nav-item-active' : ''}">
				<span aria-hidden="true">⚙</span>{$t("General settings")}
			</a>
						</div>
					</div>
				{/if}
			</nav>
			<div class="mt-6 border-t border-slate-800 pt-4"><a href="/profile" onclick={() => (drawerOpen = false)} class="block rounded-xl px-3 py-3 text-sm font-medium text-slate-100 hover:bg-slate-900">{currentUser?.name ?? $t('Profile')}<span class="mt-1 block text-xs text-slate-400">{$t("Profile & account")}</span></a><button type="button" onclick={signOut} class="mt-1 w-full rounded-xl px-3 py-2 text-left text-sm text-slate-400 hover:bg-slate-900 hover:text-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300">{$t("Sign out")}</button></div>
		</div>
	</div>
{/if}
