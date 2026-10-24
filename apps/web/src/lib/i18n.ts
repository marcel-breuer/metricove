Warning: truncated output (original token count: 21953)
Total output lines: 431

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
	'Core': 'Kern', 'Finance': 'Finanzen', 'Recipes': 'Rezepte', 'Meal Planning': 'Essensplanung', 'Pantry': 'Vorrat', 'Tasks': 'Aufgaben',
	'Shopping': 'Einkauf', 'Utilities': 'Versorger', 'Vehicles': 'Fahrzeuge', 'Analytics': 'Analysen',
	'Reminders': 'Erinnerungen', 'Household': 'Haushalt', 'Upcoming': 'Demnächst', 'unread reminders': 'ungelesene Erinnerungen',
	'Keep upcoming bills, service dates, utility alerts and pantry dates in one place. Reminders only show records you can currently view.': 'Behalte anstehende Rechnungen, Servicetermine, Zählerwarnungen und Vorratsdaten an einem Ort. Erinnerungen zeigen nur Datensätze, die du aktuell ansehen darfst.',
	'Loading reminders…': 'Erinnerungen werden geladen…', 'No upcoming reminders. They appear here when enabled modules have an obligation or alert within your reminder window.': 'Keine anstehenden Erinnerungen. Sie erscheinen hier, wenn aktivierte Module innerhalb deines Erinnerungszeitraums einen Termin oder Hinweis haben.',
	'Refresh': 'Aktualisieren', 'Due': 'Fällig', 'Open record': 'Datensatz öffnen', 'Mark read': 'Als gelesen markieren', 'Dismiss': 'Ausblenden',
	'Reminder settings': 'Erinnerungseinstellungen', 'Email is optional and uses the SMTP settings configured for this installation.': 'E-Mail ist optional und verwendet die SMTP-Einstellungen dieser Installation.',
	'Send reminder emails': 'Erinnerungen per E-Mail senden', 'Email details are sent only while you retain permission to view the source record.': 'E-Mail-Details werden nur gesendet, solange du den Quelldatensatz ansehen darfst.',
	'Days in advance': 'Tage im Voraus', 'Choose between 0 and 30 days. Utility threshold alerts are created when a new reading exceeds the meter threshold.': 'Wähle zwischen 0 und 30 Tagen. Zählerwarnungen entstehen, wenn ein neuer Messwert den eingestellten Grenzwert überschreitet.',
	'Reminder sources': 'Erinnerungsquellen', 'Vehicle insurance and service': 'Fahrzeugversicherung und Service', 'Utility threshold alerts': 'Zählerwarnungen', 'Pantry best-before dates': 'Mindesthaltbarkeitsdaten im Vorrat', 'Recurring finance entries': 'Wiederkehrende Finanzposten',
	'Save reminder settings': 'Erinnerungseinstellungen speichern', 'Reminder preferences saved.': 'Erinnerungseinstellungen gespeichert.', 'Reminder preferences updated.': 'Erinnerungseinstellungen aktualisiert.',
	'Reminder preferences could not be saved.': 'Erinnerungseinstellungen konnten nicht gespeichert werden.', 'Reminder preferences could not be saved. Check your connection and try again.': 'Erinnerungseinstellungen konnten nicht gespeichert werden. Prüfe deine Verbindung und versuche es erneut.',
	'Reminders are unavailable.': 'Erinnerungen sind nicht verfügbar.', 'Reminders are unavailable. Check your connection and try again.': 'Erinnerungen sind nicht verfügbar. Prüfe deine Verbindung und versuche es erneut.',
	'The reminder could not be updated.': 'Die Erinnerung konnte nicht aktualisiert werden.', 'Reminder updated.': 'Erinnerung aktualisiert.',
	'Next due': 'Nächster Termin',
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
	'Finance import': 'Finanzimport', 'Bring bank transactions into Finance': 'Importiere Bankbuchungen in deine Finanzen',
	'Payee': 'Zahlungsempfänger',
	'Preview CSV or CAMT.053 statements before saving. CSV amounts use a decimal point or comma without thousands separators: positive values are expenses and negative values are income. New transactions remain private to your account.': 'Prüfe CSV- oder CAMT.053-Kontoauszüge vor dem Speichern. CSV-Beträge verwenden einen Dezimalpunkt oder ein Dezimalkomma ohne Tausendertrennzeichen: positive Werte sind Ausgaben, negative Werte Einnahmen. Neue Buchungen bleiben in deinem Konto privat.',
	'Finance statement file': 'Finanzdatei', '(CSV or CAMT.053 XML · max 10 MB / 1,000 transactions)': '(CSV oder CAMT.053 XML · max. 10 MB / 1.000 Buchungen)',
	'Inspect or preview statement': 'Datei prüfen oder Vorschau erstellen', 'transactions checked': 'Buchungen geprüft', 'duplicates': 'Duplikate',
	'Default categories when a row has no category': 'Standardkategorien ohne Kategorieangabe', 'Choose an expense category': 'Ausgabenkategorie wählen', 'Choose an income category': 'Einnahmekategorie wählen',
	'Required: date, description, amount and currency. Payee and category are optional.': 'Pflichtfelder: Datum, Beschreibung, Betrag und Währung. Zahlungsempfänger und Kategorie sind optional.',
	'Transaction date': 'Buchungsdatum', 'Signed amount': 'Vorzeichenbehafteter Betrag', 'Payee (optional)': 'Zahlungsempfänger (optional)', 'Category (optional)': 'Kategorie (optional)',
	'Choose a column': 'Spalte wählen', 'Do not map': 'Nicht zuordnen', 'Preview mapped transactions': 'Zugeordnete Buchungen prüfen', 'Update selected rows': 'Auswahl aktualisieren', 'Select': 'Auswählen', 'Not selectable': 'Nicht auswählbar',
	'Selected': 'Ausgewählt', 'Not selected': 'Nicht ausgewählt', 'Duplicate': 'Duplikat', 'Select transaction row {row}': 'Buchungszeile {row} auswählen',
	'Choose a statement file, inspect its columns if it is CSV, then review the rows here before saving.': 'Wähle eine Kontoauszugsdatei. Prüfe bei CSV die Spalten und kontrolliere hier die Buchungen vor dem Speichern.',
	'Review these rows before importing': 'Prüfe diese Zeilen vor dem Import', 'Confirm import · {count} selected transactions': 'Import bestätigen · {count} ausgewählte Buchungen',
	'Import Create and Finance View and Create access are required to upload bank statements.': 'Zum Hochladen von Kontoauszügen sind Import-Erstellen sowie Finanzen-Ansehen und -Erstellen erforderlich.',
	'Import View access is required.': 'Import-Ansehen ist erforderlich.', 'Grant access to an enabled source module before importing or exporting its records.': 'Erteile Zugriff auf ein aktiviertes Quellmodul, bevor du dessen Einträge importierst oder exportierst.',
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
	'Load more measurements': 'Weitere Messungen laden', 'Showing {count} measurements. Values keep the field label and unit that were active when they were saved.': 'Es werden {count} Messungen angezeigt. Werte behalten die Feldbezeichnung und Einheit, die beim Speichern galten.',
	'Create your account': 'Konto erstellen', 'Reset your password': 'Setze dein Passwort zurück.',
	'Choose a new password': 'Wähle ein neues Passwort', 'Set up your household': 'Richte deinen Haushalt ein',
	'Create your administrator account': 'Erstelle dein Administratorkonto', 'Welcome,': 'Willkommen,',
	'This household is already set up.': 'Dieser Haushalt ist bereits eingerichtet.'
};

const routeGerman: Record<string, string> = {
	'Household settings': 'Haushaltseinstellungen', 'Manage modules.': 'Module verwalten.',
	'Optional modules start disabled. Enabling one gives every active member all four module capabilities; you can narrow access per member afterward.': 'Optionale Module sind zunächst deaktiviert. Beim Aktivieren erhalten alle aktiven Mitglieder alle vier Modulberechtigungen; anschließend kannst du den Zugriff pro Mitglied einschränken.',
	'Optional modules start disabled. Enabling one gives every active member all four module capabilities; you can narrow access per member afterward. Disabling a module preserves its data.': 'Optionale Module sind zunächst deaktiviert. Beim Aktivieren erhalten alle aktiven Mitglieder alle vier Modulberechtigungen; anschließend kannst du den Zugriff pro Mitglied einschränken. Beim Deaktivieren bleiben die Daten erhalten.',
	'General settings.': 'Allgemeine Einstellungen.',
	'Configure the assistant provider for this installation. Provider credentials are encrypted at rest and never shown again after saving.': 'Richte den Assistentenanbieter für diese Installation ein. Zugangsdaten werden verschlüsselt gespeichert und nach dem Speichern nicht erneut angezeigt.',
	'Member access': 'Mitgliederzugriff', 'Module rights.': 'Modulrechte.',
	'Choose the capabilities available to {name}.': 'Lege fest, welche Berechtigungen {name} erhält.',
	'Choose the capabilities available to this household member.': 'Lege fest, welche Berechtigungen dieses Haushaltsmitglied erhält.',
	' Administrators need explicit module rights too; administrator status does not grant access to private records.': ' Auch Administratoren benötigen ausdrückliche Modulrechte; der Adminstatus gewährt keinen Zugriff auf private Einträge.',
	'{capability} permission for {module}': 'Berechtigung „{capability}“ für {module}',
	'{module} capabilities': 'Berechtigungen für {module}',
	'View': 'Ansehen', 'Create': 'Erstellen', 'Manage': 'Verwalten',
	'Read module records the member may access.': 'Moduldatensätze lesen, auf die das Mitglied zugreifen darf.',
	'Add new module records.': 'Neue Moduldatensätze hinzufügen.',
	'Change permitted module records.': 'Freigegebene Moduldatensätze ändern.',
	'Manage module-level settings and explicit record shares.': 'Moduleinstellungen und ausdrückliche Einzelfreigaben verwalten.',
	'Enabled': 'Aktiviert',
	'Your session is unavailable. Try again.': 'Deine Sitzung ist nicht verfügbar. Versuche es erneut.',
	'Module navigation is unavailable. Try again.': 'Die Modulnavigation ist nicht verfügbar. Versuche es erneut.',
	'Metricove is unavailable. Check your connection and try again.': 'Metricove ist nicht verfügbar. Prüfe deine Verbindung und versuche es erneut.',
	'Reload this page and try again.': 'Lade diese Seite neu und versuche es erneut.',
	'Key stored; leave blank to keep it': 'Schlüssel gespeichert; leer lassen, um ihn beizubehalten', 'Enter a key for the selected provider': 'Gib einen Schlüssel für den ausgewählten Anbieter ein',
	'Resend invitation to {email}': 'Einladung erneut an {email} senden', 'Revoke invitation to {email}': 'Einladung für {email} widerrufen',
	'Edit {kind} context': '{kind}-Kontext bearbeiten', 'all questions': 'alle Fragen', 'series': 'Reihe', 'range': 'Zeitraum',
	'{module} module': 'Modul {module}', 'A clear title': 'Ein aussagekräftiger Titel',
	'income': 'Einnahme', 'expense': 'Ausgabe', 'private': 'privat', 'shared': 'gemeinsam', 'Uncategorized': 'Ohne Kategorie', 'Expired': 'Abgelaufen',
	'Metricove overview': 'Metricove-Übersicht', 'Mobile main navigation': 'Mobile Hauptnavigation', 'never': 'nie',
	' · You': ' · Du', ' · household': ' · Haushalt', ' · required': ' · erforderlich', ' — alert': ' — Warnung',
	'required': 'erforderlich', '{series} series': '{series} Reihe', 'field': 'Feld', 'fields': 'Felder',
	'correction': 'Korrektur', 'corrections': 'Korrekturen', 'over': 'über',
	'{count} days since the previous reading': 'Seit dem vorherigen Messwert: {count} Tage', 'Meter photo for {date}': 'Zählerfoto vom {date}',
	'+': '+', '−': '−',
	'Active': 'Aktiv', 'Administrator': 'Administration', 'Configured': 'Eingerichtet', 'Not configured': 'Nicht eingerichtet',
	'Add a food item to keep its quantity, location and best-before date in one place.': 'Füge ein Lebensmittel hinzu und verwalte Menge, Lagerort und Mindesthaltbarkeit an einem Ort.',
	'Add a transaction.': 'Buchung hinzufügen.', 'Add category': 'Kategorie hinzufügen', 'Add item': 'Artikel hinzufügen', 'Add schedule': 'Zeitplan hinzufügen',
	'Add to the plan': 'Zum Plan hinzufügen', 'Add widget': 'Widget hinzufüge…6953 tokens truncated…cht verfügbar. Die Vorschläge zeigen die vollständigen Rezeptmengen.', 'Pantry was not read. Select the option above and review again to account for stock you can view.': 'Vorräte wurden nicht berücksichtigt. Wähle oben die Option aus und prüfe erneut, damit sichtbare Bestände einbezogen werden.', 'Pantry was unavailable; the full amount is shown.': 'Vorräte waren nicht verfügbar; es wird die volle Menge angezeigt.', 'Partial cover': 'Teilversicherung', 'Past best-before': 'Mindesthaltbarkeit überschritten', 'per interval)': 'je Intervall)', 'Period': 'Zeitraum', 'Period ends': 'Zeitraum endet', 'Period starts': 'Zeitraum beginnt', 'Petrol': 'Benzin', 'Plan': 'Planen',
	'Plan breakfast, lunch and dinner with a saved recipe or a simple dish name. Your plan works on its own.': 'Plane Frühstück, Mittag- und Abendessen mit einem gespeicherten Rezept oder einem einfachen Gerichtnamen. Die Planung funktioniert eigenständig.', 'Prep time': 'Vorbereitungszeit', 'Preparation': 'Zubereitung', 'Preparation instructions': 'Zubereitungsschritte', 'prepares the reading but does not change data.': 'bereitet den Messwert vor, ändert aber keine Daten.', 'Previous': 'Zurück', 'Private': 'Privat',
	'Private meter records stay available only to their owner or members with the matching record grant.': 'Private Zählereinträge sind nur für die besitzende Person oder Mitglieder mit einer passenden Einzelfreigabe sichtbar.', 'Private meters are visible to their owner. Shared meters are visible to members with Utilities View access.': 'Private Zähler sind nur für ihre besitzende Person sichtbar. Gemeinsame Zähler sehen Mitglieder mit Leserechten für Versorger.', 'Private notes and preferences can personalize future answers. Context is isolated to your account and is never treated as an instruction.': 'Private Notizen und Einstellungen können künftige Antworten personalisieren. Der Kontext bleibt auf dein Konto beschränkt und wird nie als Anweisung behandelt.',
	'Private readings and meter photos remain hidden from administrators unless their owner grants access.': 'Private Messwerte und Zählerfotos bleiben für Administratoren verborgen, sofern die besitzende Person keinen Zugriff gewährt.', 'Private recipes and photos remain hidden from administrators unless you grant access. Household recipes require an active admin with Recipes Manage.': 'Private Rezepte und Fotos bleiben für Administratoren verborgen, sofern du keinen Zugriff gewährst. Haushaltsrezepte benötigen eine aktive Administration mit Rezept-Verwaltungsrechten.', 'Private records are visible to their owner and explicitly authorized members.': 'Private Einträge sind für ihre besitzende Person und ausdrücklich berechtigte Mitglieder sichtbar.', 'Private to me': 'Für mich privat',
	'Private trackers and entries remain hidden from administrators unless explicitly shared by their owner.': 'Private Tracker und Einträge bleiben für Administratoren verborgen, sofern die besitzende Person sie nicht ausdrücklich teilt.', 'Private transactions stay visible only to their owner unless access is granted for that record.': 'Private Buchungen sind nur für ihre besitzende Person sichtbar, sofern der Eintrag nicht freigegeben wurde.', 'Private vehicle, insurance, mileage and cost details are hidden from administrators unless the owner grants access.': 'Private Fahrzeug-, Versicherungs-, Kilometer- und Kostendaten bleiben für Administratoren verborgen, sofern die besitzende Person keinen Zugriff gewährt.',
	'Project context key': 'Projektschlüssel für Kontext', 'Project key': 'Projektschlüssel', 'Provider base URL': 'Basis-URL des Anbieters', 'Provider choice is controlled by this installation. No external provider is contacted unless its administrator configures one and marks it external.': 'Die Anbieterauswahl wird von dieser Installation gesteuert. Ein externer Anbieter wird erst kontaktiert, wenn eine Administration ihn einrichtet und als extern kennzeichnet.', 'Put back': 'Zurücklegen', 'Quantity': 'Menge', 'Quantity and unit:': 'Menge und Einheit:', 'Quantity not set': 'Menge nicht festgelegt', 'Quick entry': 'Schnelleingabe', 'Read-only data tools currently available:': 'Derzeit verfügbare schreibgeschützte Datenwerkzeuge:', 'Reading date': 'Messdatum', 'Reading history': 'Messverlauf', 'Ready when you are': 'Bereit, wenn du es bist', 'Reason for correction': 'Grund der Korrektur', 'recent readings': 'aktuelle Messwerte',
	'Recipe editor': 'Rezepteditor', 'Recipe photo': 'Rezeptfoto', 'Recipe you can view': 'Für dich sichtbares Rezept', 'Recipes is enabled, but your account does not have Recipes View access. Ask a household administrator to grant the required module permission.': 'Rezepte sind aktiviert, aber dein Konto hat keine Leserechte. Bitte eine Haushaltsadministration um die entsprechende Modulberechtigung.', 'Recipient email': 'E-Mail-Adresse des Empfängers', 'Record due entry': 'Fälligen Eintrag erfassen',
	'Record income and spending, set a monthly category budget and keep recurring charges ready to record when they are due.': 'Erfasse Einnahmen und Ausgaben, lege ein monatliches Kategoriebudget fest und halte wiederkehrende Zahlungen bereit, bis sie fällig sind.', 'Record the current odometer to begin the vehicle timeline.': 'Erfasse den aktuellen Kilometerstand, um den Fahrzeugverlauf zu beginnen.', 'Recorded at': 'Erfasst am', 'Refresh': 'Aktualisieren',
	'Register units are fixed by type to prevent accidental conversions: electricity uses kWh; gas and water use m³. Enter cumulative readings from the meter display.': 'Die Zählereinheiten sind festgelegt, damit keine versehentlichen Umrechnungen erfolgen: Strom wird in kWh, Gas und Wasser in m³ erfasst. Gib den kumulativen Stand auf der Zähleranzeige ein.',
	'Registration': 'Registrierung', 'Reload': 'Neu laden', 'Remove': 'Entfernen', 'Remove current photo': 'Aktuelles Foto entfernen', 'Remove stored API key (disable the provider first)': 'Gespeicherten API-Schlüssel entfernen (deaktiviere zuerst den Anbieter)',
	'Removed fields stay in past measurements. Once a field has values, its type and unit cannot change; add a new field for a new unit.': 'Entfernte Felder bleiben in früheren Messungen erhalten. Sobald ein Feld Werte enthält, können Typ und Einheit nicht mehr geändert werden. Füge für eine neue Einheit ein Feld hinzu.', 'Rename': 'Umbenennen', 'Rename list': 'Liste umbenennen', 'Repair': 'Reparieren', 'Requests and answers are not stored as chat history. The assistant cannot change records.': 'Anfragen und Antworten werden nicht als Chatverlauf gespeichert. Der Assistent kann keine Einträge ändern.', 'Required': 'Erforderlich', 'Requires:': 'Benötigt:', 'Resend': 'Erneut senden', 'Reset links are short-lived and can only be used once.': 'Links zum Zurücksetzen sind nur kurz gültig und können nur einmal verwendet werden.', 'Reset password': 'Passwort zurücksetzen', 'Result': 'Ergebnis', 'Retire': 'Stilllegen', 'Return to household members': 'Zurück zu den Haushaltsmitgliedern', 'Return to overview': 'Zurück zur Übersicht', 'Return to sign in': 'Zurück zur Anmeldung', 'Return to your profile': 'Zurück zu deinem Profil',
	'Review recipe ingredients before adding them. Free-text meals stay untouched, pantry stock is read-only, and units are never converted.': 'Prüfe die Rezeptzutaten vor dem Hinzufügen. Frei eingegebene Gerichte bleiben unverändert, Vorräte werden nur gelesen und Einheiten nie umgerechnet.', 'revoked': 'widerrufen', 'Rights stay stored while disabled. Enabling the module resets all active members to full capabilities.': 'Rechte bleiben bei deaktiviertem Modul gespeichert. Beim Aktivieren erhalten alle aktiven Mitglieder wieder sämtliche Berechtigungen.', 'Row': 'Zeile', 'rows checked for': 'geprüfte Zeilen für', 'Save': 'Speichern', 'Save cost': 'Kosten speichern', 'Save policy dates': 'Versicherungsdaten speichern', 'Save service record': 'Wartungseintrag speichern', 'Save the current register value. The next reading will establish the first usage interval.': 'Speichere den aktuellen Zählerstand. Der nächste Messwert bildet das erste Verbrauchsintervall.', 'saved values ·': 'gespeicherte Werte ·', 'saves the approved preview.': 'speichert die freigegebene Vorschau.', 'Schedule active': 'Zeitplan aktiv', 'Schema builder': 'Schema-Editor', 'Schema builder · new revision': 'Schema-Editor · neue Version', 'Schema revisions': 'Schema-Versionen', 'Schema v': 'Schema v', 'Search recipes and ingredients': 'Rezepte und Zutaten suchen', 'Selected:': 'Ausgewählt:',
	'Send an invitation using only their email address. They will choose their own name and password.': 'Versende eine Einladung nur mit der E-Mail-Adresse. Die eingeladene Person wählt selbst ihren Namen und ihr Passwort.', 'Service': 'Service', 'Service log': 'Wartungsprotokoll', 'Service name': 'Servicebezeichnung', 'Servings': 'Portionen', 'Setup is complete': 'Einrichtung abgeschlossen', 'Setup status could not be loaded.': 'Der Einrichtungsstatus konnte nicht geladen werden.', 'Shared': 'Gemeinsam', 'Shared household': 'Mit dem Haushalt geteilt', 'Shared household meter': 'Gemeinsamer Haushaltszähler', 'Shared household vehicle': 'Gemeinsames Haushaltsfahrzeug', 'Shared list': 'Gemeinsame Liste', 'Shared templates are available to members with tracking access; entries stay private to each owner.': 'Gemeinsame Vorlagen stehen Mitgliedern mit Tracker-Zugriff zur Verfügung; Einträge bleiben für jede Person privat.', 'Shared with household': 'Mit dem Haushalt geteilt', 'Shopping View access is required.': 'Leserechte für Einkaufen sind erforderlich.', 'Show items': 'Artikel anzeigen', 'Showing the first 25 rows of': 'Die ersten 25 Zeilen von', 'Showing up to 200 records for': 'Es werden bis zu 200 Einträge angezeigt für', 'Since previous': 'Seit dem vorherigen', 'Some linked recipes are unavailable to your account and were skipped.': 'Einige verknüpfte Rezepte sind für dein Konto nicht verfügbar und wurden übersprungen.', 'Some quantities cannot be represented exactly in Shopping and were skipped.': 'Einige Mengen lassen sich beim Einkaufen nicht exakt darstellen und wurden übersprungen.', 'Sort items': 'Artikel sortieren', 'Source': 'Quelle', 'Spending': 'Ausgaben', 'Starting quantity': 'Anfangsmenge', 'Starts on': 'Beginnt am', 'Stock history': 'Bestandsverlauf', 'Stock lines': 'Bestandspositionen', 'Storage location': 'Lagerort', 'Stored in': 'Aufbewahrt in', 'Streamable HTTP · stateless': 'Streamable HTTP · zustandslos', 'Subtract visible Pantry stock in the preview': 'Sichtbare Vorräte in der Vorschau abziehen', 'Text': 'Text',
	'The current photo will be removed when you save.': 'Das aktuelle Foto wird beim Speichern entfernt.', 'The invitation link expires according to this household’s settings and cannot be reused.': 'Der Einladungslink läuft gemäß den Einstellungen dieses Haushalts ab und kann nicht erneut verwendet werden.', 'The latest': 'Die letzten', 'The latest 100 role and account status changes.': 'Die letzten 100 Änderungen an Rollen und Kontostatus.', 'The linked recipe is not available to your account right now. You can update servings or notes, or convert this slot to a dish name.': 'Das verknüpfte Rezept ist derzeit für dein Konto nicht verfügbar. Du kannst Portionen oder Notizen ändern oder diesen Eintrag in einen Gerichtnamen umwandeln.',
	'The invitation link expires according to this household\'s settings and cannot be reused.': 'Der Einladungslink läuft gemäß den Einstellungen dieses Haushalts ab und kann nicht erneut verwendet werden.',
	'The module is enabled and you have access. Its records and entry forms are being delivered in the upcoming module work.': 'Das Modul ist aktiviert und du hast Zugriff. Einträge und Formulare werden in einer kommenden Modulerweiterung bereitgestellt.', 'The reset link expires and cannot be reused after a successful reset.': 'Der Link zum Zurücksetzen läuft ab und kann nach erfolgreichem Zurücksetzen nicht erneut verwendet werden.', 'There are no household members to show.': 'Es gibt keine Haushaltsmitglieder, die angezeigt werden können.', 'There are no pending invitations.': 'Es gibt keine ausstehenden Einladungen.', 'This history only includes entries you own or that an owner explicitly shared with you.': 'Dieser Verlauf enthält nur Einträge, die dir gehören oder die eine besitzende Person ausdrücklich mit dir geteilt hat.',
	'This installation uses an external provider. Your question and any data requested through an assistant tool will be sent to that provider only after you confirm sharing for the request.': 'Diese Installation verwendet einen externen Anbieter. Deine Frage und über ein Assistentenwerkzeug angeforderte Daten werden erst nach deiner Bestätigung für diese Anfrage an den Anbieter gesendet.',
	'This source is no longer available to your account. Its records are not loaded. The widget will return when view access is restored.': 'Diese Quelle ist für dein Konto nicht mehr verfügbar. Ihre Einträge werden nicht geladen. Das Widget wird wieder angezeigt, sobald du erneut Leserechte erhältst.', 'This week': 'Diese Woche', 'Time range': 'Zeitraum', 'Tires': 'Reifen', 'Title': 'Titel', 'to buy': 'zu kaufen', 'To buy': 'Zu kaufen', 'Total': 'Gesamt',
	'Track odometer history, service, insurance dates and running costs. Keep a vehicle private or share it with the household.': 'Verfolge Kilometerstände, Wartungen, Versicherungsdaten und laufende Kosten. Halte ein Fahrzeug privat oder teile es mit dem Haushalt.', 'Tracker name': 'Trackername', 'Tracker unavailable': 'Tracker nicht verfügbar', 'Tracking module': 'Tracking-Modul', 'Type': 'Typ', 'Unit': 'Einheit', 'Units stay explicit and are never converted automatically.': 'Einheiten bleiben eindeutig und werden nie automatisch umgerechnet.',
	'Upload a UTF-8 CSV with a header row, then choose which columns contain the date, value and optional note. Dates use YYYY-MM-DD and values use a decimal point.': 'Lade eine UTF-8-CSV-Datei mit Kopfzeile hoch und wähle anschließend die Spalten für Datum, Wert und optionale Notiz. Datumsangaben verwenden JJJJ-MM-TT, Zahlen einen Dezimalpunkt.', 'Usage alert': 'Verbrauchswarnung', 'USD': 'USD', 'Use at least 12 characters.': 'Verwende mindestens 12 Zeichen.', 'Use saved context': 'Gespeicherten Kontext verwenden', 'Used by enabled modules:': 'Verwendet von aktivierten Modulen:', 'Utilities module': 'Versorger-Modul', 'Utilities View access is required.': 'Leserechte für Versorger sind erforderlich.', 'Valid from': 'Gültig ab', 'Valid until': 'Gültig bis', 'Value': 'Wert', 'Vehicle unavailable': 'Fahrzeug nicht verfügbar', 'Vehicles module': 'Fahrzeug-Modul', 'Vehicles View access is required.': 'Leserechte für Fahrzeuge sind erforderlich.', 'Version': 'Version', 'View access is not enabled.': 'Leserechte sind nicht aktiviert.', 'Visibility': 'Sichtbarkeit', 'Visible Pantry stock uses a different unit; nothing was subtracted.': 'Der sichtbare Vorrat verwendet eine andere Einheit; es wurde nichts abgezogen.', 'Week starting Monday': 'Woche ab Montag', 'What would you like to know?': 'Was möchtest du wissen?', 'Who can see it?': 'Wer kann das sehen?', 'Widget title': 'Diagrammtitel', 'Write a dish name': 'Gerichtnamen eingeben', 'Yes / no': 'Ja / Nein',
	'You can add private records, but browsing and budget summaries require an explicit Finance View grant.': 'Du kannst private Einträge hinzufügen. Zum Durchsuchen und Anzeigen von Budgetübersichten benötigst du jedoch ausdrücklich erteilte Leserechte für Finanzen.',
	'You can create a private meter if you have Create access, but browsing readings requires an explicit View grant.': 'Mit Erstellrechten kannst du einen privaten Zähler anlegen. Zum Durchsuchen der Messwerte benötigst du ausdrücklich erteilte Leserechte.',
	'You can create a private vehicle if you have Create access. Browsing records requires an explicit View grant.': 'Mit Erstellrechten kannst du ein privates Fahrzeug anlegen. Zum Durchsuchen der Einträge benötigst du ausdrücklich erteilte Leserechte.',
	'You can create a tracker if you have create access, but browsing schemas and histories requires an explicit View grant.': 'Mit Erstellrechten kannst du einen Tracker anlegen. Zum Durchsuchen von Schemas und Verläufen benötigst du ausdrücklich erteilte Leserechte.',
	'Your account can view recipes. Ask an administrator for Recipes Create to add private recipes or Recipes Manage to create household recipes.': 'Dein Konto kann Rezepte ansehen. Bitte eine Administration um Erstellrechte für Rezepte, um private Rezepte hinzuzufügen, oder um Verwaltungsrechte für Haushaltsrezepte.',
	'Your account does not have permission to create a meter. Ask an administrator for Utilities Create access.': 'Dein Konto darf keinen Zähler anlegen. Bitte eine Administration um Erstellrechte für Versorger.', 'Your account does not have permission to create a tracker. Ask an administrator for create access.': 'Dein Konto darf keinen Tracker anlegen. Bitte eine Administration um Erstellrechte.', 'Your account does not have permission to create a vehicle. Ask an administrator for Vehicles Create access.': 'Dein Konto darf kein Fahrzeug anlegen. Bitte eine Administration um Erstellrechte für Fahrzeuge.',
	'Your administrator account is active. Choose the features your household wants to use; you can change them later.': 'Dein Administratorkonto ist aktiv. Wähle die Funktionen für deinen Haushalt aus; du kannst diese später ändern.', 'Your data, at a glance': 'Deine Daten im Überblick', 'Your entries are private to your account by default.': 'Deine Einträge sind standardmäßig nur für dein Konto sichtbar.', 'Your module': 'Dein Modul', 'Your totals will appear here once you add income or spending.': 'Deine Summen erscheinen hier, sobald du Einnahmen oder Ausgaben erfasst.'
	,'0.500 or -1': '0.500 oder -1', 'Add context': 'Kontext hinzufügen', 'Added by me': 'Von mir hinzugefügt', 'Annual service': 'Jährlicher Service', 'Anything to remember?': 'Möchtest du etwas festhalten?', 'Apples, olive oil…': 'Äpfel, Olivenöl…',
	'Ask read-only questions about data you are allowed to see.': 'Stelle schreibgeschützte Fragen zu Daten, die du sehen darfst.', 'Authenticated documentation for connecting MCP clients to Metricove.': 'Anmeldung erforderliche Dokumentation zum Verbinden von MCP-Clients mit Metricove.', 'Bar chart of consumption between consecutive meter readings': 'Balkendiagramm zum Verbrauch zwischen aufeinanderfolgenden Zählerständen', 'Bar chart of odometer distance between readings': 'Balkendiagramm zur Fahrtstrecke zwischen Kilometerständen',
	'Build household trackers and record private measurements over time.': 'Erstelle Haushaltstracker und erfasse private Messwerte im Zeitverlauf.', 'Build private charts from the household data you can access.': 'Erstelle private Diagramme aus den Haushaltsdaten, auf die du Zugriff hast.', 'Choose a new password for your Metricove account.': 'Wähle ein neues Passwort für dein Metricove-Konto.', 'Choose your name and password to join a Metricove household.': 'Wähle deinen Namen und dein Passwort, um einem Metricove-Haushalt beizutreten.',
	'Close navigation': 'Navigation schließen', 'Configure household-wide Metricove settings.': 'Konfiguriere haushaltsweite Metricove-Einstellungen.', 'Context for this reading': 'Kontext zu diesem Messwert', 'Context for this vehicle': 'Kontext zu diesem Fahrzeug', 'Correction history': 'Korrekturverlauf', 'Create the first administrator for this Metricove household.': 'Erstelle die erste Administration für diesen Metricove-Haushalt.', 'Current recipe': 'Aktuelles Rezept', 'Daily car': 'Tägliche Autofahrt', 'Daily steps': 'Schritte pro Tag', 'Dinner': 'Abendessen',
	'e.g. budget-app': 'z. B. haushaltsbudget', 'Enable optional Metricove modules for this household.': 'Aktiviere optionale Metricove-Module für diesen Haushalt.', 'Enter a decimal value': 'Gib einen Dezimalwert ein', 'Explain why the original reading changed': 'Erkläre, warum der ursprüngliche Messwert geändert wurde', 'Filter pantry items by best-before date': 'Vorräte nach Mindesthaltbarkeitsdatum filtern', 'finely grated': 'fein gerieben', 'For example, tomato soup': 'Zum Beispiel Tomatensuppe', 'For example: How did my visible utility readings change over the last few entries?': 'Zum Beispiel: Wie haben sich meine sichtbaren Zählerstände in den letzten Einträgen verändert?', 'Fridge, pantry shelf': 'Kühlschrank, Vorratsregal', 'Groceries': 'Lebensmittel', 'Housing': 'Wohnen',
	'https://provider.example/v1': 'https://provider.example/v1', 'Insurance provider': 'Versicherungsanbieter', 'Internet plan': 'Internettarif', 'Invite members to your Metricove household.': 'Lade Mitglieder in deinen Metricove-Haushalt ein.', 'Keep household recipes, measured ingredients and preparation notes together.': 'Verwalte Haushaltsrezepte, abgewogene Zutaten und Zubereitungshinweise an einem Ort.', 'Keep private or shared vehicle, mileage, service and cost records.': 'Verwalte private oder gemeinsame Fahrzeuge, Kilometerstände, Wartungen und Kosten.', 'Keep shared shopping lists organized, current and easy to restore.': 'Halte gemeinsame Einkaufslisten übersichtlich und aktuell; abgehakte Einträge lassen sich einfach wiederherstellen.', 'kg, cans': 'kg, Dosen', 'kg, steps…': 'kg, Schritte…', 'light dark': 'hell dunkel', 'Location or meter details': 'Standort oder Zählerdetails', 'Main electricity': 'Hauptstromzähler', 'Main navigation': 'Hauptnavigation',
	'Manage member access for your Metricove household.': 'Verwalte den Zugriff der Mitglieder in deinem Metricove-Haushalt.', 'Manage module capabilities for a household member.': 'Verwalte die Modulberechtigungen eines Haushaltsmitglieds.', 'Meter display or context': 'Zähleranzeige oder Kontext', 'Meter reading history, consumption trends and corrections.': 'Zählerverlauf, Verbrauchsentwicklung und Korrekturen.', 'Monthly totals': 'Monatssummen', 'Next month': 'Nächster Monat', 'Next week': 'Nächste Woche', 'No alert': 'Keine Warnung', 'No maximum': 'Kein Höchstwert', 'No minimum': 'Kein Mindestwert', 'Oat milk': 'Hafermilch', 'Odometer correction history': 'Korrekturverlauf des Kilometerstands', 'Open navigation': 'Navigation öffnen', 'Optional': 'Optional', 'Pantry summary': 'Vorratsübersicht', 'Pasta': 'Nudeln', 'pcs, g, ml': 'Stk., g, ml',
	'Plan the week with free-text dishes or recipes you can access.': 'Plane die Woche mit frei eingegebenen Gerichten oder Rezepten, auf die du Zugriff hast.', 'Previous month': 'Voriger Monat', 'Previous week': 'Vorige Woche', 'Record a measurement and review its private history.': 'Erfasse einen Messwert und sieh dir den privaten Verlauf an.', 'Record household electricity, gas and water meter readings.': 'Erfasse Strom-, Gas- und Wasserzählerstände im Haushalt.', 'Remove ingredient': 'Zutat entfernen', 'Request a password reset for your Metricove account.': 'Fordere einen Link zum Zurücksetzen des Passworts für dein Metricove-Konto an.', 'Service invoice, fuel stop…': 'Werkstattrechnung, Tankstopp…', 'Shopping items': 'Einkaufsartikel', 'Shopping lists': 'Einkaufslisten', 'Steps': 'Schritte',
	'Track food stock, storage, quantities and best-before dates.': 'Verwalte Lebensmittelvorräte, Lagerorte, Mengen und Mindesthaltbarkeitsdaten.', 'Track private or shared income, spending, recurring entries and monthly budgets.': 'Verfolge private oder gemeinsame Einnahmen, Ausgaben, wiederkehrende Buchungen und Monatsbudgets.', 'Try tomato, soup or breakfast': 'Zum Beispiel Tomate, Suppe oder Frühstück', 'Used for dinner': 'Für das Abendessen verwendet', 'Vehicle details, odometer timeline, insurance, maintenance and costs.': 'Fahrzeugdetails, Kilometerverlauf, Versicherung, Wartung und Kosten.', 'Volvo': 'Volvo', 'Weekly groceries': 'Wocheneinkauf', 'What does this tracker help you follow?': 'Was möchtest du mit diesem Tracker verfolgen?', 'Write the steps in the order you follow them…': 'Beschreibe die Schritte in der Reihenfolge, in der du sie ausführst…', 'preference, goal, fact': 'Einstellung, Ziel, Fakt', 'Preview and confirm utility reading CSV imports.': 'CSV-Importe von Zählerständen prüfen und bestätigen.'
};

Object.assign(routeGerman, {
	'Coordinate private and shared household tasks, owners and repeatable routines.': 'Koordiniere private und gemeinsame Haushaltsaufgaben, Zuständigkeiten und wiederkehrende Routinen.',
	'Tasks are not available': 'Aufgaben sind nicht verfügbar', 'Ask a household administrator to enable Tasks and grant your account View access.': 'Bitte eine Haushaltsadministration, Aufgaben zu aktivieren und deinem Konto Leserechte zu erteilen.',
	'Edit task': 'Aufgabe bearbeiten', 'New task': 'Neue Aufgabe', 'Keep the next step clear.': 'Behalte den nächsten Schritt im Blick.', 'A task everyone can understand.': 'Eine Aufgabe, die alle verstehen.',
	'Task title': 'Aufgabentitel', 'e.g. Book the car service': 'z. B. Werkstatttermin vereinbaren', 'Details': 'Details', 'Add the context someone needs to get it done.': 'Ergänze die Informationen, die zur Erledigung nötig sind.',
	'Assignee': 'Zuständig', 'Unassigned': 'Nicht zugewiesen', 'Repeats': 'Wiederholung', 'Does not repeat': 'Keine Wiederholung', 'Every': 'Alle',
	'Save task': 'Aufgabe speichern', 'Add task': 'Aufgabe hinzufügen', 'Tasks are private by default. Household tasks are visible to members with access.': 'Aufgaben sind standardmäßig privat. Gemeinsame Aufgaben sehen Mitglieder mit entsprechender Berechtigung.',
	'Your tasks': 'Deine Aufgaben', 'Keep the household moving.': 'Halte den Haushalt in Bewegung.', 'Status': 'Status', 'Open': 'Offen', 'All': 'Alle', 'All priorities': 'Alle Prioritäten', 'Due date': 'Fällig am', 'Any date': 'Beliebiges Datum', 'Overdue': 'Überfällig', 'Upcoming': 'Demnächst', 'No due date': 'Kein Fälligkeitsdatum', 'Anyone': 'Alle', 'Sort by': 'Sortieren nach',
	'Loading tasks…': 'Aufgaben werden geladen…', 'Nothing on the list yet.': 'Noch keine Aufgaben vorhanden.', 'Add a household task or adjust your filters.': 'Füge eine Haushaltsaufgabe hinzu oder passe die Filter an.', 'Due': 'Fällig', 'Assigned to': 'Zuständig:',
	'Repeats {frequency}': 'Wiederholt sich: {frequency}', 'Repeats every {interval} {frequency}': 'Wiederholt sich alle {interval} {frequency}', 'Complete occurrence': 'Diesen Durchgang erledigen', 'Complete': 'Erledigen',
	'Task occurrence completed. The next one is scheduled.': 'Aufgabe erledigt. Der nächste Durchgang ist eingeplant.', 'Task completed.': 'Aufgabe erledigt.', 'Task updated.': 'Aufgabe aktualisiert.', 'Task created.': 'Aufgabe erstellt.', 'Task deleted.': 'Aufgabe gelöscht.',
	'Delete this task and its completion history?': 'Diese Aufgabe einschließlich Erledigungsverlauf löschen?', 'Completion history could not be loaded.': 'Der Erledigungsverlauf konnte nicht geladen werden.', 'Completion history': 'Erledigungsverlauf', 'No completed occurrences yet.': 'Noch keine Durchgänge erledigt.', 'Occurrence': 'Durchgang', 'Former member': 'Früheres Mitglied', 'Showing up to 200 visible tasks.': 'Es werden bis zu 200 sichtbare Aufgaben angezeigt.',
	'Low': 'Niedrig', 'Medium': 'Mittel', 'High': 'Hoch', 'Daily': 'Täglich', 'Weekly': 'Wöchentlich', 'Monthly': 'Monatlich', 'Yearly': 'Jährlich', 'Priority': 'Priorität',
});

export const uiLanguage = writable<UiLanguage>('en');
export const numberLocale = writable<NumberLocale>('en-US');
export const defaultCurrency = writable('EUR');
export const currencyDisplay = writable<CurrencyDisplay>('code');
uiLanguage.subscribe((language) => {
	if (typeof document !== 'undefined') document.documentElement.lang = language;
});
numberLocale.subscribe((locale) => setNumberFormatPreferences(locale, get(currencyDisplay)));
currencyDisplay.subscribe((display) => setNumberFormatPreferences(get(numberLocale), display));
export const t = derived(uiLanguage, (language) => (message: string, values?: Record<string, string | number>) => {
	const translated = language === 'de' ? german[message] ?? routeGerman[message] ?? message : message;
	return values
		? translated.replace(/\{([^}]+)\}/g, (placeholder, key: string) => String(values[key] ?? placeholder))
		: translated;
});

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
