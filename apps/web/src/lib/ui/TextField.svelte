<script lang="ts">
	import type { HTMLInputAttributes } from 'svelte/elements';
	import { t } from '#lib/i18n';
	import { control, field, fieldLabel } from './styles';

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

<label class="{field} {className}" for={id}>
	<span class={fieldLabel}>{$t(label)}</span>
		<input class={control} {id} bind:value placeholder={placeholder ? $t(placeholder) : undefined} {...attributes} />
</label>
