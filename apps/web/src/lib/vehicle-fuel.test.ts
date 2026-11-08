import { describe, expect, it } from 'bun:test';
import { calculateFuelTotal, calculateMissingFuelValue, currencyAmountsDiffer } from './vehicle-fuel';

describe('vehicle fuel calculations', () => {
	it('derives the amount using currency precision without floating point drift', () => {
		expect(calculateMissingFuelValue({ amount: '', fuel_liters: '39,2', fuel_unit_price: '1,64', locale: 'de-DE' }))
			.toEqual({ field: 'amount', value: '64.29' });
	});

	it('derives quantity and unit price at their API precision', () => {
		expect(calculateMissingFuelValue({ amount: '64.29', fuel_liters: '', fuel_unit_price: '1.64', locale: 'en-US' }))
			.toEqual({ field: 'fuel_liters', value: '39.201' });
		expect(calculateMissingFuelValue({ amount: '64.29', fuel_liters: '39.2', fuel_unit_price: '', locale: 'en-US' }))
			.toEqual({ field: 'fuel_unit_price', value: '1.6401' });
	});

	it('does not derive from incomplete, malformed or over-precision values', () => {
		expect(calculateMissingFuelValue({ amount: '', fuel_liters: '39,2', fuel_unit_price: '', locale: 'de-DE' })).toBeNull();
		expect(calculateMissingFuelValue({ amount: '', fuel_liters: '39,2001', fuel_unit_price: '1,64', locale: 'de-DE' })).toBeNull();
		expect(calculateMissingFuelValue({ amount: '', fuel_liters: '-39', fuel_unit_price: '1.64', locale: 'en-US' })).toBeNull();
	});

	it('rounds displayed totals to the same two decimals stored by the API', () => {
		expect(calculateFuelTotal('39,2', '1,64', 'de-DE')).toBe('64.29');
		expect(calculateFuelTotal('1', '1.005', 'en-US')).toBe('1.01');
	});

	it('compares totals at the persisted currency precision', () => {
		expect(currencyAmountsDiffer('64,29', '64.29', 'de-DE')).toBe(false);
		expect(currencyAmountsDiffer('64,29', '64.30', 'de-DE')).toBe(true);
	});
});
