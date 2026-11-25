<script lang="ts">
	import { RadioGroup as RadioGroupPrimitive } from "bits-ui";
	import type { Snippet } from "svelte";
	import CircleIcon from '@lucide/svelte/icons/circle';
	import { cn, type WithoutChildrenOrChild } from "#lib/utils.js";

	let {
		ref = $bindable(null),
		class: className,
		variant = "default",
		children: content,
		...restProps
	}: WithoutChildrenOrChild<RadioGroupPrimitive.ItemProps> & {
		variant?: "default" | "tile";
		children?: Snippet;
	} = $props();
</script>

<RadioGroupPrimitive.Item
	bind:ref
	data-slot="radio-group-item"
	class={cn(
		variant === "tile"
			? "flex min-h-18 min-w-0 cursor-pointer flex-col items-center justify-center gap-1.5 rounded-xl border border-control-line bg-control px-1 py-2 text-center text-ink-secondary transition-colors hover:border-accent-ink data-checked:border-accent-ink data-checked:bg-accent-ink/12 data-checked:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink disabled:cursor-not-allowed disabled:opacity-50"
			: "border-input dark:bg-input/30 data-checked:bg-primary data-checked:text-primary-foreground dark:data-checked:bg-primary data-checked:border-primary aria-invalid:aria-checked:border-primary aria-invalid:border-destructive focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 dark:aria-invalid:border-destructive/50 flex size-4 rounded-full focus-visible:ring-3 aria-invalid:ring-3 group-has-[:focus-visible]/field-label:ring-0 group-has-[:focus-visible]/field-label:not-data-checked:border-input group-has-[:focus-visible]/field-label:data-checked:border-primary group/radio-group-item peer relative aspect-square shrink-0 border outline-none after:absolute after:-inset-x-3 after:-inset-y-2 disabled:cursor-not-allowed disabled:opacity-50",
		className
	)}
	{...restProps}
>
	{#snippet children({ checked })}
		{#if content}
			{@render content()}
		{:else}
			<div data-slot="radio-group-indicator" class="flex size-4 items-center justify-center">
				{#if checked}
					<CircleIcon class="bg-primary-foreground absolute top-1/2 left-1/2 size-2 -translate-x-1/2 -translate-y-1/2 rounded-full" />
				{/if}
			</div>
		{/if}
	{/snippet}
</RadioGroupPrimitive.Item>
