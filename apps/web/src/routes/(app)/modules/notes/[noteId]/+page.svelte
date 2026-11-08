<script lang="ts">
	import { panel } from '#lib/ui/styles';
	import { getContext } from 'svelte';
	import { goto } from '$app/navigation';
	import { page } from '$app/state';
	import { notesRequest as api } from '#lib/api-client';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import { formatDateTime, t } from '#lib/i18n';
	import type { NoteFolder, NotesResponse, PersonalNote } from '#lib/notes';
	import Badge from '#lib/ui/Badge.svelte';
	import BackLink from '#lib/ui/BackLink.svelte';
	import Button from '#lib/ui/Button.svelte';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import Panel from '#lib/ui/Panel.svelte';
	import NoteEditor from '../NoteEditor.svelte';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const noteId = $derived(Number(page.params.noteId));
	const access = $derived(shell.modules.find((module) => module.key === 'notes'));
	const canView = $derived(access?.capabilities.view === true);

	let note = $state<PersonalNote | null>(null);
	let folders = $state<NoteFolder[]>([]);
	let loading = $state(true);
	let error = $state('');
	let message = $state('');
	let editorOpen = $state(false);
	let deleteOpen = $state(false);

	$effect(() => {
		if (!shell.loading) void loadNote(noteId);
	});

	async function loadNote(id: number): Promise<void> {
		loading = true;
		error = '';
		if (!canView) {
			note = null;
			loading = false;
			return;
		}
		try {
			if (!Number.isInteger(id) || id <= 0) throw new Error('This note is unavailable to your account.');
			const result = await api<NotesResponse>('/api/notes/' + id);
			note = result.note ?? null;
			if (!note) throw new Error('This note is unavailable to your account.');
			if (note.can_edit) {
				const folderResult = await api<NotesResponse>('/api/notes/folders');
				folders = folderResult.folders ?? [];
			}
		} catch (cause) {
			note = null;
			error = cause instanceof Error ? cause.message : 'The note could not be opened.';
		} finally {
			loading = false;
		}
	}

	async function noteSaved(saved: PersonalNote | null): Promise<void> {
		message = 'Note saved.';
		if (saved) await loadNote(saved.id);
	}

	async function toggleSharing(current: PersonalNote): Promise<void> {
		error = '';
		message = '';
		try {
			const result = await api<NotesResponse>('/api/notes/' + current.id, 'PATCH', {
				expected_version: current.version,
				shared_with_household: !current.shared_with_household
			});
			message = current.shared_with_household ? 'Note is private again.' : 'Note shared with the household.';
			if (result.note) await loadNote(result.note.id);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The sharing setting could not be updated.';
		}
	}

	async function archiveNote(current: PersonalNote, archived: boolean): Promise<void> {
		error = '';
		message = '';
		try {
			await api<NotesResponse>('/api/notes/' + current.id + '/archive', 'PATCH', { archived, expected_version: current.version });
			message = archived ? 'Note archived.' : 'Note restored.';
			await loadNote(current.id);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The note archive could not be updated.';
		}
	}

	async function deleteNote(current: PersonalNote): Promise<void> {
		try {
			await api<NotesResponse>('/api/notes/' + current.id, 'DELETE');
			deleteOpen = false;
			await goto('/modules/notes');
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The note could not be deleted.';
		}
	}
</script>

<svelte:head>
	<title>{note ? note.title : $t('Notes')} · Metricove</title>
	<meta name="description" content={$t('Keep private Markdown notes organized, searchable and selectively shared.')} />
</svelte:head>

<div class="mx-auto max-w-5xl space-y-6">
	<BackLink href="/modules/notes" label="All notes" />

	{#if message}<Notice tone="success" message={message} />{/if}
	{#if error && note}<Notice tone="error" message={error} />{/if}

	{#if shell.loading || loading}
		<p class="{panel} p-5 max-sm:p-4 text-sm text-ink-muted" role="status">{$t('Loading note…')}</p>
	{:else if !canView}
		<Panel><h1 class="text-lg font-semibold">{$t('Notes are not available')}</h1><p class="mt-2 text-sm leading-6 text-ink-muted">{$t('Ask a household administrator to enable Notes and grant your account View access.')}</p></Panel>
	{:else if !note}
		<Panel>
			<h1 class="text-xl font-semibold">{$t('Note unavailable')}</h1>
			<p class="mt-2 text-sm leading-6 text-ink-muted">{$t(error || 'This note is unavailable to your account.')}</p>
			<Button href="/modules/notes" variant="secondary" class="mt-5">{$t('All notes')}</Button>
		</Panel>
	{:else}
		<header class="flex flex-wrap items-end justify-between gap-4 max-sm:items-start">
			<div class="min-w-0">
				<p class="text-[0.8125rem] font-semibold tracking-[0.01em] text-accent-ink">{$t(note.shared_with_household ? 'Shared with household' : 'Private note')}{#if note.archived_at} · {$t('Archived')}{/if}</p>
				<h1 class="mt-2 break-words text-[clamp(1.75rem,3vw,2.25rem)] leading-[1.16] font-[650] tracking-[-0.03em] text-ink">{note.title}</h1>
				<div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-ink-muted">
					{#if note.folder}<span>{$t('Folder')}: {note.folder.name}</span>{/if}
					{#if note.updated_at}<span>{$t('Updated')} {formatDateTime(note.updated_at)}</span>{/if}
					{#each note.tags as tag (tag.id)}<Badge>{tag.name}</Badge>{/each}
				</div>
			</div>
			<div class="flex items-center gap-3 max-sm:w-full max-sm:flex-wrap max-sm:*:flex-auto">
				{#if note.is_owner && note.can_edit}
					<Button variant="ghost" onclick={() => (deleteOpen = true)}>{$t('Delete')}</Button>
					<Button variant="secondary" onclick={() => void archiveNote(note!, !note!.archived_at)}>{$t(note.archived_at ? 'Restore' : 'Archive')}</Button>
				{/if}
				{#if note.can_share}<Button variant="secondary" onclick={() => void toggleSharing(note!)}>{$t(note.shared_with_household ? 'Make private' : 'Share with household')}</Button>{/if}
				{#if note.can_edit}<Button onclick={() => { message = ''; editorOpen = true; }}>{$t('Edit')}</Button>{/if}
			</div>
		</header>

		{#if !note.is_owner}<Notice tone="info" message="This note is shared for reading. Only its owner can edit it." />{/if}

		<Panel padding="large">
			{#if note.markdown_html}<article class="prose max-w-none overflow-x-auto text-sm leading-7">{@html note.markdown_html}</article>{:else}<p class="text-sm text-ink-muted">{$t('No preview text.')}</p>{/if}
		</Panel>

		{#if note.can_edit}<NoteEditor bind:open={editorOpen} {note} {folders} onSaved={(saved) => noteSaved(saved)} />{/if}
		{#if note.is_owner && note.can_edit}<ConfirmModal bind:open={deleteOpen} title={$t('Delete note?')} message={$t('Delete this note permanently?')} onConfirm={() => void deleteNote(note!)} />{/if}
	{/if}
</div>
