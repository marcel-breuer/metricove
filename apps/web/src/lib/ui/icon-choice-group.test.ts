import { afterEach, describe, expect, it } from 'bun:test';
import { plugin } from 'bun';
import { compile } from 'svelte/compiler';
import { render } from 'svelte/server';
import { setUserPreferences } from '../i18n';

plugin({
	name: 'icon-choice-server-rendering',
	setup(build) {
		build.onLoad({ filter: /\.svelte$/ }, async ({ path }) => ({
			contents: compile(await Bun.file(path).text(), { filename: path, generate: 'server' }).js.code,
			loader: 'js'
		}));
	}
});

const { default: IconChoiceGroup } = await import('./IconChoiceGroup.svelte');

afterEach(() => setUserPreferences({ language: 'en' }));

describe('icon choice groups', () => {
	it('renders localized appearance choices as a selected native radio group with icons', () => {
		setUserPreferences({ language: 'de' });
		const { body } = render(IconChoiceGroup, {
			props: {
				id: 'appearance',
				label: 'Appearance',
				value: 'dark',
				options: [
					{ value: 'system', label: 'Use device setting', icon: 'monitor' },
					{ value: 'light', label: 'Light', icon: 'sun' },
					{ value: 'dark', label: 'Dark', icon: 'moon' }
				],
				onchange: () => {}
			}
		});

		expect(body).toMatch(/<legend[^>]*>Darstellung<\/legend>/);
		expect(body).toContain('Geräteeinstellung verwenden');
		expect(body).toContain('Hell');
		expect(body).toContain('Dunkel');
		expect(body.match(/type="radio"/g)).toHaveLength(3);
		expect(body.match(/<svg viewBox="0 0 24 24"/g)).toHaveLength(3);

		const selected = body.match(/<input[^>]*id="appearance-dark"[^>]*>/)?.[0];
		expect(selected).toContain('name="appearance"');
		expect(selected).toContain('checked');
	});

	it('renders language markers with full localized names instead of flag-only labels', () => {
		setUserPreferences({ language: 'de' });
		const { body } = render(IconChoiceGroup, {
			props: {
				id: 'language',
				label: 'Language',
				value: 'de',
				options: [
					{ value: 'en', label: 'English', icon: 'language', marker: 'EN' },
					{ value: 'de', label: 'German', icon: 'language', marker: 'DE' }
				],
				onchange: () => {}
			}
		});

		expect(body).toMatch(/<legend[^>]*>Sprache<\/legend>/);
		expect(body).toContain('Englisch');
		expect(body).toContain('Deutsch');
		expect(body).toContain('>EN</span>');
		expect(body).toContain('>DE</span>');
		expect(body.match(/type="radio"/g)).toHaveLength(2);
		expect(body.match(/<svg viewBox="0 0 24 24"/g)).toHaveLength(2);

		const selected = body.match(/<input[^>]*id="language-de"[^>]*>/)?.[0];
		expect(selected).toContain('name="language"');
		expect(selected).toContain('checked');
	});
});
