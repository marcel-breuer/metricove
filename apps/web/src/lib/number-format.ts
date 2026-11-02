export type NumberInput = string | number | null | undefined;

let preferredLocale = 'en-US';
let preferredCurrencyDisplay: 'code' | 'symbol' = 'code';

export function setNumberFormatPreferences(locale: string, currencyDisplay: 'code' | 'symbol'): void {
	preferredLocale = locale;
	preferredCurrencyDisplay = currencyDisplay;
}

export type NumberFormatOptions = {
	locale?: string;
	minimumFractionDigits?: number;
	maximumFractionDigits?: number;
};

const DEFAULT_MEASUREMENT_FRACTION_DIGITS = 3;
const MEASUREMENT_FRACTION_DIGITS_BY_UNIT = new Map<string, number>([
	['km', 1],
	['mi', 1]
]);

const DECIMAL_PATTERN = /^([+-]?)(\d+)(?:\.(\d+))?$/;
const EXPONENTIAL_PATTERN = /^([+-]?)(\d+)(?:\.(\d*))?[eE]([+-]?\d+)$/;

function localeSeparator(locale: string, type: 'decimal' | 'group'): string {
	return new Intl.NumberFormat(locale)
		.formatToParts(type === 'decimal' ? 1.1 : 1000)
		.find((part) => part.type === type)?.value ?? (type === 'decimal' ? '.' : ',');
}

function removeValidGrouping(value: string, separator: string): string | null {
	if (!value.includes(separator)) return value;
	const escaped = separator.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	const grouped = new RegExp(`^\\d{1,3}(?:${escaped}\\d{3})+$`);
	return grouped.test(value) ? value.split(separator).join('') : null;
}

/** Parse a localized decimal into the dot-decimal format accepted by the API. */
export function parseLocalizedDecimal(value: string, locale: string = preferredLocale): string | null {
	const trimmed = value.trim();
	if (!trimmed) return null;

	const sign = /^[+-]/.exec(trimmed)?.[0] ?? '';
	const unsigned = sign ? trimmed.slice(1) : trimmed;
	if (!unsigned) return null;

	const decimal = localeSeparator(locale, 'decimal');
	const grouping = localeSeparator(locale, 'group');
	let decimalSeparator: string | null = null;
	if (unsigned.includes(decimal)) {
		decimalSeparator = decimal;
	} else if (decimal !== '.' && unsigned.includes('.')) {
		// Keep accepting canonical decimal strings when editing prior API values. Multiple
		// valid dot groups can still be recognized as a pasted localized integer.
		const grouped = removeValidGrouping(unsigned, grouping);
		decimalSeparator = grouping === '.' && grouped !== null && unsigned.split('.').length > 2 ? null : '.';
	} else if (decimal === '.' && unsigned.includes('.')) {
		decimalSeparator = '.';
	}

	let wholePart = unsigned;
	let fractionPart = '';
	if (decimalSeparator !== null) {
		const parts = unsigned.split(decimalSeparator);
		if (parts.length > 2) return null;
		[wholePart, fractionPart = ''] = parts;
		if (fractionPart && !/^\d+$/.test(fractionPart)) return null;
		if (!fractionPart && parts.length === 2) return null;
	}

	if (decimalSeparator !== grouping) {
		const ungrouped = removeValidGrouping(wholePart, grouping);
		if (ungrouped === null) return null;
		wholePart = ungrouped;
	}

	if (!wholePart && !fractionPart) return null;
	if (wholePart && !/^\d+$/.test(wholePart)) return null;
	return `${sign}${wholePart || '0'}${fractionPart ? `.${fractionPart}` : ''}`;
}

/** Localize an API decimal for an editable field without adding ambiguous grouping marks. */
export function formatDecimalInput(value: NumberInput, locale: string = preferredLocale): string {
	if (value === null || value === undefined) return '';
	const source = typeof value === 'number'
		? (Number.isFinite(value) ? expandExponent(value) : String(value))
		: value.trim();
	const match = DECIMAL_PATTERN.exec(source);
	if (!match) return typeof value === 'string' ? value : source;

	const [, sign, whole, fraction] = match;
	const decimal = localeSeparator(locale, 'decimal');
	return `${sign}${whole}${fraction ? `${decimal}${fraction}` : ''}`;
}

/** Normalize valid localized decimals; leave other input intact for normal validation. */
export function canonicalizeDecimalInput(value: string, locale: string = preferredLocale): string {
	return parseLocalizedDecimal(value, locale) ?? value;
}

function hasNonZeroMagnitude(value: NumberInput): boolean {
	if (value === null || value === undefined) return false;
	const source = typeof value === 'number'
		? (Number.isFinite(value) ? expandExponent(value) : String(value))
		: value.trim();
	const match = DECIMAL_PATTERN.exec(source);

	return match !== null && /[1-9]/.test(match[2] + (match[3] ?? ''));
}

function expandExponent(value: number): string {
	const source = String(value);
	const match = EXPONENTIAL_PATTERN.exec(source);
	if (!match) return source;

	const [, sign, whole, fraction = '', exponentText] = match;
	const digits = whole + fraction;
	const decimalIndex = whole.length + Number(exponentText);

	if (decimalIndex <= 0) return `${sign}0.${'0'.repeat(-decimalIndex)}${digits}`;
	if (decimalIndex >= digits.length) return `${sign}${digits}${'0'.repeat(decimalIndex - digits.length)}`;
	return `${sign}${digits.slice(0, decimalIndex)}.${digits.slice(decimalIndex)}`;
}

/** Format decimal strings without losing precision to JavaScript floating point. */
export function formatNumber(value: NumberInput, options: NumberFormatOptions = {}): string {
	if (value === null || value === undefined) return '—';

	const source = typeof value === 'number' ? (Number.isFinite(value) ? expandExponent(value) : String(value)) : value.trim();
	const match = DECIMAL_PATTERN.exec(source);
	if (!match) return typeof value === 'string' ? value : source;

	const [, sign, sourceWhole, sourceFraction = ''] = match;
	const wholeDigits = sourceWhole.replace(/^0+(?=\d)/, '');
	const minimumFractionDigits = Math.max(0, Math.trunc(options.minimumFractionDigits ?? 0));
	const maximumFractionDigits = options.maximumFractionDigits === undefined
		? undefined
		: Math.max(minimumFractionDigits, Math.trunc(options.maximumFractionDigits));

	let whole = wholeDigits;
	let fraction = sourceFraction;
	if (maximumFractionDigits !== undefined && fraction.length > maximumFractionDigits) {
		const scale = 10n ** BigInt(maximumFractionDigits);
		const retained = fraction.slice(0, maximumFractionDigits).padEnd(maximumFractionDigits, '0');
		let scaled = BigInt(wholeDigits) * scale + BigInt(retained || '0');
		if (fraction.charCodeAt(maximumFractionDigits) >= 53) scaled += 1n;
		whole = (scaled / scale).toString();
		fraction = maximumFractionDigits === 0
			? ''
			: (scaled % scale).toString().padStart(maximumFractionDigits, '0');
	}

	fraction = fraction.replace(/0+$/, '').padEnd(minimumFractionDigits, '0');
	const isZero = whole === '0' && /^0*$/.test(fraction);
	const locale = options.locale ?? preferredLocale;
	const groupedWhole = new Intl.NumberFormat(locale, {
		maximumFractionDigits: 0,
		useGrouping: true
	}).format(BigInt(whole));
	const decimalSeparator = new Intl.NumberFormat(locale, {
		minimumFractionDigits: 1,
		maximumFractionDigits: 1,
		useGrouping: false
	}).formatToParts(1.1).find((part) => part.type === 'decimal')?.value ?? '.';
	const minusSign = new Intl.NumberFormat(locale, { useGrouping: false })
		.formatToParts(-1)
		.find((part) => part.type === 'minusSign')?.value ?? '-';
	const prefix = isZero ? '' : sign === '-' ? minusSign : sign === '+' ? '+' : '';

	return `${prefix}${groupedWhole}${fraction ? decimalSeparator + fraction : ''}`;
}

/** Keep measurements readable while retaining useful precision for their unit. */
export function formatMeasurement(
	value: NumberInput,
	unit?: string | null,
	locale: string = preferredLocale
): string {
	const normalizedUnit = unit?.trim().toLocaleLowerCase('en-US') ?? '';
	const maximumFractionDigits = MEASUREMENT_FRACTION_DIGITS_BY_UNIT.get(normalizedUnit)
		?? DEFAULT_MEASUREMENT_FRACTION_DIGITS;

	const formatted = formatNumber(value, { locale, maximumFractionDigits });
	if (formatted === '0' && hasNonZeroMagnitude(value)) {
		return formatNumber(value, { locale });
	}

	return formatted;
}

/** Format stored money amounts to the two decimal places supported by the API. */
export function formatCurrency(
	value: NumberInput,
	currency: string,
	locale: string = preferredLocale,
	display: 'code' | 'symbol' = preferredCurrencyDisplay
): string {
	const formatted = formatNumber(value, {
		locale,
		minimumFractionDigits: 2,
		maximumFractionDigits: 2
	});

	if (formatted === '—') return formatted;
	if (display === 'code') return `${currency.toUpperCase()} ${formatted}`;

	const parts = new Intl.NumberFormat(locale, { style: 'currency', currency, currencyDisplay: 'symbol' }).formatToParts(1);
	const symbol = parts.find((part) => part.type === 'currency')?.value ?? currency.toUpperCase();
	const sign = formatted.startsWith('-') ? formatted.slice(0, 1) : '';
	const unsigned = sign ? formatted.slice(1) : formatted;
	const hasPrefix = parts.findIndex((part) => part.type === 'currency') < parts.findIndex((part) => part.type === 'integer');
	const spacing = parts.some((part) => part.type === 'literal' && part.value.trim() === '') ? ' ' : '';
	return hasPrefix ? `${sign}${symbol}${spacing}${unsigned}` : `${sign}${unsigned}${spacing}${symbol}`;
}
