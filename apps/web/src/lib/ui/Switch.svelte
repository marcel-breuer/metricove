<script lang="ts">
	import { t } from '#lib/i18n';
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
	aria-label={$t(label)}
	{disabled}
	class="ui-switch"
	class:ui-switch-checked={checked}
	onclick={onchange}
>
	<span class="ui-switch-track" aria-hidden="true"><span class="ui-switch-thumb"></span></span>
	<span class="ui-switch-status">{pending ? $t('Saving…') : checked ? $t('On') : $t('Off')}</span>
</button>
