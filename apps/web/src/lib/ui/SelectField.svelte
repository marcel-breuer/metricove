<script lang="ts">
	import { t } from '#lib/i18n';
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

<label class="ui-field {className}" for={id}>
	<span class="ui-field-label">{$t(label)}</span>
	<select
		class="ui-control"
		{id}
		value={value}
		onchange={(event) => onchange?.(event.currentTarget.value)}
	>
		{#each options as option (option.value)}
			<option value={option.value}>{$t(option.label)}</option>
		{/each}
	</select>
</label>
