<script lang="ts">
	import { Switch as SwitchPrimitive } from "bits-ui";
	import { cn, type WithoutChild } from "#lib/utils.js";

	let {
		ref = $bindable(null),
		class: className,
		checked = $bindable(false),
		size = "default",
		variant = "default",
		children,
		...restProps
	}: WithoutChild<SwitchPrimitive.RootProps> & {
		size?: "sm" | "default";
		variant?: "default" | "labeled";
	} = $props();

	const thumbClass = "rounded-full pointer-events-none";
</script>

<SwitchPrimitive.Root
	bind:ref
	bind:checked
	data-slot="switch"
	data-size={size}
	class={cn(
		variant === "labeled"
			? "group inline-flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border-0 bg-transparent px-2 py-1 text-sm font-[550] text-secondary-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-55"
			: "data-checked:bg-primary data-unchecked:bg-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:aria-invalid:border-destructive/50 dark:data-unchecked:bg-input/80 shrink-0 rounded-full border border-transparent shadow-xs focus-visible:ring-3 aria-invalid:ring-3 group-has-[:focus-visible]/field-label:ring-0 group-has-[:focus-visible]/field-label:border-transparent data-[size=default]:h-[18.4px] data-[size=default]:w-[32px] data-[size=sm]:h-[14px] data-[size=sm]:w-[24px] peer group/switch relative inline-flex items-center transition-all outline-none after:absolute after:-inset-x-3 after:-inset-y-2 data-disabled:cursor-not-allowed data-disabled:opacity-50",
		className
	)}
	{...restProps}
>
	{#snippet children(state)}
		{#if variant === "labeled"}
			<span class="inline-flex h-7 w-12 items-center rounded-full border p-[0.1875rem] transition-colors group-data-checked:border-ring group-data-checked:bg-primary group-data-unchecked:border-input group-data-unchecked:bg-muted" aria-hidden="true">
				<SwitchPrimitive.Thumb data-slot="switch-thumb" class={cn(thumbClass, "size-[1.125rem] bg-muted-foreground shadow-[0_1px_2px_rgb(15_23_42/20%)] transition-[transform,background-color] data-checked:translate-x-5 data-checked:bg-primary-foreground")} />
			</span>
		{:else}
			<SwitchPrimitive.Thumb
					data-slot="switch-thumb"
					class={cn(
						thumbClass,
						"bg-background dark:data-unchecked:bg-foreground dark:data-checked:bg-primary-foreground group-data-[size=default]/switch:size-4 group-data-[size=sm]/switch:size-3 group-data-[size=default]/switch:data-checked:translate-x-[calc(100%_-_2px)] group-data-[size=sm]/switch:data-checked:translate-x-[calc(100%_-_2px)] group-data-[size=default]/switch:data-unchecked:translate-x-0 group-data-[size=sm]/switch:data-unchecked:translate-x-0 block ring-0 transition-transform",
						"rtl:data-[state=checked]:translate-x-[calc(-100%)]"
					)}
			/>
		{/if}
		{@render children?.(state)}
	{/snippet}
</SwitchPrimitive.Root>
