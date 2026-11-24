import { afterEach, describe, expect, it } from 'bun:test';
import { render } from 'svelte/server';
import { setUserPreferences } from '../i18n';

import './test-rendering';

const { default: ConfirmModal } = await import('./ConfirmModal.svelte');

afterEach(() => setUserPreferences({ language: 'en' }));

describe('rendered dialog actions', () => {
	it('renders German close, cancel and default confirmation labels', () => {
		setUserPreferences({ language: 'de' });
		const { body } = render(ConfirmModal, { props: { open: true, title: 'Test', message: 'Test', onConfirm: () => {} } });
		expect(body).toContain('role="alertdialog"');
		expect(body).toContain('data-slot="alert-dialog-description"');
		expect(body).toContain('aria-label="Dialog schließen"');
		expect(body).toContain('Abbrechen');
		expect(body).toContain('Löschen');
		expect(body).not.toContain('Close dialog');
	});

	it('renders a translated custom action and a neutral busy state for revocation', () => {
		setUserPreferences({ language: 'de' });
		const props = { open: true, title: 'Test', message: 'Test', confirmLabel: 'Revoke', onConfirm: () => {} };
		expect(render(ConfirmModal, { props }).body).toContain('Widerrufen');
		const busy = render(ConfirmModal, { props: { ...props, busy: true } }).body;
		expect(busy).toContain('Wird ausgeführt…');
		expect(busy).toContain('disabled');
		expect(busy).not.toContain('Deleting');
	});

	it('renders English again after switching back from German', () => {
		setUserPreferences({ language: 'de' });
		setUserPreferences({ language: 'en' });
		const { body } = render(ConfirmModal, { props: { open: true, title: 'Test', message: '<script>user content</script>', onConfirm: () => {} } });
		expect(body).toContain('aria-label="Close dialog"');
		expect(body).toContain('Cancel');
		expect(body).toContain('Delete');
		expect(body).toContain('&lt;script>user content&lt;/script>');
	});
});
