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
		expect(translate('Manage modules.')).toBe('Module verwalten.');
		expect(translate('Network access')).toBe('Netzwerkzugriff');
		expect(translate('Allowed IP addresses and networks')).toBe('Erlaubte IP-Adressen und Netzwerke');
		expect(translate('Choose the capabilities available to {name}.', { name: 'Alex' })).toBe('Lege fest, welche Berechtigungen Alex erhält.');
		expect(translate('{capability} permission for {module}', { capability: translate('View'), module: translate('Finance') })).toBe('Berechtigung „Ansehen“ für Finanzen');
	});
});
