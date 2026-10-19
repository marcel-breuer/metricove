export type TrackerFieldType = 'number' | 'text' | 'date' | 'boolean';

export type TrackerField = {
	id: number;
	key: string;
	label: string;
	type: TrackerFieldType;
	unit: string | null;
	required: boolean;
	minimum: string | null;
	maximum: string | null;
	active: boolean;
	created_version: number;
	retired_version: number | null;
	sort_order: number;
};

export type TrackerFieldDraft = {
	id?: number;
	local_key: number;
	label: string;
	type: TrackerFieldType;
	unit: string;
	required: boolean;
	minimum: string;
	maximum: string;
};

export type Tracker = {
	id: number;
	name: string;
	description: string | null;
	visibility: 'private' | 'household';
	schema_version: number;
	can_manage_schema: boolean;
	fields: TrackerField[];
	created_at: string;
	updated_at: string;
};

export type TrackerValue = {
	key: string;
	label: string;
	type: TrackerFieldType;
	unit: string | null;
	value: string | boolean | null;
};

export type TrackerEntry = {
	id: number;
	tracker_id: number;
	schema_version: number;
	recorded_at: string;
	note: string | null;
	can_edit: boolean;
	values: TrackerValue[];
	created_at: string;
	updated_at: string;
};

export type TrackerRevision = {
	version: number;
	schema: {
		name: string;
		description: string | null;
		fields: Array<{
			key: string;
			label: string;
			type: TrackerFieldType;
			unit: string | null;
			required: boolean;
			minimum: string | null;
			maximum: string | null;
		}>;
	};
	created_at: string;
};

export type TrackerResponse = {
	message?: string;
	tracker?: Tracker;
	trackers?: Tracker[];
	entries?: TrackerEntry[];
	schema_revisions?: TrackerRevision[];
	errors?: Record<string, string[]>;
};

export function newTrackerField(localKey: number): TrackerFieldDraft {
	return {
		local_key: localKey,
		label: '',
		type: 'number',
		unit: '',
		required: false,
		minimum: '',
		maximum: ''
	};
}
