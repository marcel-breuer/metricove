import { derived, get, writable } from 'svelte/store';
import { setNumberFormatPreferences } from '#lib/number-format';

export type UiLanguage = 'en' | 'de';
export type NumberLocale = 'de-DE' | 'en-US' | 'en-GB';
export type CurrencyDisplay = 'code' | 'symbol';

const german: Record<string, string> = {
	'Overview': 'Übersicht', 'Profile': 'Profil', 'Members': 'Mitglieder', 'Module rights': 'Modulrechte',
	'Modules': 'Module', 'General settings': 'Allgemeine Einstellungen', 'Invitations': 'Einladungen',
	'Assistant': 'Assistent', 'Tracking': 'Tracking', 'Tracker history': 'Tracker-Verlauf', 'Module': 'Modul',
	'Home': 'Startseite', 'Your profile': 'Dein Profil', 'Sign out': 'Abmelden', 'Sign in': 'Anmelden',
	'Overview of your household': 'Übersicht über deinen Haushalt', 'Your Metricove profile.': 'Dein Metricove-Profil.',
	'Account settings': 'Kontoeinstellungen', 'Update the name and email address used for this household account.': 'Ändere den Namen und die E-Mail-Adresse dieses Haushaltskontos.',
	'Household administrator': 'Haushaltsadministrator', 'Loading your profile…': 'Profil wird geladen…',
	'Profile details': 'Profildaten', 'Your name': 'Dein Name', 'Email address': 'E-Mail-Adresse',
	'Save profile': 'Profil speichern', 'Saving…': 'Wird gespeichert…', 'Change password': 'Passwort ändern',
	'Changing…': 'Wird geändert…', 'Confirm your current password and choose a new password with at least 12 characters.': 'Bestätige dein aktuelles Passwort und wähle ein neues mit mindestens 12 Zeichen.',
	'Current password': 'Aktuelles Passwort', 'New password': 'Neues Passwort', 'Confirm new password': 'Neues Passwort bestätigen',
	'Language': 'Sprache', 'English': 'Englisch', 'German': 'Deutsch', 'Number and date format': 'Zahlen- und Datumsformat',
	'Currency': 'Währung', 'Currency display': 'Währungsdarstellung', 'Currency code (EUR)': 'Währungscode (EUR)',
	'Currency symbol (€)': 'Währungssymbol (€)', 'English (United States)': 'Englisch (USA)',
	'English (United Kingdom)': 'Englisch (Vereinigtes Königreich)', 'German (Germany)': 'Deutsch (Deutschland)',
	'Your profile has been updated.': 'Dein Profil wurde aktualisiert.', 'Profile could not be saved.': 'Profil konnte nicht gespeichert werden.',
	'Profile is unavailable.': 'Profil ist nicht verfügbar.', 'Reload this page and try again.': 'Lade die Seite neu und versuche es erneut.',
	'External access': 'Externer Zugriff', 'MCP access tokens': 'MCP-Zugriffstoken', 'Human approval': 'Manuelle Freigabe',
	'Pending MCP actions': 'Ausstehende MCP-Aktionen', 'Your tokens': 'Deine Token', 'No tokens created yet.': 'Noch keine Token erstellt.',
	'Create MCP token': 'MCP-Token erstellen', 'Creating…': 'Wird erstellt…', 'Token name': 'Tokenname',
	'Available scopes': 'Verfügbare Berechtigungen', 'Expires in days': 'Läuft ab in Tagen', 'Never expires': 'Läuft nie ab',
	'Revoke': 'Widerrufen', 'Approve': 'Freigeben', 'Reject': 'Ablehnen', 'Cancel approval': 'Freigabe zurücknehmen',
	'Date': 'Datum', 'Reading': 'Messwert', 'Requested by': 'Angefordert von', 'Note': 'Notiz', 'Expires': 'Läuft ab',
	'No pending actions.': 'Keine ausstehenden Aktionen.', 'Return home': 'Zurück zur Startseite',
	'Light': 'Hell', 'Dark': 'Dunkel', 'System': 'System', 'Search Metricove': 'Metricove durchsuchen',
	'Open menu': 'Menü öffnen', 'Close menu': 'Menü schließen', 'Quick add': 'Schnelleingabe',
	'No MCP scopes are currently available. Ask a household admin to enable a module and grant your account access.': 'Derzeit sind keine MCP-Berechtigungen verfügbar. Bitte eine Administration, ein Modul zu aktivieren und deinem Konto Zugriff zu geben.',
	'Copy this token now. It will not be shown again.': 'Kopiere dieses Token jetzt. Es wird nicht erneut angezeigt.',
	'Use this endpoint with a Metricove bearer token. Tokens are shown once, can expire within one year or never expire, and can be revoked here. MCP uses Metricove-managed tokens; OAuth authorization-server discovery is not provided.': 'Verwende diesen Endpunkt mit einem Metricove-Bearer-Token. Token werden nur einmal angezeigt, können innerhalb eines Jahres ablaufen oder unbegrenzt gültig sein und hier widerrufen werden. MCP verwendet von Metricove verwaltete Token; OAuth-Autorisierungsserver-Erkennung wird nicht angeboten.',
	'A connected MCP client cannot approve its own writes. Review each utility reading here before the client can save it.': 'Ein verbundener MCP-Client kann eigene Schreibvorgänge nicht freigeben. Prüfe hier jeden Zählerstand, bevor der Client ihn speichern kann.',
	'Approved. The connected MCP client can complete this action until it expires.': 'Freigegeben. Der verbundene MCP-Client kann diese Aktion bis zum Ablauf ausführen.',
	'View permitted tracking summaries': 'Freigegebene Tracking-Übersichten ansehen',
	'View permitted monthly finance totals': 'Freigegebene monatliche Finanzsummen ansehen',
	'View permitted utility readings': 'Freigegebene Zählerstände ansehen',
	'Prepare utility readings for your approval': 'Zählerstände zur Freigabe vorbereiten',
	'View permitted vehicle odometer summaries': 'Freigegebene Kilometerstände ansehen',
	'Your modules': 'Deine Module', 'Administration': 'Verwaltung', 'Loading account': 'Konto wird geladen',
	'Profile & account': 'Profil und Konto', 'Your household, in one private place': 'Dein Haushalt, an einem privaten Ort',
	'Search your household with AI': 'Haushalt mit KI durchsuchen', 'Search your household with AI…': 'Haushalt mit KI durchsuchen…',
	'Search with AI': 'Mit KI suchen', 'Skip to content': 'Zum Inhalt springen', 'Try again': 'Erneut versuchen',
	'Core': 'Kern', 'Finance': 'Finanzen', 'Recipes': 'Rezepte', 'Meal Planning': 'Essensplanung', 'Pantry': 'Vorrat',
	'Shopping': 'Einkauf', 'Utilities': 'Versorger', 'Vehicles': 'Fahrzeuge', 'Analytics': 'Analysen',
	'Import': 'Import', 'AI': 'KI', 'Metricove': 'Metricove', 'Developer reference': 'Entwicklerdokumentation',
	'Metricove MCP': 'Metricove MCP', 'MCP documentation': 'MCP-Dokumentation',
	'Your password has been changed.': 'Dein Passwort wurde geändert.',
	'Password could not be changed.': 'Passwort konnte nicht geändert werden.', 'Password could not be changed. Check your connection and try again.': 'Passwort konnte nicht geändert werden. Prüfe deine Verbindung und versuche es erneut.',
	'Profile could not be saved. Check your connection and try again.': 'Profil konnte nicht gespeichert werden. Prüfe deine Verbindung und versuche es erneut.',
	'Update your Metricove household profile.': 'Aktualisiere dein Metricove-Haushaltsprofil.',
	'The MCP token could not be created.': 'Das MCP-Token konnte nicht erstellt werden.',
	'OpenAPI reference': 'OpenAPI-Referenz', 'Connection': 'Verbindung', 'Transport': 'Transport',
	'Available read tools': 'Verfügbare Lesewerkzeuge', 'Approved utility reading writes': 'Freigegebene Zählerstand-Schreibvorgänge',
	'Open API reference': 'API-Referenz öffnen', 'Read the MCP documentation': 'MCP-Dokumentation lesen',
	'Connect an MCP-compatible client to the tools your account is currently allowed to use.': 'Verbinde einen MCP-kompatiblen Client mit den Werkzeugen, für die dein Konto aktuell berechtigt ist.',
	'Create a Metricove MCP token in Profile → MCP access tokens. Configure the client to send it as an HTTP bearer token. The token is shown only once. Metricove does not currently provide OAuth discovery or an OAuth authorization flow.': 'Erstelle ein Metricove-MCP-Token unter Profil → MCP-Zugriffstoken. Richte den Client so ein, dass er es als HTTP-Bearer-Token sendet. Das Token wird nur einmal angezeigt. Metricove bietet derzeit weder OAuth-Erkennung noch einen OAuth-Autorisierungsablauf.',
	'The server exposes tools based on the token scopes and your current module permissions. It checks permissions again on every call. A token alone never grants access.': 'Der Server stellt Werkzeuge entsprechend den Token-Berechtigungen und deinen aktuellen Modulrechten bereit. Bei jedem Aufruf werden die Rechte erneut geprüft. Ein Token allein gewährt keinen Zugriff.',
	'Returns visible income, expenses and net totals, grouped by currency.': 'Gibt sichtbare Einnahmen, Ausgaben und Salden nach Währung gruppiert zurück.',
	'Returns up to 8 visible meters with 5 recent readings each. Notes, photos and alert thresholds are excluded.': 'Gibt bis zu 8 sichtbare Zähler mit jeweils 5 aktuellen Messwerten zurück. Notizen, Fotos und Alarmgrenzwerte sind ausgeschlossen.',
	'Returns up to 8 visible trackers with 5 recent entries each. Text values and free-text notes are excluded.': 'Gibt bis zu 8 sichtbare Tracker mit jeweils 5 aktuellen Einträgen zurück. Textwerte und Freitextnotizen sind ausgeschlossen.',
	'Returns up to 8 visible vehicles with 5 recent odometer readings each. Costs, insurance and maintenance details are excluded.': 'Gibt bis zu 8 sichtbare Fahrzeuge mit jeweils 5 aktuellen Kilometerständen zurück. Kosten, Versicherungs- und Wartungsdetails sind ausgeschlossen.',
	'Arguments:': 'Argumente:', 'A connected MCP client cannot approve its own writes. The account owner must approve it in the profile before it can be completed.': 'Ein verbundener MCP-Client kann eigene Schreibvorgänge nicht freigeben. Der Kontoinhaber muss den Vorgang im Profil freigeben, bevor er abgeschlossen werden kann.',
	'OpenAPI generates the HTTP API reference from Laravel routes and validation. It requires an active Metricove session. MCP tool schemas are negotiated through MCP and are listed above because OpenAPI does not describe that JSON-RPC tool catalogue.': 'OpenAPI generiert die HTTP-API-Referenz aus Laravel-Routen und Validierungsregeln. Dafür ist eine aktive Metricove-Sitzung erforderlich. MCP-Werkzeugschemas werden über MCP ausgehandelt und sind oben aufgeführt, da OpenAPI diesen JSON-RPC-Werkzeugkatalog nicht beschreibt.',
	'Appearance': 'Darstellung', 'Use device setting': 'Geräteeinstellung verwenden', 'Metricove home': 'Metricove-Startseite', 'On': 'An', 'Off': 'Aus',
	'About Metricove': 'Über Metricove', 'YOUR HOUSEHOLD, IN FOCUS': 'DEIN HAUSHALT IM BLICK',
	'One private place for your household.': 'Ein privater Ort für deinen Haushalt.',
	'A simpler way to keep the things that matter close at hand.': 'Ein einfacher Weg, Wichtiges griffbereit zu halten.',
	'ONE HOUSEHOLD · YOUR SPACE': 'EIN HAUSHALT · DEIN BEREICH',
	'Welcome back': 'Willkommen zurück', 'Enter the email address and password registered for this installation.': 'Gib die für diese Installation registrierte E-Mail-Adresse und dein Passwort ein.',
	'Your account is tied to this Metricove installation.': 'Dein Konto gehört zu dieser Metricove-Installation.',
	'Password': 'Passwort', 'Forgot password?': 'Passwort vergessen?', 'Signing in…': 'Anmeldung läuft…',
	'Too many sign-in attempts. Wait a minute and try again.': 'Zu viele Anmeldeversuche. Warte eine Minute und versuche es erneut.',
	'Email address or password is incorrect.': 'E-Mail-Adresse oder Passwort ist falsch.',
	'Sign-in is unavailable. Check your connection and try again.': 'Anmeldung ist nicht verfügbar. Prüfe deine Verbindung und versuche es erneut.',
	'Sign in to your Metricove household.': 'Melde dich bei deinem Metricove-Haushalt an.',
	'Your household, at a glance': 'Dein Haushalt auf einen Blick', 'A clearer view of everyday life.': 'Mehr Überblick über den Alltag.',
	'Private by design. Your enabled modules appear here when their records and dashboards are ready.': 'Privat gestaltet. Aktivierte Module erscheinen hier, sobald ihre Einträge und Übersichten bereit sind.',
	'Loading your household…': 'Haushalt wird geladen…', 'Your workspace is ready.': 'Dein Arbeitsbereich ist bereit.',
	'No modules are available to your account yet. An administrator can enable household modules and grant each member their own capabilities.': 'Deinem Konto sind noch keine Module zugewiesen. Eine Administration kann Haushaltsmodule aktivieren und jedem Mitglied eigene Rechte geben.',
	'Manage modules': 'Module verwalten', 'Your dashboard is taking shape.': 'Deine Übersicht entsteht.',
	'Your modules are enabled and ready. Open Analytics to configure charts from the data you can access.': 'Deine Module sind aktiviert und bereit. Öffne Analysen, um Diagramme für deine verfügbaren Daten einzurichten.',
	'Open Analytics': 'Analysen öffnen', 'View access': 'Leserecht', 'Create access': 'Erstellrecht', 'Edit access': 'Bearbeitungsrecht', 'Manage access': 'Verwaltungsrecht',
	'module available to you': 'Modul für dich verfügbar', 'modules available to you': 'Module für dich verfügbar',
	'Household setup, authentication, members, settings and module access.': 'Haushaltseinrichtung, Anmeldung, Mitglieder, Einstellungen und Modulzugriff.',
	'Flexible trackers, typed values, units and history.': 'Flexible Tracker mit typisierten Werten, Einheiten und Verlauf.',
	'Private and household electricity, gas and water meters with reading history.': 'Private und gemeinsame Strom-, Gas- und Wasserzähler mit Messverlauf.',
	'Private and household vehicles, odometer history, insurance and costs.': 'Private und gemeinsame Fahrzeuge mit Kilometerverlauf, Versicherung und Kosten.',
	'Private and household transactions, categories, budgets and recurring entries.': 'Private und gemeinsame Buchungen, Kategorien, Budgets und wiederkehrende Einträge.',
	'Recipes, ingredients, servings and preparation steps.': 'Rezepte, Zutaten, Portionen und Zubereitungsschritte.',
	'Weekly meals using recipes or free-text dishes.': 'Wochenplanung mit Rezepten oder frei eingegebenen Gerichten.',
	'Private and household food stock, quantities, storage and best-before dates.': 'Private und gemeinsame Vorräte mit Mengen, Lagerung und Mindesthaltbarkeitsdaten.',
	'Private and shared shopping lists, quantities, history and reversible check-off.': 'Private und gemeinsame Einkaufslisten mit Mengen, Verlauf und rückgängig machbarem Abhaken.',
	'Private cross-module charts for data the member can view.': 'Private modulübergreifende Diagramme für Daten, die das Mitglied sehen darf.',
	'Validated utility reading CSV imports, scoped exports and local meter-photo suggestions.': 'Geprüfte CSV-Importe für Zählerstände, bereichsbezogene Exporte und lokale Zählerfoto-Vorschläge.',
	'Endpoint': 'Endpunkt',
	'Preview expires after 10 minutes. Review its meter, date, value and note under Profile → Pending MCP actions.': 'Die Vorschau läuft nach 10 Minuten ab. Prüfe Zähler, Datum, Wert und Notiz unter Profil → Ausstehende MCP-Aktionen.',
	'Access is rechecked at preview, approval and completion. Revoked tokens, suspended accounts, disabled modules or changed permissions stop the operation.': 'Der Zugriff wird bei Vorschau, Freigabe und Abschluss erneut geprüft. Widerrufene Token, gesperrte Konten, deaktivierte Module oder geänderte Rechte verhindern den Vorgang.',
	'The MCP token has been revoked.': 'Das MCP-Token wurde widerrufen.',
	'The action is approved. The MCP client can now complete it.': 'Die Aktion ist freigegeben. Der MCP-Client kann sie jetzt abschließen.',
	'The action was rejected.': 'Die Aktion wurde abgelehnt.',
	'Invite a member.': 'Lade ein Mitglied ein.', 'Send an invitation': 'Einladung senden', 'Pending invitations': 'Ausstehende Einladungen',
	'Household members': 'Haushaltsmitglieder', 'Manage members.': 'Mitglieder verwalten.', 'Recent access history': 'Letzte Zugriffsänderungen',
	'Optional module capabilities': 'Berechtigungen für optionale Module', 'Available modules': 'Verfügbare Module',
	'AI Assistant': 'KI-Assistent', 'Ask your household assistant.': 'Frage deinen Haushaltsassistenten.',
	'Provider': 'Anbieter', 'Saved context': 'Gespeicherter Kontext', 'Answer': 'Antwort',
	'This module is ready for its data tools.': 'Dieses Modul ist bereit für seine Werkzeuge.',
	'You do not have access to this module.': 'Du hast keinen Zugriff auf dieses Modul.',
	'Analytics dashboard': 'Analyseübersicht', 'Add a chart': 'Diagramm hinzufügen',
	'Your dashboard is ready to configure.': 'Deine Übersicht kann jetzt eingerichtet werden.',
	'Finance module': 'Finanzmodul', 'A clearer view of your money.': 'Mehr Überblick über deine Finanzen.',
	'Transactions': 'Buchungen', 'Monthly budgets': 'Monatsbudgets', 'Categories': 'Kategorien', 'Recurring entries': 'Wiederkehrende Einträge',
	'On a schedule': 'Nach Zeitplan', 'Bring your meter history with you.': 'Übernimm deinen Zählerverlauf.',
	'No accessible meters.': 'Keine zugänglichen Zähler.', 'Utility reading CSV': 'Zählerstände aus CSV',
	'Map CSV columns': 'CSV-Spalten zuordnen', 'Review before saving': 'Vor dem Speichern prüfen',
	'Fix these rows and preview again': 'Korrigiere diese Zeilen und erstelle eine neue Vorschau.',
	'A week that works': 'Eine Woche, die passt', 'Your stock': 'Dein Vorrat',
	'Recipes worth keeping': 'Rezepte, die bleiben', 'Your recipes': 'Deine Rezepte',
	'The list everyone can trust': 'Eine Liste, auf die sich alle verlassen können',
	'Your lists stay private to the right members.': 'Deine Listen bleiben für die richtigen Mitglieder sichtbar.',
	'Your lists': 'Deine Listen', 'Create a list': 'Liste erstellen', 'Start with one thing.': 'Fang mit einer Sache an.',
	'What do you need?': 'Was brauchst du?', 'Suggestions from meal plans': 'Vorschläge aus Essensplänen',
	'Make your own measures.': 'Erstelle eigene Messgrößen.', 'Your trackers': 'Deine Tracker', 'No trackers yet.': 'Noch keine Tracker.',
	'Start with a tracker.': 'Lege einen Tracker an.', 'Every mile, in one place.': 'Jeder Kilometer an einem Ort.',
	'Your vehicles': 'Deine Fahrzeuge', 'Set up a vehicle.': 'Richte ein Fahrzeug ein.', 'Know what your meters use.': 'Behalte deinen Verbrauch im Blick.',
	'Meters': 'Zähler', 'Add a meter.': 'Füge einen Zähler hinzu.', 'Distance between readings': 'Strecke zwischen Messungen',
	'Record mileage.': 'Kilometerstand erfassen.', 'Chronological timeline': 'Zeitlicher Verlauf',
	'Maintenance': 'Wartung', 'Insurance': 'Versicherung', 'Costs': 'Kosten', 'This vehicle is private to its authorized members.': 'Dieses Fahrzeug ist nur für berechtigte Mitglieder sichtbar.',
	'This vehicle could not be opened.': 'Dieses Fahrzeug konnte nicht geöffnet werden.', 'Usage between readings': 'Verbrauch zwischen Messungen',
	'Record the register.': 'Zählerstand erfassen.', 'Chronological record': 'Chronologischer Verlauf',
	'This meter is private to its authorized members.': 'Dieser Zähler ist nur für berechtigte Mitglieder sichtbar.',
	'This meter could not be opened.': 'Dieser Zähler konnte nicht geöffnet werden.',
	'You cannot open this tracker.': 'Du kannst diesen Tracker nicht öffnen.', 'Update tracker fields.': 'Tracker-Felder bearbeiten.',
	'Edit measurement': 'Messung bearbeiten', 'Record a measurement': 'Messung erfassen', 'Measurement history': 'Messverlauf',
	'Create your account': 'Konto erstellen', 'Reset your password': 'Setze dein Passwort zurück.',
	'Choose a new password': 'Wähle ein neues Passwort', 'Set up your household': 'Richte deinen Haushalt ein',
	'Create your administrator account': 'Erstelle dein Administratorkonto', 'Welcome,': 'Willkommen,',
	'This household is already set up.': 'Dieser Haushalt ist bereits eingerichtet.'
};

export const uiLanguage = writable<UiLanguage>('en');
export const numberLocale = writable<NumberLocale>('en-US');
export const defaultCurrency = writable('EUR');
export const currencyDisplay = writable<CurrencyDisplay>('code');
uiLanguage.subscribe((language) => {
	if (typeof document !== 'undefined') document.documentElement.lang = language;
});
numberLocale.subscribe((locale) => setNumberFormatPreferences(locale, get(currencyDisplay)));
currencyDisplay.subscribe((display) => setNumberFormatPreferences(get(numberLocale), display));
export const t = derived(uiLanguage, (language) => (message: string) =>
	language === 'de' ? german[message] ?? message : message
);

export function setUserPreferences(user: {
	language?: UiLanguage;
	number_locale?: NumberLocale;
	default_currency?: string;
	currency_display?: CurrencyDisplay;
}): void {
	const language = user.language === 'de' ? 'de' : 'en';
	const locale = ['de-DE', 'en-US', 'en-GB'].includes(user.number_locale ?? '')
		? user.number_locale as NumberLocale
		: language === 'de' ? 'de-DE' : 'en-US';
	uiLanguage.set(language);
	numberLocale.set(locale);
	defaultCurrency.set(['EUR', 'USD', 'GBP', 'CHF'].includes(user.default_currency ?? '') ? user.default_currency! : 'EUR');
	currencyDisplay.set(user.currency_display === 'symbol' ? 'symbol' : 'code');
	setNumberFormatPreferences(locale, user.currency_display === 'symbol' ? 'symbol' : 'code');
	setDateFormatLocale(locale);
	if (typeof document !== 'undefined') document.documentElement.lang = language;
}

export function formatDate(value: string | number | Date, options?: Intl.DateTimeFormatOptions): string {
	return new Intl.DateTimeFormat(preferredDateLocale, options).format(value instanceof Date ? value : new Date(value));
}

export function formatDateTime(value: string | number | Date): string {
	return new Intl.DateTimeFormat(preferredDateLocale, { dateStyle: 'short', timeStyle: 'short' }).format(value instanceof Date ? value : new Date(value));
}

let preferredDateLocale = 'en-US';

// Keep date and number formatting on the same per-user locale.
export function setDateFormatLocale(locale: string): void {
	preferredDateLocale = locale;
}

numberLocale.subscribe(setDateFormatLocale);
