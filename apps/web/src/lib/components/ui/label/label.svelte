<script lang="ts">
	import { Label as LabelPrimitive } from "bits-ui";
	import { field } from "#lib/ui/styles.js";
	import { cn } from "#lib/utils.js";

	let {
		ref = $bindable(null),
		class: className,
		variant = "default",
		...restProps
	}: LabelPrimitive.RootProps & { variant?: "default" | "field" } = $props();
</script>

<LabelPrimitive.Root
	bind:ref
	data-slot="label"
	class={cn(
		variant === "field" ? field : "gap-2 text-sm leading-none font-medium group-data-[disabled=true]:opacity-50 peer-disabled:opacity-50 flex items-center select-none group-data-[disabled=true]:pointer-events-none peer-disabled:cursor-not-allowed",
		className
	)}
	{...restProps}
/>
