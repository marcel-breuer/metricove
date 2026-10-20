export type NumberInput = string | number | null | undefined;

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
	const locale = options.locale;
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
	locale?: string
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
export function formatCurrency(value: NumberInput, currency: string, locale?: string): string {
	const formatted = formatNumber(value, {
		locale,
		minimumFractionDigits: 2,
		maximumFractionDigits: 2
	});

	return formatted === '—' ? formatted : `${currency.toUpperCase()} ${formatted}`;
}
