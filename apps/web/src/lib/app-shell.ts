import type { AuthUser } from '#lib/auth';

export type ModuleCapability = 'view' | 'create' | 'edit' | 'manage';

export type AvailableModule = {
	key: string;
	label: string;
	description: string;
	capabilities: Record<ModuleCapability, boolean>;
};

export type AppShellContext = {
	get currentUser(): AuthUser | null;
	get modules(): AvailableModule[];
	get loading(): boolean;
	refresh(): Promise<void>;
};

export const APP_SHELL_CONTEXT = 'metricove-app-shell';
