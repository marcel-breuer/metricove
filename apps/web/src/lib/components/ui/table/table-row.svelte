<script lang="ts">
	import { cn, type WithElementRef } from "#lib/utils.js";
	import type { HTMLAttributes } from "svelte/elements";

	let {
		ref = $bindable(null),
		class: className,
		variant = "default",
		children,
		...restProps
	}: WithElementRef<HTMLAttributes<HTMLTableRowElement>> & {
		variant?: "default" | "plain";
	} = $props();
</script>

<tr
	bind:this={ref}
	data-slot="table-row"
	class={cn(variant === "default" && "hover:bg-muted/50 data-[state=selected]:bg-muted border-b transition-colors has-aria-expanded:bg-muted/50", className)}
	{...restProps}
>
	{@render children?.()}
</tr>
