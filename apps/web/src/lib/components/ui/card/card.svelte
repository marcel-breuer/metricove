<script lang="ts">
	import { panel } from "#lib/ui/styles.js";
	import { cn, type WithElementRef } from "#lib/utils.js";
	import type { HTMLAnchorAttributes, HTMLAttributes } from "svelte/elements";

	const variants = {
		default: "ring-foreground/10 bg-card text-card-foreground gap-(--card-spacing) overflow-hidden rounded-xl py-(--card-spacing) text-sm shadow-xs ring-1 [--card-spacing:--spacing(6)] has-[>img:first-child]:pt-0 data-[size=sm]:[--card-spacing:--spacing(4)] *:[img:first-child]:rounded-t-xl *:[img:last-child]:rounded-b-xl group/card flex flex-col",
		glass: panel,
		inset: "rounded-xl border border-line bg-page",
		warning: "rounded-2xl border border-warning/20 bg-warning/5",
		danger: "rounded-2xl border border-danger/20 bg-danger/5",
	};

	let {
		ref = $bindable(null),
		as = "div",
		variant = "default",
		class: className,
		children,
		size = "default",
		...restProps
	}: WithElementRef<HTMLAttributes<HTMLElement>> & Pick<HTMLAnchorAttributes, "href"> & {
		as?: "a" | "article" | "div" | "footer" | "form" | "li" | "p" | "section" | "ul";
		size?: "default" | "sm";
		variant?: keyof typeof variants;
	} = $props();
</script>

<svelte:element
	this={as}
	bind:this={ref}
	data-slot="card"
	data-size={size}
	class={cn(variants[variant], className)}
	{...restProps}
>
	{@render children?.()}
</svelte:element>
