<script lang="ts">
	import { iconButton } from '#lib/ui/styles';
	import { page } from '$app/state';
	import { afterNavigate, goto } from '$app/navigation';
	import { onMount, setContext } from 'svelte';
	import ChevronUp from '@lucide/svelte/icons/chevron-up';
	import Menu from '@lucide/svelte/icons/menu';
	import X from '@lucide/svelte/icons/x';
	import type { LayoutProps } from './$types';
	import { csrfToken, type ApiResponse, type AuthUser } from '#lib/auth';
	import * as DropdownMenu from '#lib/components/ui/dropdown-menu/index.js';
	import * as Sheet from '#lib/components/ui/sheet/index.js';
	import Button from '#lib/ui/Button.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import ModuleIcon from '#lib/ui/ModuleIcon.svelte';
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
	const accountItemClass = 'min-h-11 cursor-pointer rounded-[10px] px-3 text-ink-secondary focus:bg-surface-subtle focus:text-ink';

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

	type NavLink = { href: string; label: string; icon: string };

	const generalLinks: NavLink[] = [
		{ href: '/', label: 'Overview', icon: 'overview' },
		{ href: '/reminders', label: 'Reminders', icon: 'reminders' },
		{ href: '/mcp-docs', label: 'MCP documentation', icon: 'mcp' }
	];
	const adminLinks: NavLink[] = [
		{ href: '/admin/members', label: 'Members', icon: 'members' },
		{ href: '/admin/modules', label: 'Modules', icon: 'modules' },
		{ href: '/admin/settings', label: 'General settings', icon: 'settings' },
		{ href: '/admin/invitations', label: 'Invitations', icon: 'invitations' }
	];

	const pathname = $derived(page.url.pathname);
	const moduleLinks = $derived<NavLink[]>(modules.map((module) => ({ href: `/modules/${module.key}`, label: module.label, icon: module.key })));
	const pageTitle = $derived($t(titleForPath(pathname)));
	const trackingModule = $derived(modules.find((module) => module.key === 'tracking'));
	const canCreateTracker = $derived(
		trackingModule?.capabilities.create === true ||
		(currentUser?.is_admin === true && trackingModule?.capabilities.manage === true)
	);

	onMount(() => {
		void loadShell();
		const desktop = window.matchMedia('(min-width: 64rem)');
		function closeDrawerOnDesktop(event: MediaQueryListEvent): void {
			if (event.matches) drawerOpen = false;
		}
		desktop.addEventListener('change', closeDrawerOnDesktop);
		return () => desktop.removeEventListener('change', closeDrawerOnDesktop);
	});
	afterNavigate(() => {
		drawerOpen = false;
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
			drawerOpen = false;
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

{#snippet brand()}
	<a href="/" class="flex items-center gap-3 px-2 text-lg font-semibold tracking-tight text-ink" aria-label={$t('Metricove overview')}>
		<span class="grid size-9 place-items-center rounded-xl bg-[color-mix(in_srgb,var(--color-accent)_12%,transparent)] text-lg text-accent-ink" aria-hidden="true">M</span>
		<span>Metric<span class="text-accent-ink">ove</span></span>
	</a>
{/snippet}

{#snippet navLinks(links: NavLink[])}
	<div class="space-y-1">
		{#each links as link (link.href)}
			<a href={link.href} aria-current={isActive(link.href) ? 'page' : undefined} class="relative flex min-h-11 items-center gap-3 rounded-[10px] px-3 py-2.5 text-sm font-medium transition-colors {isActive(link.href) ? 'bg-accent-ink/10 text-accent-ink before:absolute before:left-0 before:h-[1.6rem] before:w-[3px] before:rounded-r-full before:bg-accent' : 'text-ink-secondary hover:bg-surface-subtle hover:text-ink'}">
				<ModuleIcon name={link.icon} />
				<span class="min-w-0 truncate">{$t(link.label)}</span>
			</a>
		{/each}
	</div>
{/snippet}

{#snippet navigation(label: string)}
	<nav aria-label={$t(label)} class="min-h-0 flex-1 space-y-6 overflow-y-auto">
		{@render navLinks(generalLinks)}
		{#if moduleLinks.length > 0}
			<div>
				<p class="px-3 text-xs font-semibold tracking-[0.01em] text-ink-muted">{$t('Your modules')}</p>
				<div class="mt-2">{@render navLinks(moduleLinks)}</div>
			</div>
		{/if}
		{#if currentUser?.is_admin}
			<div class="border-t border-line pt-5">
				<p class="px-3 text-xs font-semibold tracking-[0.01em] text-ink-muted">{$t('Administration')}</p>
				<div class="mt-2">{@render navLinks(adminLinks)}</div>
			</div>
		{/if}
	</nav>
{/snippet}

{#snippet account(inDrawer = false)}
	<div class="mt-6 border-t border-line pt-4">
		{#if inDrawer && signOutError}
			<Notice tone="error" message={signOutError} class="mb-3" />
		{/if}
		<DropdownMenu.Root>
			<DropdownMenu.Trigger class="flex w-full min-w-0 cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors hover:bg-surface-subtle focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink">
				<span class="grid size-9 shrink-0 place-items-center rounded-full bg-surface-subtle text-sm font-semibold text-accent-ink" aria-hidden="true">{currentUser?.name.slice(0, 1).toUpperCase() ?? '…'}</span>
				<span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-ink">{currentUser?.name ?? $t('Loading account')}</span><span class="mt-0.5 block text-xs text-ink-muted">{$t('Profile & account')}</span></span>
				<ChevronUp class="size-4 shrink-0 text-ink-muted" aria-hidden="true" />
			</DropdownMenu.Trigger>
			<DropdownMenu.Content variant="glass" side="top" sideOffset={8} loop portalProps={{ disabled: inDrawer }}>
				<DropdownMenu.Group>
					<DropdownMenu.Item class={accountItemClass}>
						{#snippet child({ props })}
							<a {...props} href="/profile" aria-current={isActive('/profile') ? 'page' : undefined}>{$t('Profile & account')}</a>
						{/snippet}
					</DropdownMenu.Item>
					<DropdownMenu.Item class={accountItemClass} onSelect={() => void signOut()}>{$t('Sign out')}</DropdownMenu.Item>
				</DropdownMenu.Group>
			</DropdownMenu.Content>
		</DropdownMenu.Root>
	</div>
{/snippet}

<div class="app-shell atmospheric-page min-h-screen text-ink lg:flex">
	<aside class="glass-chrome sticky top-0 hidden h-screen w-64 shrink-0 border-r px-4 py-6 lg:flex lg:flex-col">
		<div class="mb-8">{@render brand()}</div>
		{@render navigation('Main navigation')}
		{@render account()}
	</aside>

	<div class="min-w-0 flex-1">
		<header class="sticky top-0 z-20 border-b glass-chrome px-4 sm:px-7 lg:px-10">
			<div class="flex min-h-16 flex-wrap items-center gap-x-4 gap-y-3 py-3 sm:flex-nowrap">
				<div class="flex min-w-0 flex-1 items-center gap-3 sm:flex-none">
					<Sheet.Root bind:open={drawerOpen}>
						<Sheet.Trigger aria-label={$t('Open navigation')} class="{iconButton} lg:hidden">
							<Menu aria-hidden="true" class="size-5" strokeWidth={1.7} />
						</Sheet.Trigger>
						<Sheet.Content variant="glass" side="left" showCloseButton={false} class="gap-0 px-4 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))] data-[side=left]:w-[min(20rem,88vw)] motion-safe:animate-drawer-in">
							<Sheet.Title class="sr-only">{$t('Main navigation')}</Sheet.Title>
							<div class="mb-6 flex items-center justify-between">
								{@render brand()}
								<Sheet.Close aria-label={$t('Close navigation')} class={iconButton}>
									<X aria-hidden="true" class="size-5" strokeWidth={1.7} />
								</Sheet.Close>
							</div>
							{@render navigation('Mobile main navigation')}
							{@render account(true)}
						</Sheet.Content>
					</Sheet.Root>
					<div class="min-w-0">
						<p class="truncate text-base font-semibold tracking-tight text-ink">{pageTitle}</p>
						<p class="hidden text-xs text-ink-muted xl:block">{$t('Your household, in one private place')}</p>
					</div>
				</div>
				{#if canCreateTracker}
					<Button href="/modules/tracking?create=1" aria-label={$t('Create tracker')} class="shrink-0 sm:order-last">
						<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
						<span>{$t('Create tracker')}</span>
					</Button>
				{/if}
				<form
					role="search"
					onsubmit={submitAssistantSearch}
					class="flex w-full min-w-0 items-center gap-2 rounded-xl border border-control-line bg-control py-1 pr-1 pl-3 focus-within:border-accent-ink sm:mx-auto sm:w-auto sm:max-w-2xl sm:flex-1"
				>
					<label for="header-assistant-search" class="sr-only">{$t('Search your household with AI')}</label>
					<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-5 shrink-0 text-ink-muted">
						<circle cx="10.8" cy="10.8" r="6.8" stroke="currentColor" stroke-width="1.7" />
						<path d="m16 16 4.2 4.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
					</svg>
					<input
						id="header-assistant-search"
						type="search"
						bind:value={assistantSearch}
						maxlength="4000"
						disabled={loading || currentUser === null}
						aria-keyshortcuts="Meta+K Control+K"
						placeholder={$t('Search your household with AI…')}
						class="min-h-9 min-w-0 flex-1 bg-transparent text-sm text-ink outline-none placeholder:text-ink-subtle disabled:cursor-not-allowed max-sm:text-base"
					/>
					<kbd aria-hidden="true" class="hidden shrink-0 rounded border border-line px-1.5 py-0.5 text-[0.6875rem] text-ink-muted md:inline">⌘ K</kbd>
					<button
						type="submit"
						disabled={loading || currentUser === null}
						class="grid size-9 shrink-0 place-items-center rounded-lg bg-accent text-on-accent transition-[filter] hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink disabled:cursor-not-allowed disabled:opacity-50"
						aria-label={$t('Search with AI')}
					>
						<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4"><path d="m5 12 14 0m-6-6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
					</button>
				</form>
			</div>
		</header>

		<main id="main-content" class="mx-auto w-full max-w-screen-2xl px-4 py-6 sm:px-7 sm:py-8 lg:px-10 lg:py-10">
			<a href="#main-content" class="sr-only focus:not-sr-only focus:mb-5 focus:inline-flex focus:rounded-lg focus:bg-accent focus:px-3 focus:py-2 focus:text-sm focus:font-semibold focus:text-on-accent">{$t('Skip to content')}</a>
			{#if shellError}
				<div role="alert" class="rounded-xl border border-danger/30 bg-danger/9 px-4 py-3 text-sm leading-normal text-danger mb-6 flex flex-wrap items-center justify-between gap-3">
					<p>{$t(shellError)}</p>
					<Button variant="secondary" size="small" onclick={() => void loadShell()}>{$t('Try again')}</Button>
				</div>
			{/if}
			{#if signOutError && !drawerOpen}<Notice tone="error" message={signOutError} class="mb-6" />{/if}
			{#if loading}
				<p role="status" class="text-sm text-ink-muted">{$t('Loading account')}…</p>
			{:else if !shellError && currentUser}
				{@render children()}
			{/if}
		</main>
	</div>
</div>
