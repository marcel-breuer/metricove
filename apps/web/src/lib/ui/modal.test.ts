import { afterEach, describe, expect, it } from 'bun:test';
import { plugin } from 'bun';
import { createRawSnippet } from 'svelte';
import { compile } from 'svelte/compiler';
import { render } from 'svelte/server';
import { setUserPreferences } from '../i18n';

plugin({
	name: 'modal-server-rendering',
	setup(build) {
		build.onLoad({ filter: /\.svelte$/ }, async ({ path }) => ({
			contents: compile(await Bun.file(path).text(), { filename: path, generate: 'server' }).js.code,
			loader: 'js'
		}));
	}
});

const { default: Modal } = await import('./Modal.svelte');
const { default: BackLink } = await import('./BackLink.svelte');

const text = (value: string) => createRawSnippet(() => ({ render: () => `<span>${value}</span>` }));

afterEach(() => setUserPreferences({ language: 'en' }));

describe('Modal', () => {
	it('keeps the compact default size and names the dialog by its visible title', () => {
		const { body } = render(Modal, { props: { title: 'Rename list', children: text('Body') } });
		expect(body).toContain('data-size="default"');
		expect(body).not.toContain('data-size="fullscreen"');
		const labelledBy = body.match(/aria-labelledby="([^"]+)"/)?.[1];
		expect(labelledBy).toBeTruthy();
		expect(body).toContain(`id="${labelledBy}"`);
		expect(body).not.toContain('aria-describedby');
		expect(body).not.toContain('<footer');
	});

	it('renders the fullscreen variant with a scrolling body, description and sticky footer', () => {
		const { body } = render(Modal, {
			props: { title: 'Edit recipe', description: 'Tomato soup', size: 'fullscreen', children: text('Fields'), footer: text('Save') }
		});
		expect(body).toContain('data-size="fullscreen"');
		expect(body).toContain('overflow-y-auto overscroll-contain');
		const describedBy = body.match(/aria-describedby="([^"]+)"/)?.[1];
		expect(describedBy).toBeTruthy();
		expect(body).toContain(`id="${describedBy}"`);
		const content = body.indexOf('overscroll-contain');
		const footer = body.indexOf('<footer');
		expect(footer).toBeGreaterThan(content);
		expect(body.slice(footer)).toContain('<span>Save</span>');
	});

	it('translates the close button in German', () => {
		setUserPreferences({ language: 'de' });
		const { body } = render(Modal, { props: { title: 'Test', size: 'fullscreen', children: text('x') } });
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
