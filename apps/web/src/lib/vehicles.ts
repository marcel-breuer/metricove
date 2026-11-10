export type VehicleFuelType = 'petrol' | 'diesel' | 'hybrid' | 'electric' | 'lpg' | 'other';
export type VehicleVisibility = 'private' | 'household';

export type Vehicle = {
	id: number;
	name: string;
	make: string;
	model: string;
	model_year: number | null;
	fuel_type: VehicleFuelType;
	odometer_unit: 'km' | 'mi';
	description: string | null;
	visibility: VehicleVisibility;
	created_at: string;
	updated_at: string;
};

export type VehicleOdometerReading = {
	id: number;
	reading_date: string;
	value: string;
	unit: string;
	distance_since_previous: string | null;
	days_since_previous: number | null;
	note: string | null;
	revision_count: number;
	can_edit: boolean;
	created_at: string;
	updated_at: string;
};

export type VehicleDistanceMonth = {
	period: string;
	distance: string;
};

export type VehicleDistanceTrend = {
	unit: 'km' | 'mi';
	months: VehicleDistanceMonth[];
	average_monthly_distance: string;
	projection: VehicleDistanceMonth[];
	has_data: boolean;
};

export type VehicleDistanceChartPoint = VehicleDistanceMonth & {
	kind: 'actual' | 'projection';
	label: string;
	accessibleLabel: string;
};

export function vehicleDistanceChartPoints(trend: VehicleDistanceTrend, locale: string): VehicleDistanceChartPoint[] {
	const formatMonth = (period: string, options: Intl.DateTimeFormatOptions): string => {
		const [year, month] = period.split('-').map(Number);
		return new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, 1)));
	};

	return [
		...trend.months.map((month) => ({
			...month,
			kind: 'actual' as const,
			label: formatMonth(month.period, { month: 'short' }),
			accessibleLabel: formatMonth(month.period, { month: 'long', year: 'numeric' })
		})),
		...trend.projection.map((month) => ({
			...month,
			kind: 'projection' as const,
			label: formatMonth(month.period, { month: 'short' }),
			accessibleLabel: formatMonth(month.period, { month: 'long', year: 'numeric' })
		}))
	];
}

export type VehicleOdometerRevision = {
	id: number;
	old_reading_date: string;
	new_reading_date: string;
	old_value: string;
	new_value: string;
	old_note: string | null;
	new_note: string | null;
	reason: string | null;
	changed_at: string;
};

export type VehicleMaintenanceRecord = {
	id: number;
	title: string;
	service_type: string;
	occurred_on: string;
	next_due_on?: string | null;
	odometer_value: string | null;
	odometer_unit: string | null;
	note: string | null;
	can_edit: boolean;
};

export type VehicleInsurancePolicy = {
	id: number;
	provider: string;
	coverage_type: string;
	valid_from: string;
	valid_until: string;
	note: string | null;
	status: 'active' | 'upcoming' | 'expired';
	can_edit: boolean;
};

export type VehicleCost = {
	id: number;
	category: string;
	amount: string;
	currency: string;
	period_start: string;
	period_end: string | null;
	period: 'once' | 'monthly' | 'quarterly' | 'yearly';
	description: string | null;
	fuel_liters: string | null;
	fuel_unit_price: string | null;
	fuel_odometer_value: string | null;
	fuel_full_tank: boolean;
	fuel_consumption_l_per_100km: string | null;
	fuel_consumption_unavailable_reason: 'not_full_to_full' | 'missing_previous_full_tank' | 'missing_odometer' | 'non_comparable' | null;
	can_edit: boolean;
	invoice_url: string | null;
	invoice_name: string | null;
	invoice_mime: string | null;
};

export type VehicleResponse = {
	message?: string;
	vehicle?: Vehicle;
	distance_trend?: VehicleDistanceTrend;
	active_insurance_count?: number;
	vehicles?: Vehicle[];
	reading?: VehicleOdometerReading;
	odometer_readings?: VehicleOdometerReading[];
	has_more_odometer_readings?: boolean;
	has_more_maintenance_records?: boolean;
	has_more_insurance_policies?: boolean;
	has_more_costs?: boolean;
	maintenance_record?: VehicleMaintenanceRecord;
	maintenance_records?: VehicleMaintenanceRecord[];
	insurance_policy?: VehicleInsurancePolicy;
	insurance_policies?: VehicleInsurancePolicy[];
	cost?: VehicleCost;
	costs?: VehicleCost[];
	revisions?: VehicleOdometerRevision[];
	errors?: Record<string, string[]>;
};

export const vehicleFuelTypes: Array<{ value: VehicleFuelType; label: string }> = [
	{ value: 'petrol', label: 'Petrol' },
	{ value: 'diesel', label: 'Diesel' },
	{ value: 'hybrid', label: 'Hybrid' },
	{ value: 'electric', label: 'Electric' },
	{ value: 'lpg', label: 'LPG' },
	{ value: 'other', label: 'Other' }
];

export const vehicleCostCategories = [
	{ value: 'fuel', label: 'Fuel / charging' },
	{ value: 'maintenance', label: 'Maintenance' },
	{ value: 'insurance', label: 'Insurance' },
	{ value: 'tax', label: 'Tax' },
	{ value: 'registration', label: 'Registration' },
	{ value: 'finance', label: 'Finance' },
	{ value: 'other', label: 'Other' }
] as const;

export const vehiclePeriods = [
	{ value: 'once', label: 'One time' },
	{ value: 'monthly', label: 'Monthly' },
	{ value: 'quarterly', label: 'Quarterly' },
	{ value: 'yearly', label: 'Yearly' }
] as const;
