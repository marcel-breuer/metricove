<script lang="ts">
	import { t } from '#lib/i18n';

	let { href, label, class: className = '' }: { href: string; label: string; class?: string } = $props();
</script>

<a {href} class="ui-back-link {className}">
	<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="size-4"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" /></svg>
	{$t(label)}
</a>
