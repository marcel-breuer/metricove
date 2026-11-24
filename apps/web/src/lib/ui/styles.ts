/**
 * Shared Tailwind classes for component variants and existing native controls.
 */

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-ink';

/** Text inputs, selects and textareas. 16px on phones so iOS Safari does not zoom. */
export const control = `box-border min-h-11 w-full rounded-[10px] border border-control-line bg-control glass-control px-3 py-2.5 text-base text-ink placeholder:text-ink-subtle focus:border-accent-ink ${focusRing}`;

export const fieldLabel = 'text-sm font-[550] text-ink-secondary';

export const field = 'grid gap-2';

export const glassDialog = 'fixed top-1/2 left-1/2 z-50 -translate-x-1/2 -translate-y-1/2 border glass-dialog p-0 text-popover-foreground outline-none';

export const glassOverlay = 'fixed inset-0 z-50 bg-overlay';

export const fileInput = `box-border block min-h-12 w-full cursor-pointer rounded-[10px] border border-dashed border-control-line bg-control glass-control p-1.5 text-sm text-ink-muted file:mr-3 file:cursor-pointer file:rounded-lg file:border file:border-control-line file:bg-surface file:px-3 file:py-2 file:font-semibold file:text-ink-secondary hover:file:border-accent-ink ${focusRing}`;

export const iconButton = `grid size-11 shrink-0 cursor-pointer place-items-center rounded-[10px] border border-line bg-transparent text-ink-secondary transition-colors hover:border-line-strong hover:bg-surface-subtle hover:text-ink ${focusRing}`;

export const panel = 'glass-surface rounded-2xl border border-line';

export const segmented = 'inline-flex min-h-11 items-center gap-1 rounded-[10px] border border-control-line bg-surface glass-control p-0.5';

export const segmentedButton = 'grid min-h-10 min-w-10 cursor-pointer place-items-center rounded-lg text-lg text-ink-secondary transition-colors hover:bg-surface-subtle hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent-ink';

export const modalActions = 'mt-6 flex flex-wrap items-center justify-end gap-3';
