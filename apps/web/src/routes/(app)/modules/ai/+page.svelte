<script lang="ts">
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';

	onMount(() => {
		void goto('/assistant', { replaceState: true });
	});
</script>

<p role="status" class="text-sm text-slate-300">Opening the household assistant…</p>
