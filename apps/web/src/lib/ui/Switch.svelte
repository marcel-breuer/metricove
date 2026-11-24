<script lang="ts">
	import { t } from '#lib/i18n';
	import { Switch } from '#lib/components/ui/switch/index.js';
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

<Switch variant="labeled" bind:checked={() => checked, () => onchange()} aria-label={$t(label)} {disabled}>
	<span>{pending ? $t('Saving…') : checked ? $t('On') : $t('Off')}</span>
</Switch>
