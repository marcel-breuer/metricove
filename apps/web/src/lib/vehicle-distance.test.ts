import { describe, expect, it } from 'bun:test';
import { vehicleDistanceChartPoints, type VehicleDistanceTrend } from './vehicles';

describe('vehicle distance chart data', () => {
	const trend: VehicleDistanceTrend = {
		unit: 'km',
		months: Array.from({ length: 12 }, (_, index) => ({
			period: index === 0 ? '2025-12' : `2026-${String(index).padStart(2, '0')}`,
			distance: index === 0 ? '100.000' : '0.000'
		})),
		average_monthly_distance: '8.333',
		projection: [
			{ period: '2026-12', distance: '8.333' },
			{ period: '2027-01', distance: '8.333' },
			{ period: '2027-02', distance: '8.333' }
		],
		has_data: true
	};

	it('keeps twelve historical months separate from three projected months', () => {
		const points = vehicleDistanceChartPoints(trend, 'en-US');

		expect(points).toHaveLength(15);
		expect(points.slice(0, 12).every((point) => point.kind === 'actual')).toBe(true);
		expect(points.slice(12).map((point) => point.period)).toEqual(['2026-12', '2027-01', '2027-02']);
		expect(points.slice(12).every((point) => point.kind === 'projection' && point.distance === '8.333')).toBe(true);
	});

	it('formats chart and accessible month labels in the selected locale', () => {
		const english = vehicleDistanceChartPoints(trend, 'en-US');
		const german = vehicleDistanceChartPoints(trend, 'de-DE');

		expect(english[0].label).toBe('Dec');
		expect(german[0].label).toBe('Dez');
		expect(english[12].accessibleLabel).toBe('December 2026');
		expect(german[12].accessibleLabel).toBe('Dezember 2026');
	});
});
