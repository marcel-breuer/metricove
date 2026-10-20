export type AnalyticsRange = '30d' | '90d' | '12m';
export type AnalyticsChartType = 'line' | 'bar';

export type AnalyticsMetric = {
	key: string;
	label: string;
	description: string;
	source_module: string;
	source_label: string;
};

export type AnalyticsSeries = {
	label: string;
	unit: string;
	values: string[];
	total: string;
};

export type AnalyticsWidget = {
	id: number;
	metric_key: string;
	title: string;
	range_key: AnalyticsRange;
	chart_type: AnalyticsChartType;
	position: number;
	version: number;
	available: boolean;
	periods: string[];
	series: AnalyticsSeries[];
};

export type AnalyticsResponse = {
	message?: string;
	metrics?: AnalyticsMetric[];
	catalog?: AnalyticsMetric[];
	widgets?: AnalyticsWidget[];
	widget?: Omit<AnalyticsWidget, 'available' | 'periods' | 'series'>;
	errors?: Record<string, string[]>;
};

export const analyticsRanges: Array<{ value: AnalyticsRange; label: string }> = [
	{ value: '30d', label: 'Last 30 days' },
	{ value: '90d', label: 'Last 90 days' },
	{ value: '12m', label: 'Last 12 months' }
];
