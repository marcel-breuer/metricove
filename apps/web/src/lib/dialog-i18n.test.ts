import { afterEach, describe, expect, it } from 'bun:test';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { get } from 'svelte/store';
import { parse } from 'svelte/compiler';
import { analyticsRanges } from './analytics';
import { financeFrequencies } from './finance';
import { setUserPreferences, t } from './i18n';
import { utilityMeterTypes } from './utilities';
import { vehicleCostCategories, vehicleFuelTypes, vehiclePeriods } from './vehicles';

type Node = Record<string, unknown>;

const sourceRoot = fileURLToPath(new URL('../', import.meta.url));
const unchangedGerman = new Set([
	'AI', 'CHF', 'EUR', 'GBP', 'USD', 'LPG', 'Volvo', 'Diesel', 'Hybrid', 'Metricove', 'Metricove MCP', '· Metricove',
	'Core', 'Tracking', 'Import', 'Status', 'Details', 'Name', 'Text', 'Service', 'Gas',
	'Optional', 'optional', '(optional)', '(optional,', 'Moment', 'Start', 'Total',
	'Admin', 'Version', 'Transport', 'Minimum', 'Maximum', 'Name A–Z', 'Schema v',
	'Claude', 'Gemini', 'OpenRouter', 'https://provider.example/v1'
]);

function files(directory: string): string[] {
	return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
		const path = join(directory, entry.name);
		return entry.isDirectory() ? files(path) : path.endsWith('.svelte') ? [path] : [];
	});
}

const dialogFiles = files(sourceRoot).filter((path) =>
	/<Modal\b|<ConfirmModal\b|<dialog\b|role="(?:alert)?dialog"|window\.confirm/.test(readFileSync(path, 'utf8'))
);

function walk(value: unknown, visit: (node: Node, attribute: boolean) => void, attribute = false): void {
	if (!value || typeof value !== 'object') return;
	if (Array.isArray(value)) {
		for (const item of value) walk(item, visit, attribute);
		return;
	}
	const node = value as Node;
	visit(node, attribute);
	for (const child of Object.values(node)) walk(child, visit, attribute || node.type === 'Attribute');
}

function literalMessages(value: unknown): string[] {
	if (!value || typeof value !== 'object') return [];
	const node = value as Node;
	if (node.type === 'Literal' && typeof node.value === 'string') return [node.value];
	if (node.type === 'ConditionalExpression') return [...literalMessages(node.consequent), ...literalMessages(node.alternate)];
	return [];
}

afterEach(() => setUserPreferences({ language: 'en' }));

describe('dialog localization audit', () => {
	it('provides German translations for static messages used by every dialog route', () => {
		setUserPreferences({ language: 'de' });
		const translate = get(t);
		const missing: string[] = [];
		for (const path of dialogFiles) {
			const ast = parse(readFileSync(path, 'utf8'));
			walk(ast, (node) => {
				const callee = node.callee as Node | undefined;
				const key = node.key as Node | undefined;
				const left = node.left as Node | undefined;
				const messages = node.type === 'AssignmentExpression' && /(?:error|message|notice)$/i.test(String(left?.name))
					? literalMessages(node.right)
					: node.type === 'NewExpression' && callee?.name === 'Error'
						? literalMessages((node.arguments as unknown[])[0])
					: node.type === 'Property' && (key?.name === 'label' || key?.value === 'label')
					? literalMessages(node.value)
					: node.type === 'CallExpression' && ['$t', 'translate'].includes(String(callee?.name))
						? literalMessages((node.arguments as unknown[])[0]) : [];
				for (const message of messages) {
					if (/[A-Za-z]/.test(message) && !unchangedGerman.has(message) && translate(message) === message) {
						missing.push(`${relative(sourceRoot, path)}: ${message}`);
					}
				}
			});
		}
		expect(dialogFiles.length).toBeGreaterThan(15);
		expect([...new Set(missing)]).toEqual([]);
	});

	it('keeps dialog text and accessible labels connected to the translation store', () => {
		const untranslated: string[] = [];
		const invariantText = new Set(['M', 'Metric', 'ove', 'km', 'mi']);
		for (const path of dialogFiles) {
			const source = readFileSync(path, 'utf8');
			const ast = parse(source);
			walk(ast.html, (node) => {
				const attributes = node.attributes as Node[] | undefined;
				if (!['Modal', 'ConfirmModal', 'dialog'].includes(String(node.name)) &&
					!attributes?.some((attribute) => attribute.name === 'role' &&
						(attribute.value as Node[] | undefined)?.some((value) => value.data === 'dialog'))) return;
				walk(node, (child, attribute) => {
					if (child.type === 'Text' && !attribute) {
						const text = String(child.data).trim();
						if (/[A-Za-z]/.test(text) && !invariantText.has(text)) untranslated.push(`${path}: ${text}`);
					}
					if (child.type === 'Attribute' && ['title', 'description', 'message', 'confirmLabel', 'aria-label'].includes(String(child.name))) {
						for (const value of child.value as Node[] ?? []) {
							if (value.type === 'Text' && /[A-Za-z]/.test(String(value.data))) untranslated.push(`${path}: ${value.data}`);
						}
					}
				});
			});
		}
		expect([...new Set(untranslated)]).toEqual([]);
	});

	it('translates selectable labels without changing the values sent to the API', () => {
		setUserPreferences({ language: 'de' });
		const translate = get(t);
		const options = [...analyticsRanges, ...financeFrequencies, ...utilityMeterTypes, ...vehicleFuelTypes, ...vehicleCostCategories, ...vehiclePeriods];
		for (const option of options) {
			if (!unchangedGerman.has(option.label)) expect(translate(option.label)).not.toBe(option.label);
		}
		for (const message of ['Breakfast', 'Lunch', 'Dinner', 'Expenses by currency', 'Utility consumption', 'Distance travelled']) {
			expect(translate(message)).not.toBe(message);
		}
		for (const subject of ['transaction', 'budget', 'category', 'recurring entry']) {
			const message = `Delete this ${subject}? This cannot be undone.`;
			expect(translate(message)).not.toBe(message);
		}
		expect(utilityMeterTypes[0]).toMatchObject({ value: 'electricity', label: 'Electricity', unit: 'kWh' });
	});

	it('interpolates complete confirmations and preserves user content when switching languages', () => {
		const name = 'Alex {name} <script>';
		const confirmation = 'Remove {name} from this household? This cannot be undone.';
		setUserPreferences({ language: 'de' });
		expect(get(t)(confirmation, { name })).toBe(`Alex {name} <script> aus diesem Haushalt entfernen? Dies kann nicht rückgängig gemacht werden.`);
		expect(get(t)('Delete “{name}” and all its measurements?', { name: 'Weight' })).toBe('„Weight“ und alle zugehörigen Messungen löschen?');
		expect(get(t)('Set {month} budget', { month: '2026-10' })).toBe('Budget für 2026-10 festlegen');
		setUserPreferences({ language: 'en' });
		expect(get(t)(confirmation, { name })).toBe(`Remove ${name} from this household? This cannot be undone.`);
	});
});
