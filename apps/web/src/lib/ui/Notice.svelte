<script lang="ts">
	import { t } from '#lib/i18n';
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

<p class="ui-notice ui-notice--{tone} {className}" {role}>{$t(message)}</p>
