<script lang="ts">
	type Props = { name: string; class?: string };
	let { name, class: className = 'size-5' }: Props = $props();
</script>

<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class={className}>
	{#if name === 'tracking'}
		<path d="M4 18V6m0 12h16M7 14l4-4 3 3 6-7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
	{:else if name === 'utilities'}
		<path d="M12 3v10m0 0a3 3 0 1 0 3 3V3m-3 10a3 3 0 1 1-3 3V3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
	{:else if name === 'vehicles'}
		<path d="m5 11 1.5-4h11L19 11m-15 0h16v7h-2m-12 0H4v-7Zm3 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
	{:else if name === 'finance'}
		<path d="M4 8h16v11H4zM4 8l8-4 8 4m-5 5h4m-11 0h3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
	{:else if name === 'recipes'}
		<path d="M7 4h10v16H7zM9.5 8h5m-5 4h5m-5 4h3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
	{:else if name === 'meal_planning'}
		<rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="1.7" />
		<path d="M8 3v4m8-4v4M4 10h16m-11 4h2m3 0h2m-7 3h2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
	{:else if name === 'shopping'}
		<path d="M5 8h14l-1 12H6L5 8Zm4 0a3 3 0 0 1 6 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
	{:else if name === 'tasks'}
		<path d="m5 7 1.5 1.5L9 6m3 1h7M5 14l1.5 1.5L9 13m3 1h7M5 20l1.5 1.5L9 19m3 1h7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
	{:else if name === 'notes'}
		<path d="M6 4h12v16H6zM9 8h6m-6 4h6m-6 4h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
	{:else if name === 'analytics'}
		<path d="M4 19V5m0 14h16M8 15v-4m4 4V7m4 8v-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
	{:else if name === 'import'}
		<path d="M12 15V4m0 0L8 8m4-4 4 4M5 14v5h14v-5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
	{:else if name === 'receipts'}
		<path d="M6 4h12v16l-3-2-3 2-3-2-3 2V4Zm3 4h6m-6 4h6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
	{:else}
		<rect x="4" y="4" width="16" height="16" rx="3" stroke="currentColor" stroke-width="1.7" />
		<path d="M8 9h8M8 13h8M8 17h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
	{/if}
</svg>
