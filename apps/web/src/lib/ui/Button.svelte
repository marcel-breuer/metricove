<script lang="ts">
	import type { Snippet } from 'svelte';
	import type { HTMLButtonAttributes } from 'svelte/elements';
	import { Button } from '#lib/components/ui/button/index.js';
	import { cn } from '#lib/utils.js';

	const variants = { primary: 'default', secondary: 'secondary', ghost: 'ghost', danger: 'destructive' } as const;
	const sizes = { small: 'sm', medium: 'default', large: 'lg' } as const;

	type Props = Omit<HTMLButtonAttributes, 'class'> & {
		variant?: keyof typeof variants;
		size?: keyof typeof sizes;
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

	const classes = $derived(cn(fullWidth && 'w-full', align === 'start' && 'justify-start', className));
</script>

{#if href}
	<Button {href} class={classes} variant={variants[variant]} size={sizes[size]} aria-label={attributes['aria-label']} onclick={attributes.onclick}>
		{@render children()}
	</Button>
{:else}
	<Button bind:ref={element} class={classes} variant={variants[variant]} size={sizes[size]} type="submit" {...attributes}>
		{@render children()}
	</Button>
{/if}
