import { defineEnvVars } from '@sveltejs/kit/env';

export const variables = defineEnvVars({
	METRICOVE_INTERNAL_API_URL: {
		description: 'Internal gateway URL for server-side session checks.',
		schema: (value) => value || undefined
	}
});
