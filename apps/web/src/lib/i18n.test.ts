import { afterEach, describe, expect, it } from 'bun:test';
import { get } from 'svelte/store';
import { defaultCurrency, numberLocale, setUserPreferences, t, uiLanguage } from './i18n';

afterEach(() => setUserPreferences({ language: 'en', number_locale: 'en-US', default_currency: 'EUR', currency_display: 'code' }));

describe('interface translations', () => {
	it('translates headings used across account, admin and module routes', () => {
		setUserPreferences({ language: 'de', number_locale: 'de-DE', default_currency: 'CHF', currency_display: 'symbol' });
		const translate = get(t);

		expect(get(uiLanguage)).toBe('de');
		expect(get(numberLocale)).toBe('de-DE');
		expect(get(defaultCurrency)).toBe('CHF');
		expect(translate('Invite a member.')).toBe('Lade ein Mitglied ein.');
		expect(translate('Analytics dashboard')).toBe('Analyseübersicht');
		expect(translate('A clearer view of your money.')).toBe('Mehr Überblick über deine Finanzen.');
		expect(translate('Your stock')).toBe('Dein Vorrat');
		expect(translate('The list everyone can trust')).toBe('Eine Liste, auf die sich alle verlassen können');
	});
});
