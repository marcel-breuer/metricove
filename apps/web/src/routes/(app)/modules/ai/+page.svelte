<script lang="ts">
	import { Alert } from '#lib/components/ui/alert/index.js';
	import { t } from '#lib/i18n';
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';

	onMount(() => {
		void goto('/assistant', { replaceState: true });
	});
</script>

<Alert variant="plain" role="status" class="text-sm text-ink-secondary">{$t("Opening the household assistant…")}</Alert>
