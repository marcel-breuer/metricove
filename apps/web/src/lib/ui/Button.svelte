<script lang="ts">
	import type { Snippet } from 'svelte';
	import type { HTMLButtonAttributes } from 'svelte/elements';

	type Props = Omit<HTMLButtonAttributes, 'class'> & {
		variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
		size?: 'small' | 'medium';
		fullWidth?: boolean;
		class?: string;
		element?: HTMLButtonElement | null;
		children: Snippet;
	};

	let {
		variant = 'primary',
		size = 'medium',
		fullWidth = false,
		class: className = '',
		element = $bindable(null),
		children,
		...attributes
	}: Props = $props();
</script>

<button
	bind:this={element}
	class="ui-button ui-button--{variant} ui-button--{size} {fullWidth ? 'ui-button--full' : ''} {className}"
	{...attributes}
>
	{@render children()}
</button>
