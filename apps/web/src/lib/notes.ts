export type NoteTag = { id: number; name: string };

export type NoteFolder = {
	id: number;
	name: string;
	version: number;
	note_count?: number;
};

export type PersonalNote = {
	id: number;
	title: string;
	preview: string;
	body: string | null;
	markdown_html: string | null;
	folder: { id: number; name: string } | null;
	tags: NoteTag[];
	shared_with_household: boolean;
	is_owner: boolean;
	can_edit: boolean;
	can_share: boolean;
	archived_at: string | null;
	version: number;
	created_at: string | null;
	updated_at: string | null;
};

export type NotesResponse = {
	notes?: PersonalNote[];
	note?: PersonalNote;
	folders?: NoteFolder[];
	folder?: NoteFolder;
	pagination?: { current_page: number; last_page: number; per_page: number; total: number };
	message?: string;
	errors?: Record<string, string[]>;
};

export function parseNoteTags(value: string): string[] {
	const seen = new Set<string>();
	const tags: string[] = [];
	for (const raw of value.split(',')) {
		const tag = raw.trim();
		const normalized = tag.toLocaleLowerCase();
		if (!tag || seen.has(normalized)) continue;
		seen.add(normalized);
		tags.push(tag);
	}
	return tags;
}
