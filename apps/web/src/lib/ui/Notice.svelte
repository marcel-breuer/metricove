<script lang="ts">
	import { t } from '#lib/i18n';

	const tones = {
		info: 'border-line bg-surface-subtle text-ink-secondary',
		success: 'border-positive/30 bg-positive/9 text-positive',
		warning: 'border-warning/30 bg-warning/10 text-warning',
		error: 'border-danger/30 bg-danger/9 text-danger'
	};
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

<p class="rounded-xl border px-4 py-3 text-sm leading-normal {tones[tone]} {className}" {role}>{$t(message)}</p>
