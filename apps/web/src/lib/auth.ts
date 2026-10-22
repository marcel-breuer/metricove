export type AuthUser = {
	id: number;
	name: string;
	email: string;
	is_admin: boolean;
	language: 'en' | 'de';
	number_locale: 'de-DE' | 'en-US' | 'en-GB';
	default_currency: 'EUR' | 'USD' | 'GBP' | 'CHF';
	currency_display: 'code' | 'symbol';
};

export type ApiResponse = {
	message?: string;
	user?: AuthUser | null;
	errors?: Record<string, string[]>;
};

export function csrfToken(): string {
	const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));

	if (!cookie) return '';

	try {
		return decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
	} catch {
		return '';
	}
}

export function firstError(response: ApiResponse): string {
	return Object.values(response.errors ?? {}).flat()[0] ?? response.message ?? '';
}
