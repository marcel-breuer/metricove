<script lang="ts">
	import type { Snippet } from 'svelte';
	import type { HTMLButtonAttributes } from 'svelte/elements';

	type Props = Omit<HTMLButtonAttributes, 'class'> & {
		variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
		size?: 'small' | 'medium';
		fullWidth?: boolean;
		/** Renders a link styled as a button for navigation actions. */
		href?: string;
		class?: string;
		element?: HTMLButtonElement | null;
		children: Snippet;
	};

	let {
		variant = 'primary',
		size = 'medium',
		fullWidth = false,
		href,
		class: className = '',
		element = $bindable(null),
		children,
		...attributes
	}: Props = $props();

	const classes = $derived(`ui-button ui-button--${variant} ui-button--${size} ${fullWidth ? 'ui-button--full' : ''} ${className}`);
</script>

{#if href}
	<a {href} class={classes} aria-label={attributes['aria-label']} onclick={attributes.onclick as ((event: MouseEvent) => void) | undefined}>{@render children()}</a>
{:else}
	<button bind:this={element} class={classes} {...attributes}>
		{@render children()}
	</button>
{/if}
