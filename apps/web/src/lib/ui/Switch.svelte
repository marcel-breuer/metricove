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
	class="group inline-flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border-0 bg-transparent px-2 py-1 text-sm font-[550] text-ink-secondary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink disabled:cursor-not-allowed disabled:opacity-55"
	onclick={onchange}
>
	<span class="inline-flex h-7 w-12 items-center rounded-full border p-[0.1875rem] transition-colors {checked ? 'border-accent-ink bg-accent' : 'border-control-line bg-surface-subtle'}" aria-hidden="true"><span class="size-[1.125rem] rounded-full shadow-[0_1px_2px_rgb(15_23_42/20%)] transition-[transform,background-color] {checked ? 'translate-x-5 bg-on-accent' : 'bg-ink-muted'}"></span></span>
	<span>{pending ? $t('Saving…') : checked ? $t('On') : $t('Off')}</span>
</button>
