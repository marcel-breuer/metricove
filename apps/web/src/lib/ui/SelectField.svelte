<script lang="ts">
	import { t } from '#lib/i18n';
	import { Label } from '#lib/components/ui/label/index.js';
	import * as NativeSelect from '#lib/components/ui/native-select/index.js';
	import { fieldLabel } from './styles';
	let {
		id,
		label,
		value,
		options,
		onchange,
		class: className = ''
	}: {
		id: string;
		label: string;
		value: string;
		options: { value: string; label: string }[];
		onchange?: (value: string) => void;
		class?: string;
	} = $props();
</script>

<Label for={id} variant="field" class={className}>
	<span class={fieldLabel}>{$t(label)}</span>
	<NativeSelect.Root
		variant="glass"
		{id}
		value={value}
		onchange={(event) => onchange?.(event.currentTarget.value)}
	>
		{#each options as option (option.value)}
			<NativeSelect.Option value={option.value}>{$t(option.label)}</NativeSelect.Option>
		{/each}
	</NativeSelect.Root>
</Label>
