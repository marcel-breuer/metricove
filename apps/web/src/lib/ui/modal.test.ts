import { afterEach, describe, expect, it } from 'bun:test';
import { createRawSnippet } from 'svelte';
import { render } from 'svelte/server';
import { setUserPreferences } from '../i18n';

import './test-rendering';

const { default: Modal } = await import('./Modal.svelte');
const { default: BackLink } = await import('./BackLink.svelte');

const text = (value: string) => createRawSnippet(() => ({ render: () => `<span>${value}</span>` }));

afterEach(() => setUserPreferences({ language: 'en' }));

describe('Modal', () => {
	it('renders the compact dialog and its visible heading', () => {
		const { body } = render(Modal, { props: { open: true, title: 'Rename list', children: text('Body') } });
		expect(body).toContain('data-size="default"');
		expect(body).not.toContain('data-size="fullscreen"');
		expect(body).toContain('role="dialog"');
		expect(body).toContain('data-slot="dialog-title"');
		expect(body).toContain('role="heading" aria-level="2"');
		expect(body).toContain('Rename list');
		expect(body).not.toContain('aria-describedby');
		expect(body).not.toContain('<footer');
	});

	it('renders the fullscreen variant with a scrolling body, description and sticky footer', () => {
		const { body } = render(Modal, {
			props: { open: true, title: 'Edit recipe', description: 'Tomato soup', size: 'fullscreen', children: text('Fields'), footer: text('Save') }
		});
		expect(body).toContain('data-size="fullscreen"');
		expect(body).toContain('overflow-y-auto overscroll-contain');
		expect(body).toContain('data-slot="dialog-description"');
		expect(body).toContain('Tomato soup');
		const content = body.indexOf('overscroll-contain');
		const footer = body.indexOf('<footer');
		expect(footer).toBeGreaterThan(content);
		expect(body.slice(footer)).toContain('<span>Save</span>');
	});

	it.each(['default', 'wide'] as const)('keeps %s dialog actions outside the scrolling body', (size) => {
		const { body } = render(Modal, { props: { open: true, title: 'Edit', size, children: text('Fields'), footer: text('Save') } });
		const content = body.indexOf('overflow-y-auto overscroll-contain');
		const footer = body.indexOf('<footer');
		expect(content).toBeGreaterThan(-1);
		expect(footer).toBeGreaterThan(content);
		expect(body.slice(footer)).toContain('<span>Save</span>');
	});

	it('translates the close button in German', () => {
		setUserPreferences({ language: 'de' });
		const { body } = render(Modal, { props: { open: true, title: 'Test', size: 'fullscreen', children: text('x') } });
		expect(body).toContain('aria-label="Dialog schließen"');
	});
});

describe('BackLink', () => {
	it('renders a translated link back to the list', () => {
		setUserPreferences({ language: 'de' });
		const { body } = render(BackLink, { props: { href: '/modules/recipes', label: 'All recipes' } });
		expect(body).toContain('href="/modules/recipes"');
		expect(body).toContain('Alle Rezepte');
	});
});
