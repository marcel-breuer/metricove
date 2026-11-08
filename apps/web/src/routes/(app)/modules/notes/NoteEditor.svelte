<script lang="ts">
	import { control, field, fieldLabel } from '#lib/ui/styles';
	import { untrack } from 'svelte';
	import { notesRequest } from '#lib/api-client';
	import { t } from '#lib/i18n';
	import { parseNoteTags, type NoteFolder, type NotesResponse, type PersonalNote } from '#lib/notes';
	import Button from '#lib/ui/Button.svelte';
	import Modal from '#lib/ui/Modal.svelte';
	import Notice from '#lib/ui/Notice.svelte';

	let {
		open = $bindable(false),
		note = null,
		folders,
		onSaved
	}: {
		open?: boolean;
		/** Note being edited; `null` creates a new note. */
		note?: PersonalNote | null;
		folders: NoteFolder[];
		onSaved: (note: PersonalNote | null, created: boolean) => void | Promise<void>;
	} = $props();

	const formId = $props.id();
	let title = $state('');
	let body = $state('');
	let tagsText = $state('');
	let folderId = $state('');
	let draftHtml = $state('');
	let saving = $state(false);
	let error = $state('');
	let previewTimeout: ReturnType<typeof setTimeout> | undefined;

	$effect(() => {
		if (!open) return;
		untrack(() => {
			title = note?.title ?? '';
			body = note?.body ?? '';
			tagsText = note?.tags.map((tag) => tag.name).join(', ') ?? '';
			folderId = note?.folder ? String(note.folder.id) : '';
			draftHtml = note?.markdown_html ?? '';
			error = '';
		});
		return () => {
			if (previewTimeout) clearTimeout(previewTimeout);
		};
	});

	function schedulePreview(): void {
		if (previewTimeout) clearTimeout(previewTimeout);
		previewTimeout = setTimeout(() => void updatePreview(), 250);
	}

	async function updatePreview(): Promise<void> {
		try {
			const result = await notesRequest<{ markdown_html?: string }>('/api/notes/preview', 'POST', { body });
			draftHtml = result.markdown_html ?? '';
		} catch {
			draftHtml = '';
		}
	}

	async function save(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		error = '';
		saving = true;
		const payload = {
			title,
			body,
			folder_id: folderId ? Number(folderId) : null,
			tags: parseNoteTags(tagsText),
			...(note ? { expected_version: note.version } : {})
		};
		try {
			const result = await notesRequest<NotesResponse>(note ? '/api/notes/' + note.id : '/api/notes', note ? 'PATCH' : 'POST', payload);
			open = false;
			await onSaved(result.note ?? null, !note);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The note could not be saved.';
		} finally {
			saving = false;
		}
	}
</script>

<Modal bind:open size="fullscreen" title={$t(note ? 'Edit note' : 'New note')} description={note?.title}>
	{#if error}<Notice tone="error" message={error} class="mb-5" />{/if}
	<form id={formId} onsubmit={save} class="grid h-full gap-x-8 gap-y-6 lg:grid-cols-2 lg:grid-rows-[auto_minmax(0,1fr)]">
		<div class="grid gap-4 sm:grid-cols-2 lg:col-span-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
			<label class="{field} sm:col-span-2 lg:col-span-1">
				<span class="{fieldLabel}">{$t('Title')}</span>
				<input required maxlength="180" bind:value={title} class="{control}" />
			</label>
			<label class="{field}">
				<span class="{fieldLabel}">{$t('Folder')}</span>
				<select bind:value={folderId} class="{control}"><option value="">{$t('No folder')}</option>{#each folders as folder (folder.id)}<option value={String(folder.id)}>{folder.name}</option>{/each}</select>
			</label>
			<label class="{field}">
				<span class="{fieldLabel}">{$t('Tags')}</span>
				<input bind:value={tagsText} placeholder={$t('Separate tags with commas')} class="{control}" />
			</label>
		</div>
		<label class="{field} min-h-0 lg:grid-rows-[auto_minmax(0,1fr)]">
			<span class="{fieldLabel}">{$t('Markdown')}</span>
			<textarea bind:value={body} oninput={schedulePreview} rows="16" maxlength="100000" placeholder={$t('Write your note in Markdown…')} class="{control} min-h-64 resize-y font-mono text-sm leading-6 lg:h-full lg:resize-none"></textarea>
		</label>
		<section class="flex min-h-0 flex-col gap-2" aria-labelledby="{formId}-preview">
			<h3 id="{formId}-preview" class="{fieldLabel}">{$t('Preview')}</h3>
			<div class="min-h-40 flex-1 overflow-y-auto rounded-[0.625rem] border border-line bg-surface-subtle p-4">
				{#if draftHtml}<article class="prose max-w-none text-sm leading-6">{@html draftHtml}</article>{:else}<p class="text-sm text-ink-muted">{$t('Your preview will appear here when the note is saved.')}</p>{/if}
			</div>
		</section>
	</form>

	{#snippet footer()}
		<p class="mr-auto hidden text-xs text-ink-muted md:block">{$t('Notes are private by default. You can share an individual note after saving it.')}</p>
		<Button variant="secondary" type="button" disabled={saving} onclick={() => (open = false)}>{$t('Cancel')}</Button>
		<Button type="submit" form={formId} disabled={saving || (note !== null && !note.can_edit)}>{saving ? $t('Saving…') : $t('Save note')}</Button>
	{/snippet}
</Modal>
