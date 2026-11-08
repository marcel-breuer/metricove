/**
 * Shared Tailwind class strings for native elements that cannot use a Svelte
 * component (inputs bound in place, file pickers, inline icon buttons).
 */

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink';

/** Text inputs, selects and textareas. 16px on phones so iOS Safari does not zoom. */
export const control = `box-border min-h-11 w-full rounded-[10px] border border-control-line bg-control glass-control px-3 py-2.5 text-[0.9375rem] text-ink placeholder:text-ink-subtle focus:border-accent-ink max-sm:text-base ${focusRing}`;

export const fieldLabel = 'text-sm font-[550] text-ink-secondary';

export const field = 'grid gap-2';

export const fileInput = `box-border block min-h-12 w-full cursor-pointer rounded-[10px] border border-dashed border-control-line bg-control glass-control p-1.5 text-sm text-ink-muted file:mr-3 file:cursor-pointer file:rounded-lg file:border file:border-control-line file:bg-surface file:px-3 file:py-2 file:font-semibold file:text-ink-secondary hover:file:border-accent-ink ${focusRing}`;

export const iconButton = `grid size-11 shrink-0 cursor-pointer place-items-center rounded-[10px] border border-line bg-transparent text-ink-secondary transition-colors hover:border-line-strong hover:bg-surface-subtle hover:text-ink ${focusRing}`;

export const panel = 'glass-surface rounded-2xl border border-line';

export const segmented = 'inline-flex min-h-11 items-center gap-1 rounded-[10px] border border-control-line bg-surface glass-control p-0.5';

export const segmentedButton = 'grid min-h-10 min-w-10 cursor-pointer place-items-center rounded-lg text-lg text-ink-secondary transition-colors hover:bg-surface-subtle hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent-ink';

export const modalActions = 'mt-6 flex flex-wrap justify-end gap-3 max-sm:*:flex-auto';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';
export type ButtonSize = 'small' | 'medium' | 'large';

const buttonVariants: Record<ButtonVariant, string> = {
	primary: 'border-transparent bg-accent text-on-accent hover:not-disabled:brightness-96',
	secondary: 'border-control-line bg-surface glass-control text-ink-secondary hover:not-disabled:border-accent-ink',
	ghost: 'border-transparent bg-transparent text-ink-muted hover:not-disabled:bg-surface-subtle hover:not-disabled:text-ink',
	danger: 'border-danger/35 bg-danger/10 text-danger'
};

const buttonSizes: Record<ButtonSize, string> = {
	small: 'min-h-9 rounded-[10px] px-3 py-1.5 text-[0.8125rem]',
	medium: 'min-h-11 rounded-[10px] px-4 py-2.5 text-sm',
	large: 'min-h-12 rounded-[0.8rem] px-4 py-2.5 text-[0.9375rem]'
};

export function buttonClasses({
	variant = 'primary',
	size = 'medium',
	fullWidth = false,
	align = 'center'
}: { variant?: ButtonVariant; size?: ButtonSize; fullWidth?: boolean; align?: 'center' | 'start' } = {}): string {
	return [
		'inline-flex cursor-pointer items-center gap-2 border leading-5 font-semibold no-underline transition-colors disabled:cursor-not-allowed disabled:opacity-55',
		align === 'start' ? 'justify-start' : 'justify-center',
		focusRing,
		buttonVariants[variant],
		buttonSizes[size],
		fullWidth ? 'w-full' : ''
	].join(' ');
}
