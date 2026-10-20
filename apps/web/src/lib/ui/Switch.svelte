<script lang="ts">
	let {
		checked,
		label,
		disabled = false,
		pending = false,
		onchange
	}: {
		checked: boolean;
		label: string;
		disabled?: boolean;
		pending?: boolean;
		onchange: () => void;
	} = $props();
</script>

<button
	type="button"
	role="switch"
	aria-checked={checked}
	aria-label={label}
	{disabled}
	class="ui-switch"
	class:ui-switch-checked={checked}
	onclick={onchange}
>
	<span class="ui-switch-track" aria-hidden="true"><span class="ui-switch-thumb"></span></span>
	<span class="ui-switch-status">{pending ? 'Saving…' : checked ? 'On' : 'Off'}</span>
</button>
