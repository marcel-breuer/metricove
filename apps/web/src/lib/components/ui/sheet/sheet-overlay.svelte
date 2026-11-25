<script lang="ts">
	import { Dialog as SheetPrimitive } from "bits-ui";
	import { glassOverlay } from "#lib/ui/styles.js";
	import { cn } from "#lib/utils.js";

	let {
		ref = $bindable(null),
		class: className,
		variant = "default",
		...restProps
	}: SheetPrimitive.OverlayProps & { variant?: "default" | "glass" } = $props();
</script>

<SheetPrimitive.Overlay
	bind:ref
	data-slot="sheet-overlay"
	class={cn(
		variant === "glass"
			? glassOverlay
			: "bg-black/10 supports-backdrop-filter:backdrop-blur-xs fixed inset-0 z-50 duration-100 data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0",
		className
	)}
	{...restProps}
/>
