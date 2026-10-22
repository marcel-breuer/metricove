import { METRICOVE_INTERNAL_API_URL } from '$app/env/private';
import { error, redirect } from '@sveltejs/kit';
import type { PageServerLoad } from './$types';

type SessionResponse = { user: Record<string, unknown> | null };

export const load: PageServerLoad = async ({ fetch, request }) => {
	const headers = new Headers({ Accept: 'application/json' });
	const cookie = request.headers.get('cookie');
	if (cookie) headers.set('cookie', cookie);

	let session: SessionResponse;
	try {
		const apiUrl = METRICOVE_INTERNAL_API_URL;
		const response = apiUrl
			? await globalThis.fetch(new URL('/api/auth/session', `${apiUrl.replace(/\/+$/, '')}/`), { headers })
			: await fetch('/api/auth/session', { headers });
		if (!response.ok) throw new Error('Session request failed.');
		session = (await response.json()) as SessionResponse;
	} catch {
		throw error(503, 'Your session is unavailable. Try again shortly.');
	}

	if (!session.user) redirect(303, '/login');
};
