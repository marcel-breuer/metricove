<script lang="ts">
	import { panel } from "#lib/ui/styles.js";
	import { cn, type WithElementRef } from "#lib/utils.js";
	import type { HTMLAttributes } from "svelte/elements";

	let {
		ref = $bindable(null),
		as = "div",
		variant = "default",
		class: className,
		children,
		size = "default",
		...restProps
	}: WithElementRef<HTMLAttributes<HTMLElement>> & {
		as?: "div" | "section";
		size?: "default" | "sm";
		variant?: "default" | "glass";
	} = $props();
</script>

<svelte:element
	this={as}
	bind:this={ref}
	data-slot="card"
	data-size={size}
	class={cn(variant === "glass" ? panel : "ring-foreground/10 bg-card text-card-foreground gap-(--card-spacing) overflow-hidden rounded-xl py-(--card-spacing) text-sm shadow-xs ring-1 [--card-spacing:--spacing(6)] has-[>img:first-child]:pt-0 data-[size=sm]:[--card-spacing:--spacing(4)] *:[img:first-child]:rounded-t-xl *:[img:last-child]:rounded-b-xl group/card flex flex-col", className)}
	{...restProps}
>
	{@render children?.()}
</svelte:element>
