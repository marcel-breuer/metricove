<script lang="ts">
	import { goto } from '$app/navigation';
	import { getContext, untrack } from 'svelte';
	import { notesRequest as api } from '#lib/api-client';
	import { formatDateTime, t } from '#lib/i18n';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import type { NoteFolder, PersonalNote, NotesResponse } from '#lib/notes';
	import Badge from '#lib/ui/Badge.svelte';
	import Button from '#lib/ui/Button.svelte';
	import EmptyState from '#lib/ui/EmptyState.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import Panel from '#lib/ui/Panel.svelte';
	import NoteEditor from './NoteEditor.svelte';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'notes'));
	const canView = $derived(access?.capabilities.view === true);
	const canCreate = $derived(access?.capabilities.create === true);
	const canEdit = $derived(access?.capabilities.edit === true);

	let notes = $state<PersonalNote[]>([]);
	let folders = $state<NoteFolder[]>([]);
	let pagination = $state({ current_page: 1, last_page: 1, per_page: 30, total: 0 });
	let search = $state('');
	let searchInput = $state('');
	let folderFilter = $state('');
	let statusFilter = $state<'active' | 'archived'>('active');
	let loading = $state(true);
	let error = $state('');
	let editorOpen = $state(false);
	let folderName = $state('');
	let folderEditId = $state<number | null>(null);
	let folderEditName = $state('');

	$effect(() => {
		if (!shell.loading && canView) {
			// Filters trigger their own reloads; only shell access changes should rerun this.
			untrack(() => {
				void loadFolders();
				void loadNotes();
			});
		} else if (!shell.loading) loading = false;
	});

	async function loadFolders(): Promise<void> {
		try {
			const result = await api<NotesResponse>('/api/notes/folders');
			folders = result.folders ?? [];
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Note folders could not be loaded.';
		}
	}

	async function loadNotes(): Promise<void> {
		loading = true;
		error = '';
		const query = new URLSearchParams({ status: statusFilter, page: String(pagination.current_page) });
		if (search.trim()) query.set('search', search.trim());
		if (folderFilter) query.set('folder_id', folderFilter);
		try {
			const result = await api<NotesResponse>('/api/notes?' + query.toString());
			notes = result.notes ?? [];
			pagination = result.pagination ?? { current_page: 1, last_page: 1, per_page: 30, total: 0 };
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Notes could not be loaded.';
		} finally {
			loading = false;
		}
	}

	async function noteCreated(note: PersonalNote | null): Promise<void> {
		if (note) await goto(`/modules/notes/${note.id}`);
		else await loadNotes();
	}

	async function saveFolder(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		try {
			if (folderEditId) {
				const folder = folders.find((item) => item.id === folderEditId);
				if (!folder) return;
				await api<NotesResponse>('/api/notes/folders/' + folder.id, 'PATCH', { name: folderEditName, expected_version: folder.version });
			} else {
				await api<NotesResponse>('/api/notes/folders', 'POST', { name: folderName });
			}
			folderName = '';
			folderEditId = null;
			folderEditName = '';
			await loadFolders();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The folder could not be saved.';
		}
	}

	async function deleteFolder(folder: NoteFolder): Promise<void> {
		try {
			await api<NotesResponse>('/api/notes/folders/' + folder.id, 'DELETE');
			if (folderFilter === String(folder.id)) folderFilter = '';
			await Promise.all([loadFolders(), loadNotes()]);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The folder could not be deleted.';
		}
	}

	function applySearch(event: SubmitEvent): void {
		event.preventDefault();
		search = searchInput.trim();
		pagination.current_page = 1;
		void loadNotes();
	}

	function setStatus(status: 'active' | 'archived'): void {
		statusFilter = status;
		pagination.current_page = 1;
		void loadNotes();
	}
</script>

<svelte:head>
	<title>{$t('Notes')} · Metricove</title>
	<meta name="description" content={$t('Keep private Markdown notes organized, searchable and selectively shared.')} />
</svelte:head>

<div class="space-y-6">
	<PageHeading eyebrow={$t('Household')} title={$t('Notes')} description={$t('Keep ideas and household knowledge in one organized place.')}>
		{#snippet actions()}
			{#if canView && canCreate}<Button onclick={() => (editorOpen = true)}>{$t('New note')}</Button>{/if}
		{/snippet}
	</PageHeading>
	{#if error}<Notice tone="error" message={error} />{/if}

	{#if !shell.loading && !canView}
		<Panel><h2 class="text-lg font-semibold">{$t('Notes are not available')}</h2><p class="mt-2 text-sm leading-6 text-[var(--text-muted)]">{$t('Ask a household administrator to enable Notes and grant your account View access.')}</p></Panel>
	{:else}
		<div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
			<Panel>
				<h2 class="text-lg font-semibold">{$t('Your notebook')}</h2>
				<div class="mt-4 grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_12rem] md:items-end">
					<form class="flex gap-2" onsubmit={applySearch}><label class="sr-only" for="note-search">{$t('Search notes')}</label><input id="note-search" bind:value={searchInput} placeholder={$t('Search title, text or tags')} class="ui-control min-w-0 flex-1" /><Button type="submit" variant="secondary">{$t('Search')}</Button></form>
					<label class="ui-field gap-1"><span class="text-xs text-[var(--text-muted)]">{$t('Folder')}</span><select bind:value={folderFilter} onchange={() => { pagination.current_page = 1; void loadNotes(); }} class="ui-control"><option value="">{$t('All folders')}</option>{#each folders as folder (folder.id)}<option value={String(folder.id)}>{folder.name}</option>{/each}</select></label>
					<label class="ui-field gap-1"><span class="text-xs text-[var(--text-muted)]">{$t('Show')}</span><select value={statusFilter} onchange={(event) => setStatus(event.currentTarget.value === 'archived' ? 'archived' : 'active')} class="ui-control"><option value="active">{$t('Active notes')}</option><option value="archived">{$t('Archived notes')}</option></select></label>
				</div>
				{#if loading}<p class="mt-5 text-sm text-[var(--text-muted)]" role="status">{$t('Loading notes…')}</p>
				{:else if notes.length === 0}<EmptyState title="No notes match your search." class="mt-5" />
				{:else}
					<ul class="mt-5 grid gap-3 md:grid-cols-2">
						{#each notes as note (note.id)}
							<li>
								<a href="/modules/notes/{note.id}" class="note-card flex h-full flex-col rounded-xl border border-[var(--border-default)] px-4 py-3">
									<span class="flex items-center justify-between gap-2"><span class="truncate font-medium text-[var(--text-primary)]">{note.title}</span>{#if note.shared_with_household}<Badge tone="accent" class="shrink-0">{$t('Shared')}</Badge>{/if}</span>
									<span class="mt-1 line-clamp-2 text-sm leading-5 text-[var(--text-muted)]">{note.preview || $t('No preview text.')}</span>
									<span class="mt-auto flex flex-wrap items-center gap-1.5 pt-2">
										{#each note.tags as tag (tag.id)}<Badge>{tag.name}</Badge>{/each}
										{#if note.updated_at}<span class="ml-auto text-xs text-[var(--text-subtle)]">{formatDateTime(note.updated_at)}</span>{/if}
									</span>
								</a>
							</li>
						{/each}
					</ul>
					<div class="mt-4 flex items-center justify-between gap-2 text-xs text-[var(--text-muted)]"><span>{$t('Showing {count} notes', { count: pagination.total })}</span><div class="flex gap-2"><Button size="small" variant="ghost" disabled={pagination.current_page <= 1} onclick={() => { pagination.current_page--; void loadNotes(); }}>{$t('Previous')}</Button><Button size="small" variant="ghost" disabled={pagination.current_page >= pagination.last_page} onclick={() => { pagination.current_page++; void loadNotes(); }}>{$t('Next')}</Button></div></div>
				{/if}
			</Panel>

			{#if canCreate || canEdit}
				<Panel class="h-fit">
					<h2 class="text-sm font-semibold">{$t('Private folders')}</h2>
					{#if canCreate}<form class="mt-3 flex gap-2" onsubmit={saveFolder}><label class="sr-only" for="note-folder-name">{$t('Folder name')}</label><input id="note-folder-name" bind:value={folderName} placeholder={$t('New folder')} maxlength="80" class="ui-control min-w-0 flex-1" /><Button size="small" type="submit">{$t('Add')}</Button></form>{/if}
					{#if folderEditId}<form class="mt-3 flex flex-wrap gap-2" onsubmit={saveFolder}><label class="sr-only" for="note-folder-edit">{$t('Folder name')}</label><input id="note-folder-edit" bind:value={folderEditName} maxlength="80" required class="ui-control min-w-0 flex-1" /><Button size="small" type="submit">{$t('Rename')}</Button><Button size="small" variant="ghost" type="button" onclick={() => { folderEditId = null; folderEditName = ''; }}>{$t('Cancel')}</Button></form>{/if}
					<ul class="mt-3 space-y-1">{#each folders as folder (folder.id)}<li class="flex items-center justify-between gap-2 rounded-lg py-1.5 text-sm text-[var(--text-secondary)]"><span class="truncate">{folder.name} <span class="text-xs text-[var(--text-subtle)]">({folder.note_count})</span></span>{#if canEdit}<span class="flex gap-1"><Button size="small" variant="ghost" onclick={() => { folderEditId = folder.id; folderEditName = folder.name; }}>{$t('Rename')}</Button><Button size="small" variant="ghost" onclick={() => void deleteFolder(folder)}>{$t('Remove')}</Button></span>{/if}</li>{/each}</ul>
				</Panel>
			{/if}
		</div>
	{/if}
	{#if canCreate}<NoteEditor bind:open={editorOpen} {folders} onSaved={(note) => noteCreated(note)} />{/if}
</div>

<style>
	.note-card { background: var(--surface-raised); transition: border-color 150ms ease; }
	.note-card:hover { border-color: var(--border-strong); }
	.note-card:focus-visible { outline: 2px solid var(--accent-text); outline-offset: 2px; }
</style>
