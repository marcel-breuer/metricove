<script lang="ts">
	import type { HTMLInputAttributes } from 'svelte/elements';

	type Props = Omit<HTMLInputAttributes, 'class' | 'value'> & {
		id: string;
		label: string;
		value?: string;
		class?: string;
	};

	let {
		id,
		label,
		value = $bindable(''),
		class: className = '',
		...attributes
	}: Props = $props();
</script>

<label class="ui-field {className}" for={id}>
	<span class="ui-field-label">{label}</span>
	<input class="ui-control" {id} bind:value {...attributes} />
</label>
