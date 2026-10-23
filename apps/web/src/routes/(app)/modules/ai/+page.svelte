<script lang="ts">
	import { t } from '#lib/i18n';
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';

	onMount(() => {
		void goto('/assistant', { replaceState: true });
	});
</script>

<p role="status" class="text-sm text-slate-300">{$t("Opening the household assistant…")}</p>
