import { METRICOVE_INTERNAL_API_URL } from '$app/env/private';
import { error, redirect } from '@sveltejs/kit';
import type { PageServerLoad } from './$types';

type SetupStatus = { setup_required: boolean };
type SessionResponse = { user: Record<string, unknown> | null };

export const load: PageServerLoad = async ({ fetch, request }) => {
	const headers = new Headers({ Accept: 'application/json' });
	const cookie = request.headers.get('cookie');
	if (cookie) headers.set('cookie', cookie);

	async function requestApi(path: string): Promise<Response> {
		const internalApiUrl = METRICOVE_INTERNAL_API_URL;

		return internalApiUrl
			? globalThis.fetch(new URL(path, `${internalApiUrl.replace(/\/+$/, '')}/`), { headers })
			: fetch(path, { headers });
	}

	let setupStatus: SetupStatus;
	try {
		const response = await requestApi('/api/setup/status');
		if (!response.ok) throw new Error('Setup status request failed.');
		setupStatus = (await response.json()) as SetupStatus;
	} catch {
		throw error(503, 'Household setup is unavailable. Try again shortly.');
	}

	if (setupStatus.setup_required) redirect(303, '/setup');

	let session: SessionResponse;
	try {
		const response = await requestApi('/api/auth/session');
		if (!response.ok) throw new Error('Session request failed.');
		session = (await response.json()) as SessionResponse;
	} catch {
		throw error(503, 'Your session is unavailable. Try again shortly.');
	}

	if (!session.user) redirect(303, '/login');
};
