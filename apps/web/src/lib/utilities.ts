export type UtilityMeterType = 'electricity' | 'gas' | 'water';
export type UtilityVisibility = 'private' | 'household';

export type UtilityMeter = {
	id: number;
	name: string;
	meter_type: UtilityMeterType;
	meter_label: string;
	unit: string;
	description: string | null;
	visibility: UtilityVisibility;
	alert_threshold: string | null;
	created_at: string;
	updated_at: string;
};

export type UtilityReading = {
	id: number;
	reading_date: string;
	value: string;
	unit: string;
	note: string | null;
	photo_url: string | null;
	consumption_since_previous: string | null;
	days_since_previous: number | null;
	alert: boolean;
	revision_count: number;
	created_at: string;
	updated_at: string;
};

export type UtilityReadingRevision = {
	id: number;
	old_reading_date: string;
	new_reading_date: string;
	old_value: string;
	new_value: string;
	old_note: string | null;
	new_note: string | null;
	reason: string;
	changed_at: string;
};

export type UtilitiesResponse = {
	message?: string;
	meter?: UtilityMeter;
	meters?: UtilityMeter[];
	readings?: UtilityReading[];
	revisions?: UtilityReadingRevision[];
	errors?: Record<string, string[]>;
};

export const utilityMeterTypes: Array<{ value: UtilityMeterType; label: string; unit: string }> = [
	{ value: 'electricity', label: 'Electricity', unit: 'kWh' },
	{ value: 'gas', label: 'Gas', unit: 'm³' },
	{ value: 'water', label: 'Water', unit: 'm³' }
];
