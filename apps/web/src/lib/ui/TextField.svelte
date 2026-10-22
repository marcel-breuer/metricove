<script lang="ts">
	import type { HTMLInputAttributes } from 'svelte/elements';
	import { t } from '#lib/i18n';

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

<label class="ui-field {className}" for={id}>
	<span class="ui-field-label">{$t(label)}</span>
		<input class="ui-control" {id} bind:value placeholder={placeholder === undefined ? undefined : $t(placeholder)} {...attributes} />
</label>
