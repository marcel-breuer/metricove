import { goto } from '$app/navigation';
import { csrfToken, firstError, type ApiResponse } from '#lib/auth';

/**
 * Same-origin JSON or multipart request with CSRF and session handling.
 * Authorization stays on the server; a 401 sends the user to the login page.
 */
export async function apiRequest<T extends object>(
	path: string,
	method = 'GET',
	body?: object | FormData,
	fallbackError = 'The request could not be completed.'
): Promise<T> {
	const headers: Record<string, string> = { Accept: 'application/json' };
	if (method !== 'GET') {
		const token = csrfToken();
		if (!token) throw new Error('Reload this page and try again.');
		headers['X-XSRF-TOKEN'] = token;
		if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
	}
	const response = await fetch(path, {
		method,
		credentials: 'same-origin',
		headers,
		...(body ? { body: body instanceof FormData ? body : JSON.stringify(body) } : {})
	});
	if (response.status === 401) {
		await goto('/login');
		throw new Error('Your session has expired.');
	}
	const result = (await response.json()) as T & ApiResponse;
	if (!response.ok) throw new Error(firstError(result) || result.message || fallbackError);
	return result as T;
}

export function recipeRequest<T extends object>(path: string, method = 'GET', body?: object | FormData): Promise<T> {
	return apiRequest<T>(path, method, body, 'Recipes could not complete that request.');
}

export function notesRequest<T extends object>(path: string, method = 'GET', body?: object): Promise<T> {
	return apiRequest<T>(path, method, body, 'Notes could not complete that request.');
}
