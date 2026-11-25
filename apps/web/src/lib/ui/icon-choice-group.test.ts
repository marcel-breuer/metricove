import { afterEach, describe, expect, it } from 'bun:test';
import { render } from 'svelte/server';
import { setUserPreferences } from '../i18n';

import './test-rendering';

const { default: IconChoiceGroup } = await import('./IconChoiceGroup.svelte');

afterEach(() => setUserPreferences({ language: 'en' }));

describe('icon choice groups', () => {
	it('renders localized appearance choices as a labeled radio group with icons', () => {
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

		expect(body).toMatch(/<legend id="appearance-legend"[^>]*>Darstellung<\/legend>/);
		expect(body).toContain('aria-labelledby="appearance-legend"');
		expect(body).toContain('role="radiogroup"');
		expect(body).toContain('Geräteeinstellung verwenden');
		expect(body).toContain('Hell');
		expect(body).toContain('Dunkel');
		expect(body.match(/role="radio"/g)).toHaveLength(3);
		expect(body.match(/<svg viewBox="0 0 24 24"/g)).toHaveLength(3);

		const selected = body.match(/<button[^>]*id="appearance-dark"[^>]*>/)?.[0];
		expect(selected).toContain('aria-checked="true"');
		const formValue = body.match(/<input[^>]*name="appearance"[^>]*>/)?.[0];
		expect(formValue).toContain('value="dark"');
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
		expect(body.match(/role="radio"/g)).toHaveLength(2);
		expect(body.match(/<svg viewBox="0 0 24 24"/g)).toHaveLength(2);

		const selected = body.match(/<button[^>]*id="language-de"[^>]*>/)?.[0];
		expect(selected).toContain('aria-checked="true"');
		const formValue = body.match(/<input[^>]*name="language"[^>]*>/)?.[0];
		expect(formValue).toContain('value="de"');
	});
});
