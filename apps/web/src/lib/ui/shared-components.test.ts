import { afterEach, describe, expect, it } from 'bun:test';
import { plugin } from 'bun';
import { createRawSnippet } from 'svelte';
import { compile } from 'svelte/compiler';
import { render } from 'svelte/server';
import { setUserPreferences } from '../i18n';

plugin({
	name: 'shared-component-rendering',
	setup(build) {
		build.onLoad({ filter: /\.svelte$/ }, async ({ path }) => ({
			contents: compile(await Bun.file(path).text(), { filename: path, generate: 'server' }).js.code,
			loader: 'js'
		}));
	}
});

const { default: Button } = await import('./Button.svelte');
const { default: EmptyState } = await import('./EmptyState.svelte');
const { default: Disclosure } = await import('./Disclosure.svelte');
const { default: PageHeading } = await import('./PageHeading.svelte');

const text = (value: string) => createRawSnippet(() => ({ render: () => `<span>${value}</span>` }));

afterEach(() => setUserPreferences({ language: 'en' }));

describe('shared UI components', () => {
	it('renders navigation buttons as links with the shared button styling', () => {
		const { body } = render(Button, { props: { href: '/modules/tracking', variant: 'secondary', size: 'small', children: text('Open') } });
		expect(body).toContain('<a href="/modules/tracking"');
		expect(body).toContain('border-control-line bg-surface');
		expect(body).not.toContain('<button');
	});

	it('keeps action buttons as native buttons', () => {
		const { body } = render(Button, { props: { type: 'submit', children: text('Save') } });
		expect(body).toContain('<button');
		expect(body).toContain('type="submit"');
	});

	it('translates empty state and disclosure copy', () => {
		setUserPreferences({ language: 'de' });
		expect(render(EmptyState, { props: { title: 'Nothing in the pantry yet' } }).body).toContain('Dein Vorrat ist noch leer');
		expect(render(Disclosure, { props: { summary: 'Filter and sort', children: text('x') } }).body).toContain('Filtern und sortieren');
	});

	it('renders page titles without trailing periods in both languages', () => {
		setUserPreferences({ language: 'de' });
		const { body } = render(PageHeading, { props: { eyebrow: 'Household access', title: 'Manage members' } });
		expect(body).toContain('>Mitglieder verwalten</h1>');
	});
});
