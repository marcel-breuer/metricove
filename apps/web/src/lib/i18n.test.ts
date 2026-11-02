import { afterEach, describe, expect, it } from 'bun:test';
import { get } from 'svelte/store';
import { defaultCurrency, formatDate, numberLocale, setUserPreferences, t, uiLanguage } from './i18n';

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
		expect(translate('Open dashboard')).toBe('Dashboard öffnen');
		expect(translate('Network access')).toBe('Netzwerkzugriff');
		expect(translate('Allowed IP addresses and networks')).toBe('Erlaubte IP-Adressen und Netzwerke');
		expect(translate('Monthly distance')).toBe('Monatliche Fahrstrecke');
		expect(translate('Average monthly projection')).toBe('Durchschnittliche Monatsprognose');
		expect(translate('Monthly distance chart description', { unit: 'km' })).toBe('Monatliche Fahrstrecke in km für die letzten 12 Monate, gefolgt von einer Durchschnittsprognose für die nächsten 3 Monate.');
		expect(translate('Add two visible odometer readings from the last 12 months to see monthly distance and an average projection for the next three months.')).toBe('Erfasse zwei sichtbare Kilometerstände aus den letzten 12 Monaten, um die monatliche Fahrstrecke und eine Durchschnittsprognose für die nächsten drei Monate zu sehen.');
		expect(translate('Choose the capabilities available to {name}.', { name: 'Alex' })).toBe('Lege fest, welche Berechtigungen Alex erhält.');
		expect(translate('{capability} permission for {module}', { capability: translate('View'), module: translate('Finance') })).toBe('Berechtigung „Ansehen“ für Finanzen');
	});
});

describe('locale date formatting', () => {
	it('formats date-only values in the selected locale without shifting the calendar day', () => {
		setUserPreferences({ language: 'de', number_locale: 'de-DE' });
		expect(formatDate('2024-01-02', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'America/Los_Angeles' })).toBe('02.01.2024');

		setUserPreferences({ language: 'en', number_locale: 'en-US' });
		expect(formatDate('2024-01-02', { day: '2-digit', month: '2-digit', year: 'numeric' })).toBe('01/02/2024');
	});

	it('keeps timestamp formatting on the existing locale-aware path', () => {
		setUserPreferences({ language: 'en', number_locale: 'en-US' });
		expect(formatDate('2024-01-02T00:30:00.000Z', { day: 'numeric', month: 'numeric', year: 'numeric', timeZone: 'America/Los_Angeles' }))
			.toBe('1/1/2024');
	});
});
