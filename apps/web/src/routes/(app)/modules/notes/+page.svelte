<script lang="ts">
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { formatDateTime, t } from '#lib/i18n';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { parseNoteTags, type NoteFolder, type PersonalNote, type NotesResponse } from '#lib/notes';
	import Button from '#lib/ui/Button.svelte';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import Panel from '#lib/ui/Panel.svelte';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'notes'));
	const canView = $derived(access?.capabilities.view === true);
	const canCreate = $derived(access?.capabilities.create === true);
	const canEdit = $derived(access?.capabilities.edit === true);
	const canManage = $derived(access?.capabilities.manage === true);

	let notes = $state<PersonalNote[]>([]);
	let folders = $state<NoteFolder[]>([]);
	let selected = $state<PersonalNote | null>(null);
	let pagination = $state({ current_page: 1, last_page: 1, per_page: 30, total: 0 });
	let search = $state('');
	let searchInput = $state('');
	let folderFilter = $state('');
	let statusFilter = $state<'active' | 'archived'>('active');
	let loading = $state(true);
	let saving = $state(false);
	let error = $state('');
	let message = $state('');
	let editing = $state(false);
	let title = $state('');
	let body = $state('');
	let draftHtml = $state('');
	let previewTimeout: ReturnType<typeof setTimeout> | undefined;
	let tagsText = $state('');
	let folderId = $state('');
	let folderName = $state('');
	let folderEditId = $state<number | null>(null);
	let folderEditName = $state('');
	let deleteTarget = $state<PersonalNote | null>(null);
	let deleteOpen = $state(false);

	$effect(() => {
		if (!shell.loading && canView) {
			void loadFolders();
			void loadNotes();
		} else if (!shell.loading) loading = false;
	});

	async function api<T>(path: string, method = 'GET', payload?: object): Promise<T> {
		const headers: Record<string, string> = { Accept: 'application/json' };
		if (method !== 'GET') {
			const token = csrfToken();
			if (!token) throw new Error('Reload this page and try again.');
			headers['X-XSRF-TOKEN'] = token;
			headers['Content-Type'] = 'application/json';
		}
		const response = await fetch(path, {
			method,
			credentials: 'same-origin',
			headers,
			...(payload ? { body: JSON.stringify(payload) } : {})
		});
		if (response.status === 401) {
			await goto('/login');
			throw new Error('Your session has expired.');
		}
		const result = (await response.json()) as T & ApiResponse;
		if (!response.ok) throw new Error(firstError(result) || result.message || 'Notes could not complete that request.');
		return result as T;
	}

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
			if (selected && !notes.some((note) => note.id === selected?.id)) selected = null;
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Notes could not be loaded.';
		} finally {
			loading = false;
		}
	}

	function resetEditor(): void {
		selected = null;
		editing = true;
		title = '';
		body = '';
		draftHtml = '';
		tagsText = '';
		folderId = '';
		message = '';
		error = '';
	}

	async function openNote(note: PersonalNote): Promise<void> {
		error = '';
		message = '';
		try {
			const result = await api<NotesResponse>('/api/notes/' + note.id);
			selected = result.note ?? null;
			if (selected?.can_edit) {
				title = selected.title;
				body = selected.body ?? '';
				tagsText = selected.tags.map((tag) => tag.name).join(', ');
				folderId = selected.folder ? String(selected.folder.id) : '';
			}
			draftHtml = selected?.markdown_html ?? '';
			editing = false;
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The note could not be opened.';
		}
	}

	function schedulePreview(): void {
		if (previewTimeout) clearTimeout(previewTimeout);
		previewTimeout = setTimeout(() => void updatePreview(), 250);
	}

	async function updatePreview(): Promise<void> {
		try {
			const result = await api<{ markdown_html?: string }>('/api/notes/preview', 'POST', { body });
			draftHtml = result.markdown_html ?? '';
		} catch {
			draftHtml = '';
		}
	}

	async function saveNote(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		error = '';
		message = '';
		saving = true;
		const payload = {
			title,
			body,
			folder_id: folderId ? Number(folderId) : null,
			tags: parseNoteTags(tagsText),
			...(selected ? { expected_version: selected.version } : {})
		};
		try {
			const result = await api<NotesResponse>(selected ? '/api/notes/' + selected.id : '/api/notes', selected ? 'PATCH' : 'POST', payload);
			selected = result.note ?? null;
			editing = false;
			message = 'Note saved.';
			await loadNotes();
			if (selected) await openNote(selected);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The note could not be saved.';
		} finally {
			saving = false;
		}
	}

	async function toggleSharing(note: PersonalNote): Promise<void> {
		try {
			const result = await api<NotesResponse>('/api/notes/' + note.id, 'PATCH', {
				expected_version: note.version,
				shared_with_household: !note.shared_with_household
			});
			selected = result.note ?? selected;
			message = note.shared_with_household ? 'Note is private again.' : 'Note shared with the household.';
			await loadNotes();
			if (selected) await openNote(selected);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The sharing setting could not be updated.';
		}
	}

	async function archiveNote(note: PersonalNote, archived: boolean): Promise<void> {
		try {
			const result = await api<NotesResponse>('/api/notes/' + note.id + '/archive', 'PATCH', {
				archived,
				expected_version: note.version
			});
			selected = null;
			editing = false;
			message = archived ? 'Note archived.' : 'Note restored.';
			await loadNotes();
			if (!result.note) return;
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The note archive could not be updated.';
		}
	}

	async function deleteNote(note: PersonalNote): Promise<void> {
		try {
			await api<NotesResponse>('/api/notes/' + note.id, 'DELETE');
			selected = null;
			deleteTarget = null;
			deleteOpen = false;
			message = 'Note deleted.';
			await loadNotes();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The note could not be deleted.';
		}
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
			if (folderId === String(folder.id)) folderId = '';
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
		selected = null;
		void loadNotes();
	}
</script>

<svelte:head>
	<title>{$t('Notes')} · Metricove</title>
	<meta name="description" content={$t('Keep private Markdown notes organized, searchable and selectively shared.')} />
</svelte:head>

<div class="space-y-6">
	<PageHeading eyebrow={$t('Household')} title={$t('Notes')} description={$t('Keep ideas and household knowledge in one organized place.')} />
	{#if error}<Notice tone="error" message={$t(error)} />{/if}
	{#if message}<Notice tone="success" message={$t(message)} />{/if}

	{#if !shell.loading && !canView}
		<Panel><h2 class="text-lg font-semibold text-white">{$t('Notes are not available')}</h2><p class="mt-2 text-sm leading-6 text-slate-400">{$t('Ask a household administrator to enable Notes and grant your account View access.')}</p></Panel>
	{:else}
		<div class="grid gap-5 xl:grid-cols-[minmax(18rem,0.9fr)_minmax(0,1.5fr)]">
			<Panel>
				<div class="flex items-center justify-between gap-3"><h2 class="text-lg font-semibold text-white">{$t('Your notebook')}</h2>{#if canCreate}<Button size="small" onclick={resetEditor}>{$t('New note')}</Button>{/if}</div>
				<form class="mt-4 flex gap-2" onsubmit={applySearch}><label class="sr-only" for="note-search">{$t('Search notes')}</label><input id="note-search" bind:value={searchInput} placeholder={$t('Search title, text or tags')} class="ui-field min-w-0 flex-1" /><Button type="submit" variant="secondary">{$t('Search')}</Button></form>
				<div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
					<label><span class="mb-1 block text-xs text-slate-400">{$t('Folder')}</span><select bind:value={folderFilter} onchange={() => { pagination.current_page = 1; void loadNotes(); }} class="ui-field w-full"><option value="">{$t('All folders')}</option>{#each folders as folder (folder.id)}<option value={String(folder.id)}>{folder.name}</option>{/each}</select></label>
					<label><span class="mb-1 block text-xs text-slate-400">{$t('Show')}</span><select value={statusFilter} onchange={(event) => setStatus(event.currentTarget.value === 'archived' ? 'archived' : 'active')} class="ui-field w-full"><option value="active">{$t('Active notes')}</option><option value="archived">{$t('Archived notes')}</option></select></label>
				</div>
				{#if loading}<p class="mt-5 text-sm text-slate-400">{$t('Loading notes…')}</p>
				{:else if notes.length === 0}<p class="mt-5 rounded-xl border border-dashed border-slate-700 p-4 text-sm text-slate-400">{$t('No notes match your search.')}</p>
				{:else}<ul class="mt-4 space-y-2">{#each notes as note (note.id)}<li><button type="button" onclick={() => void openNote(note)} aria-current={selected?.id === note.id ? 'true' : undefined} class="w-full rounded-xl border px-4 py-3 text-left transition {selected?.id === note.id ? 'border-cyan-300/60 bg-cyan-300/5' : 'border-slate-800 bg-slate-950/40 hover:border-slate-600'}"><span class="flex items-center justify-between gap-2"><span class="truncate font-medium text-white">{note.title}</span>{#if note.shared_with_household}<span class="shrink-0 rounded-full border border-cyan-300/30 px-2 py-0.5 text-[11px] text-cyan-200">{$t('Shared')}</span>{/if}</span><span class="mt-1 block line-clamp-2 text-sm leading-5 text-slate-400">{note.preview || $t('No preview text.')}</span><span class="mt-2 flex flex-wrap gap-1.5">{#each note.tags as tag (tag.id)}<span class="rounded-full bg-slate-800 px-2 py-0.5 text-[11px] text-slate-300">{tag.name}</span>{/each}</span></button></li>{/each}</ul>
					<div class="mt-4 flex items-center justify-between gap-2 text-xs text-slate-400"><span>{$t('Showing {count} notes', { count: pagination.total })}</span><div class="flex gap-2"><Button size="small" variant="ghost" disabled={pagination.current_page <= 1} onclick={() => { pagination.current_page--; void loadNotes(); }}>{$t('Previous')}</Button><Button size="small" variant="ghost" disabled={pagination.current_page >= pagination.last_page} onclick={() => { pagination.current_page++; void loadNotes(); }}>{$t('Next')}</Button></div></div>
				{/if}
				{#if canCreate || canEdit}<div class="mt-6 border-t border-slate-800 pt-5"><h3 class="text-sm font-semibold text-white">{$t('Private folders')}</h3>{#if canCreate}<form class="mt-3 flex gap-2" onsubmit={saveFolder}><label class="sr-only" for="note-folder-name">{$t('Folder name')}</label><input id="note-folder-name" bind:value={folderName} placeholder={$t('New folder')} maxlength="80" class="ui-field min-w-0 flex-1" /><Button size="small" type="submit">{$t('Add')}</Button></form>{/if}{#if folderEditId}<form class="mt-3 flex gap-2" onsubmit={saveFolder}><label class="sr-only" for="note-folder-edit">{$t('Folder name')}</label><input id="note-folder-edit" bind:value={folderEditName} maxlength="80" required class="ui-field min-w-0 flex-1" /><Button size="small" type="submit">{$t('Rename')}</Button><Button size="small" variant="ghost" onclick={() => { folderEditId = null; folderEditName = ''; }}>{$t('Cancel')}</Button></form>{/if}<ul class="mt-3 space-y-1">{#each folders as folder (folder.id)}<li class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-300"><span class="truncate">{folder.name} <span class="text-xs text-slate-500">({folder.note_count})</span></span>{#if canEdit}<span class="flex gap-1"><Button size="small" variant="ghost" onclick={() => { folderEditId = folder.id; folderEditName = folder.name; }}>{$t('Rename')}</Button><Button size="small" variant="ghost" onclick={() => void deleteFolder(folder)}>{$t('Remove')}</Button></span>{/if}</li>{/each}</ul></div>{/if}
			</Panel>

			<Panel>
				{#if editing}
					<div class="flex items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-300">{$t(selected ? 'Edit note' : 'New note')}</p><h2 class="mt-2 text-xl font-semibold text-white">{$t('Write it down.')}</h2></div><Button variant="ghost" size="small" onclick={() => { editing = false; selected = null; }}>{$t('Cancel')}</Button></div>
					<form class="mt-5 space-y-4" onsubmit={saveNote}>
						<label class="block"><span class="mb-1.5 block text-sm font-medium text-slate-300">{$t('Title')}</span><input required maxlength="180" bind:value={title} class="ui-field w-full" /></label>
						<div class="grid gap-4 md:grid-cols-2"><label><span class="mb-1.5 block text-sm font-medium text-slate-300">{$t('Folder')}</span><select bind:value={folderId} class="ui-field w-full"><option value="">{$t('No folder')}</option>{#each folders as folder (folder.id)}<option value={String(folder.id)}>{folder.name}</option>{/each}</select></label><label><span class="mb-1.5 block text-sm font-medium text-slate-300">{$t('Tags')}</span><input bind:value={tagsText} placeholder={$t('Separate tags with commas')} class="ui-field w-full" /></label></div>
						<label class="block"><span class="mb-1.5 block text-sm font-medium text-slate-300">{$t('Markdown')}</span><textarea bind:value={body} oninput={schedulePreview} rows="14" maxlength="100000" placeholder={$t('Write your note in Markdown…')} class="ui-field w-full resize-y font-mono text-sm"></textarea></label>
						<div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4"><h3 class="mb-3 text-sm font-semibold text-slate-300">{$t('Preview')}</h3>{#if draftHtml}<article class="prose prose-invert max-w-none text-sm leading-6">{@html draftHtml}</article>{:else}<p class="text-sm text-slate-500">{$t('Your preview will appear here when the note is saved.')}</p>{/if}</div>
						<div class="flex flex-wrap gap-2"><Button type="submit" disabled={saving || (!selected && !canCreate) || (selected && !selected.can_edit)}>{saving ? $t('Saving…') : $t('Save note')}</Button></div>
						<p class="text-xs text-slate-500">{$t('Notes are private by default. You can share an individual note after saving it.')}</p>
					</form>
				{:else if selected}
					<div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><p class="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-300">{$t(selected.shared_with_household ? 'Shared with household' : 'Private note')}</p><h2 class="mt-2 break-words text-2xl font-semibold text-white">{selected.title}</h2><div class="mt-2 flex flex-wrap gap-2 text-xs text-slate-400">{#if selected.folder}<span>{$t('Folder')}: {selected.folder.name}</span>{/if}{#if selected.updated_at}<span>{$t('Updated')} {formatDateTime(selected.updated_at)}</span>{/if}</div><div class="mt-3 flex flex-wrap gap-1.5">{#each selected.tags as tag (tag.id)}<span class="rounded-full bg-slate-800 px-2.5 py-1 text-xs text-slate-300">{tag.name}</span>{/each}</div></div><div class="flex flex-wrap gap-2">{#if selected.can_edit}<Button size="small" variant="secondary" onclick={() => { editing = true; }}>{$t('Edit')}</Button>{/if}{#if selected.can_share}<Button size="small" variant="secondary" onclick={() => void toggleSharing(selected!)}>{$t(selected.shared_with_household ? 'Make private' : 'Share with household')}</Button>{/if}{#if selected.is_owner && selected.can_edit}<Button size="small" variant="ghost" onclick={() => void archiveNote(selected!, !selected!.archived_at)}>{$t(selected.archived_at ? 'Restore' : 'Archive')}</Button><Button size="small" variant="danger" onclick={() => { deleteTarget = selected; deleteOpen = true; }}>{$t('Delete')}</Button>{/if}</div></div>
					{#if selected.markdown_html}<article class="prose prose-invert mt-6 max-w-none overflow-x-auto rounded-xl border border-slate-800 bg-slate-950/50 p-5 text-sm leading-7">{@html selected.markdown_html}</article>{/if}
					{#if !selected.is_owner}<p class="mt-4 rounded-lg border border-cyan-300/20 bg-cyan-300/5 p-3 text-sm text-cyan-100">{$t('This note is shared for reading. Only its owner can edit it.')}</p>{/if}
				{:else}<div class="grid min-h-64 place-items-center rounded-xl border border-dashed border-slate-700 p-8 text-center"><div><p class="text-base font-semibold text-white">{$t('Select a note')}</p><p class="mt-2 text-sm text-slate-400">{$t('Choose a note to read or create a new one.')}</p>{#if canCreate}<Button class="mt-4" onclick={resetEditor}>{$t('New note')}</Button>{/if}</div></div>{/if}
			</Panel>
		</div>
	{/if}
	{#if deleteTarget}<ConfirmModal bind:open={deleteOpen} title={$t('Delete note?')} message={$t('Delete this note permanently?')} onConfirm={() => void deleteNote(deleteTarget!)} />{/if}
</div>
