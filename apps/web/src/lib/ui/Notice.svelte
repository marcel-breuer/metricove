<script lang="ts">
	import { t } from '#lib/i18n';

	import { Alert } from '#lib/components/ui/alert/index.js';

	let {
		tone = 'info',
		message,
		role = tone === 'error' ? 'alert' : 'status',
		class: className = ''
	}: {
		tone?: 'info' | 'success' | 'warning' | 'error';
		message: string;
		role?: 'status' | 'alert';
		class?: string;
	} = $props();
</script>

<Alert variant={tone} class={className} role={role}>{$t(message)}</Alert>
