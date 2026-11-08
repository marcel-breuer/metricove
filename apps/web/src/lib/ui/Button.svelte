<script lang="ts">
	import type { Snippet } from 'svelte';
	import type { HTMLButtonAttributes } from 'svelte/elements';
	import { buttonClasses, type ButtonSize, type ButtonVariant } from './styles';

	type Props = Omit<HTMLButtonAttributes, 'class'> & {
		variant?: ButtonVariant;
		size?: ButtonSize;
		fullWidth?: boolean;
		align?: 'center' | 'start';
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
		align = 'center',
		href,
		class: className = '',
		element = $bindable(null),
		children,
		...attributes
	}: Props = $props();

	const classes = $derived(`${buttonClasses({ variant, size, fullWidth, align })} ${className}`);
</script>

{#if href}
	<a {href} class={classes} aria-label={attributes['aria-label']} onclick={attributes.onclick as ((event: MouseEvent) => void) | undefined}>{@render children()}</a>
{:else}
	<button bind:this={element} class={classes} {...attributes}>
		{@render children()}
	</button>
{/if}
