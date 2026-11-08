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
	{:else if name === 'overview'}
		<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1V10Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
	{:else if name === 'reminders'}
		<path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Zm-8 12h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
	{:else if name === 'mcp'}
		<path d="m8 8-4 4 4 4m8-8 4 4-4 4m-2-10-4 12" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
	{:else if name === 'members'}
		<path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-4A4.5 4.5 0 0 0 3 18.5V20M9.5 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM17 11a3 3 0 1 0 0-6M17 14h.5a3.5 3.5 0 0 1 3.5 3.5V20" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
	{:else if name === 'modules'}
		<path d="M12 3 20 7.5v9L12 21l-8-4.5v-9L12 3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
		<path d="m4.5 7.8 7.5 4.4 7.5-4.4M12 12.5V21" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
	{:else if name === 'settings'}
		<path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Z" stroke="currentColor" stroke-width="1.7"/>
		<path d="m19.4 15 .1.1a1.8 1.8 0 0 1-2.5 2.5l-.1-.1a1.8 1.8 0 0 0-3 .9v.2a1.8 1.8 0 0 1-3.6 0v-.2a1.8 1.8 0 0 0-3-.9l-.1.1a1.8 1.8 0 0 1-2.5-2.5l.1-.1a1.8 1.8 0 0 0-.9-3h-.2a1.8 1.8 0 0 1 0-3.6h.2a1.8 1.8 0 0 0 .9-3l-.1-.1a1.8 1.8 0 0 1 2.5-2.5l.1.1a1.8 1.8 0 0 0 3-.9v-.2a1.8 1.8 0 0 1 3.6 0v.2a1.8 1.8 0 0 0 3 .9l.1-.1a1.8 1.8 0 0 1 2.5 2.5l-.1.1a1.8 1.8 0 0 0 .9 3h.2a1.8 1.8 0 0 1 0 3.6h-.2a1.8 1.8 0 0 0-.9 3Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
	{:else if name === 'invitations'}
		<rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.7" />
		<path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
	{:else}
		<rect x="4" y="4" width="16" height="16" rx="3" stroke="currentColor" stroke-width="1.7" />
		<path d="M8 9h8M8 13h8M8 17h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
	{/if}
</svg>
