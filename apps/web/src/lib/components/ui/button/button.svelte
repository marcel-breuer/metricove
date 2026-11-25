<script lang="ts" module>
	import { type VariantProps, tv } from "tailwind-variants";
	import { cn, type WithElementRef } from "#lib/utils.js";
	import type { HTMLAnchorAttributes, HTMLButtonAttributes } from "svelte/elements";

	export const buttonVariants = tv({
		base: "inline-flex max-w-full cursor-pointer items-center justify-center gap-2 border leading-5 font-semibold no-underline transition-colors disabled:cursor-not-allowed disabled:opacity-55 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
		variants: {
			variant: {
				default: "border-transparent bg-primary text-primary-foreground hover:not-disabled:brightness-96",
				outline: "border-border bg-background hover:bg-muted hover:text-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50 aria-expanded:bg-muted aria-expanded:text-foreground shadow-xs",
				secondary: "border-input bg-secondary glass-control text-secondary-foreground hover:not-disabled:border-ring",
				ghost: "border-transparent bg-transparent text-muted-foreground hover:not-disabled:bg-muted hover:not-disabled:text-foreground",
				destructive: "border-destructive/35 bg-destructive/10 text-destructive",
				link: "text-primary underline-offset-4 hover:underline",
				toolbar: "rounded-lg border-line bg-surface px-2 text-sm text-ink-secondary hover:not-disabled:border-accent-ink disabled:opacity-50",
			},
			size: {
				default: "min-h-11 rounded-[10px] px-4 py-2.5 text-sm",
				xs: "h-6 gap-1 rounded-[min(var(--radius-md),8px)] px-2 text-xs in-data-[slot=button-group]:rounded-md has-data-[icon=inline-end]:pr-1.5 has-data-[icon=inline-start]:pl-1.5 [&_svg:not([class*='size-'])]:size-3",
				sm: "min-h-9 rounded-[10px] px-3 py-1.5 text-[0.8125rem]",
				lg: "min-h-12 rounded-[0.8rem] px-4 py-2.5 text-[0.9375rem]",
				icon: "size-9",
				"icon-xs": "size-6 rounded-[min(var(--radius-md),8px)] in-data-[slot=button-group]:rounded-md [&_svg:not([class*='size-'])]:size-3",
				"icon-sm": "size-8 rounded-[min(var(--radius-md),10px)] in-data-[slot=button-group]:rounded-md",
				"icon-lg": "size-10",
			},
		},
		defaultVariants: {
			variant: "default",
			size: "default",
		},
	});

	export type ButtonVariant = VariantProps<typeof buttonVariants>["variant"];
	export type ButtonSize = VariantProps<typeof buttonVariants>["size"];

	export type ButtonProps = WithElementRef<HTMLButtonAttributes> &
		Omit<HTMLAnchorAttributes, keyof HTMLButtonAttributes> & {
			variant?: ButtonVariant;
			size?: ButtonSize;
		};
</script>

<script lang="ts">
	let {
		class: className,
		variant = "default",
		size = "default",
		ref = $bindable(null),
		href = undefined,
		type = "button",
		disabled,
		children,
		...restProps
	}: ButtonProps = $props();
</script>

{#if href}
	<a
		bind:this={ref}
		data-slot="button"
		class={cn(buttonVariants({ variant, size }), className)}
		href={disabled ? undefined : href}
		aria-disabled={disabled}
		role={disabled ? "link" : undefined}
		tabindex={disabled ? -1 : undefined}
		{...(restProps as HTMLAnchorAttributes)}
	>
		{@render children?.()}
	</a>
{:else}
	<button
		bind:this={ref}
		data-slot="button"
		class={cn(buttonVariants({ variant, size }), className)}
		{type}
		{disabled}
		{...restProps}
	>
		{@render children?.()}
	</button>
{/if}
