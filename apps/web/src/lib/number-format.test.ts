import { describe, expect, it } from 'bun:test';
import { formatCurrency, formatNumber } from './number-format';

describe('formatNumber', () => {
	it('groups large integers and removes insignificant fractional zeroes', () => {
		expect(formatNumber('1234567.500000', { locale: 'en-US' })).toBe('1,234,567.5');
		expect(formatNumber('1200.000', { locale: 'en-US' })).toBe('1,200');
	});

	it('uses the requested locale and keeps every significant measurement digit', () => {
		expect(formatNumber('1234.000125', { locale: 'de-DE' })).toBe('1.234,000125');
		expect(formatNumber('0.000001', { locale: 'en-US' })).toBe('0.000001');
	});

	it('preserves decimal precision for values larger than JavaScript safe integers', () => {
		expect(formatNumber('123456789012345678.123456', { locale: 'en-US' })).toBe('123,456,789,012,345,678.123456');
	});

	it('rounds only when a maximum fraction length is explicitly requested', () => {
		expect(formatNumber('-1.235', { locale: 'en-US', maximumFractionDigits: 2 })).toBe('-1.24');
		expect(formatNumber('12.3400', { locale: 'en-US', maximumFractionDigits: 2 })).toBe('12.34');
	});

	it('keeps non-numeric quantities intact and formats empty values clearly', () => {
		expect(formatNumber('1 1/2 cups', { locale: 'en-US' })).toBe('1 1/2 cups');
		expect(formatNumber('to taste', { locale: 'en-US' })).toBe('to taste');
		expect(formatNumber(null, { locale: 'en-US' })).toBe('—');
	});

	it('formats stored monetary amounts with exactly two fraction digits', () => {
		expect(formatCurrency('1234', 'EUR', 'en-US')).toBe('EUR 1,234.00');
		expect(formatCurrency('1234.235', 'EUR', 'de-DE')).toBe('EUR 1.234,24');
		expect(formatCurrency('-0.004', 'EUR', 'en-US')).toBe('EUR 0.00');
	});
});
