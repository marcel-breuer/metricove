import { parseLocalizedDecimal } from './number-format';

export type FuelCalculationField = 'amount' | 'fuel_liters' | 'fuel_unit_price';

export type FuelCalculationInput = {
	amount: string;
	fuel_liters: string;
	fuel_unit_price: string;
	locale: string;
};

export type FuelCalculation = {
	field: FuelCalculationField;
	value: string;
};

const TEN = 10n;

function parseScaled(value: string, locale: string, scale: number): bigint | null {
	const canonical = parseLocalizedDecimal(value, locale);
	if (!canonical || !/^\d+(?:\.\d+)?$/.test(canonical)) return null;
	const [whole, fraction = ''] = canonical.split('.');
	if (fraction.length > scale) return null;
	return BigInt(whole) * TEN ** BigInt(scale) + BigInt(fraction.padEnd(scale, '0') || '0');
}

function roundedDivide(numerator: bigint, denominator: bigint): bigint {
	if (denominator <= 0n) throw new RangeError('A positive denominator is required.');
	return (numerator + denominator / 2n) / denominator;
}

function formatScaled(value: bigint, scale: number): string {
	const digits = value.toString().padStart(scale + 1, '0');
	return scale === 0 ? digits : `${digits.slice(0, -scale)}.${digits.slice(-scale)}`;
}

/** Calculate exactly one missing fuel value using the API's supported precision. */
export function calculateMissingFuelValue(input: FuelCalculationInput): FuelCalculation | null {
	const present = (Object.keys(input) as Array<keyof FuelCalculationInput>)
		.filter((key): key is FuelCalculationField => key !== 'locale' && input[key].trim() !== '');
	if (present.length !== 2) return null;

	const missing = (['amount', 'fuel_liters', 'fuel_unit_price'] as FuelCalculationField[])
		.find((field) => !present.includes(field));
	if (!missing) return null;

	const amount = parseScaled(input.amount, input.locale, 2);
	const liters = parseScaled(input.fuel_liters, input.locale, 3);
	const unitPrice = parseScaled(input.fuel_unit_price, input.locale, 4);
	if (missing === 'amount' && liters !== null && liters > 0n && unitPrice !== null && unitPrice > 0n) {
		// (liters / 10^3) * (price / 10^4), rounded to the API's two money decimals.
		return { field: missing, value: formatScaled(roundedDivide(liters * unitPrice, 100_000n), 2) };
	}
	if (missing === 'fuel_liters' && amount !== null && unitPrice !== null && unitPrice > 0n) {
		// (amount / 10^2) / (price / 10^4), rounded to 3 quantity decimals.
		return { field: missing, value: formatScaled(roundedDivide(amount * 100_000n, unitPrice), 3) };
	}
	if (missing === 'fuel_unit_price' && amount !== null && liters !== null && liters > 0n) {
		// (amount / 10^2) / (liters / 10^3), rounded to 4 unit-price decimals.
		return { field: missing, value: formatScaled(roundedDivide(amount * 100_000n, liters), 4) };
	}
	return null;
}

/** Return the rounded invoice total shown for a valid quantity and unit price. */
export function calculateFuelTotal(fuelLiters: string, fuelUnitPrice: string, locale: string): string | null {
	const liters = parseScaled(fuelLiters, locale, 3);
	const unitPrice = parseScaled(fuelUnitPrice, locale, 4);
	if (liters === null || liters <= 0n || unitPrice === null || unitPrice <= 0n) return null;
	return formatScaled(roundedDivide(liters * unitPrice, 100_000n), 2);
}

export function currencyAmountsDiffer(first: string, second: string, locale: string): boolean {
	const firstMinor = parseScaled(first, locale, 2);
	const secondMinor = parseScaled(second, locale, 2);
	return firstMinor !== null && secondMinor !== null && firstMinor !== secondMinor;
}
