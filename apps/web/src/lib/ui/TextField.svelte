<script lang="ts">
	import type { HTMLInputAttributes } from 'svelte/elements';
	import { t } from '#lib/i18n';
	import { Input } from '#lib/components/ui/input/index.js';
	import { Label } from '#lib/components/ui/label/index.js';
	import { fieldLabel } from './styles';

	type Props = Omit<HTMLInputAttributes, 'class' | 'value'> & {
		id: string;
		label: string;
		value?: string;
		class?: string;
	};

	let {
		id,
		label,
		placeholder,
		value = $bindable(''),
		class: className = '',
		...attributes
	}: Props = $props();
</script>

<Label for={id} variant="field" class={className}>
	<span class={fieldLabel}>{$t(label)}</span>
	<Input variant="glass" {id} bind:value placeholder={placeholder ? $t(placeholder) : undefined} {...attributes} />
</Label>
