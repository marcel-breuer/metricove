<script lang="ts">
	import { goto } from '$app/navigation';
	import { getContext } from 'svelte';
	import { get } from 'svelte/store';
	import { csrfToken, firstError, type ApiResponse } from '#lib/auth';
	import { formatDateTime, t } from '#lib/i18n';
	import { APP_SHELL_CONTEXT, type AppShellContext } from '#lib/app-shell';
	import Button from '#lib/ui/Button.svelte';
	import Notice from '#lib/ui/Notice.svelte';
	import PageHeading from '#lib/ui/PageHeading.svelte';
	import Panel from '#lib/ui/Panel.svelte';
	import Modal from '#lib/ui/Modal.svelte';
	import ConfirmModal from '#lib/ui/ConfirmModal.svelte';
	import { visibleTaskAssignees, type HouseholdTask, type TaskAssignee, type TaskChecklistItem, type TaskCompletion, type TasksResponse } from '#lib/tasks';

	const shell = getContext<AppShellContext>(APP_SHELL_CONTEXT);
	const access = $derived(shell.modules.find((module) => module.key === 'tasks'));
	const canView = $derived(access?.capabilities.view ?? false);
	const canCreate = $derived(access?.capabilities.create ?? false);
	const canManage = $derived(access?.capabilities.manage ?? false);
	const canCreateShared = $derived(shell.currentUser?.is_admin === true && canManage);
	const canAssign = $derived(canManage);

	let tasks = $state<HouseholdTask[]>([]);
	let assignees = $state<TaskAssignee[]>([]);
	let visibleAssignees = $state<TaskAssignee[]>([]);
	let loading = $state(true);
	let saving = $state(false);
	let error = $state('');
	let message = $state('');
	let statusFilter = $state<'open' | 'completed' | 'all'>('open');
	let priorityFilter = $state<'all' | 'low' | 'medium' | 'high'>('all');
	let dueFilter = $state<'all' | 'overdue' | 'upcoming' | 'none'>('all');
	let assigneeFilter = $state('');
	let sortMode = $state<'due_at' | 'priority' | 'assignee'>('due_at');
	let editing = $state<HouseholdTask | null>(null);
	let formOpen = $state(false);
	let deleteTarget = $state<HouseholdTask | null>(null);
	let deleteOpen = $state(false);
	let title = $state('');
	let description = $state('');
	let dueInput = $state('');
	let priority = $state<'low' | 'medium' | 'high'>('medium');
	let assigneeId = $state('');
	let taskTimezone = $state(Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');
	let visibility = $state<'private' | 'household'>('private');
	let frequency = $state<'none' | 'daily' | 'weekly' | 'monthly' | 'yearly'>('none');
	let interval = $state('1');
	let historyOpen = $state<number | null>(null);
	let histories = $state<Record<number, TaskCompletion[]>>({});
	let checklistOpenId = $state<number | null>(null);
	let checklistTitle = $state('');
	let checklistDueInput = $state('');
	let checklistLead = $state('0');
	let editingChecklistItemId = $state<number | null>(null);
	let checklistEditTitle = $state('');
	let checklistEditDueInput = $state('');
	let checklistEditLead = $state('0');
	let checklistDeleteTarget = $state<{ task: HouseholdTask; item: TaskChecklistItem } | null>(null);
	let checklistDeleteOpen = $state(false);

	$effect(() => {
		if (!shell.loading && canView) {
			void loadTasks();
			if (canAssign) void loadAssignees();
		} else if (!shell.loading) loading = false;
	});

	async function api<T>(path: string, method = 'GET', body?: object): Promise<T> {
		const headers: Record<string, string> = { Accept: 'application/json' };
		if (method !== 'GET') {
			const token = csrfToken();
			if (!token) throw new Error('Reload this page and try again.');
			headers['X-XSRF-TOKEN'] = token;
			headers['Content-Type'] = 'application/json';
		}
		const response = await fetch(path, {
			method,
			credentials: 'same-origin',
			headers,
			...(body ? { body: JSON.stringify(body) } : {})
		});
		if (response.status === 401) {
			await goto('/login');
			throw new Error('Your session has expired.');
		}
		const result = (await response.json()) as T & ApiResponse;
		if (!response.ok) throw new Error(firstError(result) || result.message || 'Tasks could not complete that request.');
		return result as T;
	}

	async function loadTasks(): Promise<void> {
		loading = true;
		error = '';
		const query = new URLSearchParams({ status: statusFilter, sort: sortMode });
		if (priorityFilter !== 'all') query.set('priority', priorityFilter);
		if (dueFilter !== 'all') query.set('due', dueFilter);
		if (assigneeFilter) query.set('assignee_id', assigneeFilter);
		try {
			const result = await api<TasksResponse>('/api/tasks?' + query.toString());
			tasks = result.tasks ?? [];
			if (!assigneeFilter) visibleAssignees = visibleTaskAssignees(tasks);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Tasks could not be loaded.';
		} finally {
			loading = false;
		}
	}

	async function loadAssignees(): Promise<void> {
		try {
			const result = await api<TasksResponse>('/api/tasks/assignees');
			assignees = result.assignees ?? [];
		} catch {
			assignees = [];
		}
	}

	function resetForm(): void {
		formOpen = false;
		editing = null;
		title = '';
		description = '';
		dueInput = '';
		priority = 'medium';
		assigneeId = '';
		taskTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
		visibility = 'private';
		frequency = 'none';
		interval = '1';
	}

	function editTask(task: HouseholdTask): void {
		formOpen = true;
		editing = task;
		title = task.title;
		description = task.description ?? '';
		dueInput = task.due_at ? dateToLocalInput(task.due_at) : '';
		priority = task.priority;
		assigneeId = task.assignee ? String(task.assignee.id) : '';
		taskTimezone = task.timezone;
		visibility = task.visibility;
		frequency = task.recurrence_frequency ?? 'none';
		interval = String(task.recurrence_interval);
		message = '';
		error = '';
	}

	function dateToLocalInput(value: string): string {
		const date = new Date(value);
		const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
		return local.toISOString().slice(0, 16);
	}

	async function saveTask(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		error = '';
		message = '';
		saving = true;
		const payload = {
			title,
			description: description.trim() || null,
			due_at: dueInput ? new Date(dueInput).toISOString() : null,
			timezone: taskTimezone,
			priority,
			assignee_id: assigneeId ? Number(assigneeId) : null,
			recurrence_frequency: frequency === 'none' ? null : frequency,
			recurrence_interval: frequency === 'none' ? 1 : Number(interval),
			...(!editing ? { visibility: canCreateShared ? visibility : 'private' } : {}),
			...(editing ? { expected_version: editing.version } : {})
		};
		try {
			const result = await api<TasksResponse>(editing ? '/api/tasks/' + editing.id : '/api/tasks', editing ? 'PATCH' : 'POST', payload);
			message = editing ? 'Task updated.' : 'Task created.';
			resetForm();
			await loadTasks();
			if (result.task) await loadHistory(result.task.id, true);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The task could not be saved.';
		} finally {
			saving = false;
		}
	}

	async function completeTask(task: HouseholdTask): Promise<void> {
		error = '';
		message = '';
		try {
			await api<TasksResponse>('/api/tasks/' + task.id + '/complete', 'POST', { expected_version: task.version });
			message = task.recurrence_frequency ? 'Task occurrence completed. The next one is scheduled.' : 'Task completed.';
			await loadTasks();
			if (historyOpen === task.id) await loadHistory(task.id, true);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The task could not be completed.';
			if (error.toLowerCase().includes('changed')) await loadTasks();
		}
	}

	async function addChecklistItem(event: SubmitEvent, task: HouseholdTask): Promise<void> {
		event.preventDefault();
		error = '';
		message = '';
		try {
			await api<TasksResponse>('/api/tasks/' + task.id + '/checklist-items', 'POST', {
				title: checklistTitle,
				due_at: checklistDueInput ? new Date(checklistDueInput).toISOString() : null,
				reminder_lead_minutes: checklistDueInput ? Number(checklistLead) : 0,
				expected_version: task.version
			});
			checklistTitle = '';
			checklistDueInput = '';
			checklistLead = '0';
			message = 'Checklist item added.';
			await loadTasks();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The checklist item could not be added.';
			if (error.toLowerCase().includes('changed')) await loadTasks();
		}
	}

	function editChecklistItem(item: TaskChecklistItem): void {
		editingChecklistItemId = item.id;
		checklistEditTitle = item.title;
		checklistEditDueInput = item.due_at ? dateToLocalInput(item.due_at) : '';
		checklistEditLead = String(item.reminder_lead_minutes);
	}

	async function saveChecklistItem(event: SubmitEvent, task: HouseholdTask, item: TaskChecklistItem): Promise<void> {
		event.preventDefault();
		error = '';
		try {
			await api<TasksResponse>('/api/tasks/' + task.id + '/checklist-items/' + item.id, 'PATCH', {
				title: checklistEditTitle,
				due_at: checklistEditDueInput ? new Date(checklistEditDueInput).toISOString() : null,
				reminder_lead_minutes: checklistEditDueInput ? Number(checklistEditLead) : 0,
				expected_version: task.version
			});
			editingChecklistItemId = null;
			message = 'Checklist item updated.';
			await loadTasks();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The checklist item could not be updated.';
			if (error.toLowerCase().includes('changed')) await loadTasks();
		}
	}

	async function setChecklistItemCompleted(task: HouseholdTask, item: TaskChecklistItem, completed: boolean): Promise<void> {
		error = '';
		message = '';
		try {
			await api<TasksResponse>('/api/tasks/' + task.id + '/checklist-items/' + item.id + '/completion', 'PATCH', {
				completed,
				expected_version: task.version
			});
			message = completed ? 'Checklist item completed.' : 'Checklist item reopened.';
			await loadTasks();
			if (historyOpen === task.id) await loadHistory(task.id, true);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Checklist progress could not be updated.';
			if (error.toLowerCase().includes('changed')) await loadTasks();
		}
	}

	async function moveChecklistItem(task: HouseholdTask, item: TaskChecklistItem, direction: -1 | 1): Promise<void> {
		const ordered = [...task.checklist_items].sort((a, b) => a.position - b.position);
		const index = ordered.findIndex((candidate) => candidate.id === item.id);
		const target = index + direction;
		if (index < 0 || target < 0 || target >= ordered.length) return;
		[ordered[index], ordered[target]] = [ordered[target], ordered[index]];
		try {
			await api<TasksResponse>('/api/tasks/' + task.id + '/checklist-items/order', 'PATCH', {
				item_ids: ordered.map((candidate) => candidate.id),
				expected_version: task.version
			});
			await loadTasks();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Checklist order could not be updated.';
			if (error.toLowerCase().includes('changed')) await loadTasks();
		}
	}

	async function deleteChecklistItem(): Promise<void> {
		if (!checklistDeleteTarget) return;
		const { task, item } = checklistDeleteTarget;
		try {
			await api<TasksResponse>('/api/tasks/' + task.id + '/checklist-items/' + item.id, 'DELETE', { expected_version: task.version });
			checklistDeleteTarget = null;
			checklistDeleteOpen = false;
			message = 'Checklist item deleted.';
			await loadTasks();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The checklist item could not be deleted.';
			if (error.toLowerCase().includes('changed')) await loadTasks();
		}
	}

	async function deleteTask(task: HouseholdTask): Promise<void> {
		error = '';
		message = '';
		try {
			await api<TasksResponse>('/api/tasks/' + task.id, 'DELETE');
			message = 'Task deleted.';
			deleteTarget = null;
			deleteOpen = false;
			if (editing?.id === task.id) resetForm();
			await loadTasks();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'The task could not be deleted.';
		}
	}

	async function toggleHistory(task: HouseholdTask): Promise<void> {
		if (historyOpen === task.id) {
			historyOpen = null;
			return;
		}
		historyOpen = task.id;
		await loadHistory(task.id);
	}

	async function loadHistory(taskId: number, force = false): Promise<void> {
		if (!force && histories[taskId]) return;
		try {
			const result = await api<TasksResponse>('/api/tasks/' + taskId + '/history');
			histories = { ...histories, [taskId]: result.completions ?? [] };
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Completion history could not be loaded.';
		}
	}

	function updateFilters(): void {
		void loadTasks();
	}

	function describeRecurrence(task: HouseholdTask): string {
		if (!task.recurrence_frequency) return '';
		const translate = get(t);
		const frequencyLabel = translate(task.recurrence_frequency[0].toUpperCase() + task.recurrence_frequency.slice(1));
		return task.recurrence_interval === 1
			? translate('Repeats {frequency}', { frequency: frequencyLabel })
			: translate('Repeats every {interval} {frequency}', { interval: task.recurrence_interval, frequency: frequencyLabel });
	}
</script>

<svelte:head>
	<title>{$t('Tasks')} · Metricove</title>
	<meta name="description" content={$t('Coordinate private and shared household tasks, owners and repeatable routines.')} />
</svelte:head>

<svelte:window onfocus={updateFilters} />

<div class="space-y-6">
	<PageHeading eyebrow={$t('Household')} title={$t('Tasks')} description={$t('Assign household work, set a due date and keep recurring chores moving.')} />

	{#if error}<Notice tone="error" message={$t(error)} />{/if}
	{#if message}<Notice tone="success" message={$t(message)} />{/if}

	{#if !shell.loading && !canView}
		<Panel>
			<h2 class="text-lg font-semibold text-white">{$t('Tasks are not available')}</h2>
			<p class="mt-2 text-sm leading-6 text-slate-400">{$t('Ask a household administrator to enable Tasks and grant your account View access.')}</p>
		</Panel>
	{:else}
		{#if canCreate || canCreateShared || editing}
			<div class="flex justify-end"><Button onclick={() => { resetForm(); formOpen = true; }}>{$t('Add task')}</Button></div>
			<Modal bind:open={formOpen} title={$t(editing ? 'Edit task' : 'New task')}>
			<Panel>
				<div class="flex flex-wrap items-start justify-between gap-3">
					<div>
					<p class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-300">{$t(editing ? 'Edit task' : 'New task')}</p>
					<h2 class="mt-2 text-xl font-semibold text-white">{$t(editing ? 'Keep the next step clear.' : 'A task everyone can understand.')}</h2>
					</div>
					{#if editing}<Button variant="ghost" size="small" onclick={resetForm}>{$t('Cancel')}</Button>{/if}
				</div>
				<form class="mt-5 grid gap-4 md:grid-cols-2" onsubmit={saveTask}>
					<label class="block md:col-span-2">
						<span class="mb-1.5 block text-sm font-medium text-slate-300">{$t('Task title')}</span>
						<input required maxlength="180" bind:value={title} placeholder={$t('e.g. Book the car service')} class="ui-field w-full" />
					</label>
					<label class="block md:col-span-2">
						<span class="mb-1.5 block text-sm font-medium text-slate-300">{$t('Details')}</span>
						<textarea maxlength="4000" bind:value={description} rows="2" placeholder={$t('Add the context someone needs to get it done.')} class="ui-field w-full resize-y"></textarea>
					</label>
					<label class="block">
						<span class="mb-1.5 block text-sm font-medium text-slate-300">{$t('Due date')}</span>
						<input type="datetime-local" bind:value={dueInput} oninput={() => (taskTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC')} class="ui-field w-full" />
					</label>
					<label class="block">
						<span class="mb-1.5 block text-sm font-medium text-slate-300">{$t('Priority')}</span>
						<select bind:value={priority} class="ui-field w-full">
							<option value="low">{$t('Low')}</option><option value="medium">{$t('Medium')}</option><option value="high">{$t('High')}</option>
						</select>
					</label>
					{#if canAssign}
						<label class="block">
							<span class="mb-1.5 block text-sm font-medium text-slate-300">{$t('Assignee')}</span>
							<select bind:value={assigneeId} class="ui-field w-full"><option value="">{$t('Unassigned')}</option>{#each assignees as assignee (assignee.id)}<option value={String(assignee.id)}>{assignee.name}</option>{/each}</select>
						</label>
					{/if}
					<label class="block">
						<span class="mb-1.5 block text-sm font-medium text-slate-300">{$t('Repeats')}</span>
						<select bind:value={frequency} class="ui-field w-full">
							<option value="none">{$t('Does not repeat')}</option><option value="daily">{$t('Daily')}</option><option value="weekly">{$t('Weekly')}</option><option value="monthly">{$t('Monthly')}</option><option value="yearly">{$t('Yearly')}</option>
						</select>
					</label>
					{#if frequency !== 'none'}
						<label class="block">
							<span class="mb-1.5 block text-sm font-medium text-slate-300">{$t('Every')}</span>
							<input type="number" min="1" max="99" bind:value={interval} required class="ui-field w-full" />
						</label>
					{/if}
					{#if canCreateShared && !editing}
						<label class="block">
							<span class="mb-1.5 block text-sm font-medium text-slate-300">{$t('Visibility')}</span>
							<select bind:value={visibility} class="ui-field w-full"><option value="private">{$t('Private')}</option><option value="household">{$t('Shared with household')}</option></select>
						</label>
					{/if}
					<div class="flex flex-wrap items-center gap-3 md:col-span-2">
						<Button type="submit" disabled={saving || (!editing && !canCreate && !canCreateShared)}>{saving ? $t('Saving…') : $t(editing ? 'Save task' : 'Add task')}</Button>
						{#if !editing}<p class="text-xs leading-5 text-slate-400">{$t('Tasks are private by default. Household tasks are visible to members with access.')}</p>{/if}
					</div>
				</form>
			</Panel>
			</Modal>
		{/if}

		<Panel>
			<div class="flex flex-wrap items-end justify-between gap-4">
				<div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-300">{$t('Your tasks')}</p><h2 class="mt-2 text-xl font-semibold text-white">{$t('Keep the household moving.')}</h2></div>
				<div class="grid w-full gap-3 sm:grid-cols-2 xl:w-auto xl:grid-cols-5">
					<label><span class="mb-1 block text-xs text-slate-400">{$t('Status')}</span><select bind:value={statusFilter} onchange={updateFilters} class="ui-field w-full"><option value="open">{$t('Open')}</option><option value="completed">{$t('Completed')}</option><option value="all">{$t('All')}</option></select></label>
					<label><span class="mb-1 block text-xs text-slate-400">{$t('Priority')}</span><select bind:value={priorityFilter} onchange={updateFilters} class="ui-field w-full"><option value="all">{$t('All priorities')}</option><option value="high">{$t('High')}</option><option value="medium">{$t('Medium')}</option><option value="low">{$t('Low')}</option></select></label>
					<label><span class="mb-1 block text-xs text-slate-400">{$t('Due date')}</span><select bind:value={dueFilter} onchange={updateFilters} class="ui-field w-full"><option value="all">{$t('Any date')}</option><option value="overdue">{$t('Overdue')}</option><option value="upcoming">{$t('Upcoming')}</option><option value="none">{$t('No due date')}</option></select></label>
					<label><span class="mb-1 block text-xs text-slate-400">{$t('Assignee')}</span><select bind:value={assigneeFilter} onchange={updateFilters} class="ui-field w-full"><option value="">{$t('Anyone')}</option>{#each visibleAssignees as assignee (assignee.id)}<option value={String(assignee.id)}>{assignee.name}</option>{/each}</select></label>
					<label><span class="mb-1 block text-xs text-slate-400">{$t('Sort by')}</span><select bind:value={sortMode} onchange={updateFilters} class="ui-field w-full"><option value="due_at">{$t('Due date')}</option><option value="priority">{$t('Priority')}</option><option value="assignee">{$t('Assignee')}</option></select></label>
				</div>
			</div>

			{#if loading}
				<p class="mt-6 text-sm text-slate-400">{$t('Loading tasks…')}</p>
			{:else if tasks.length === 0}
				<div class="mt-6 rounded-2xl border border-dashed border-slate-700 px-5 py-8 text-center"><p class="text-base font-semibold text-white">{$t('Nothing on the list yet.')}</p><p class="mt-2 text-sm text-slate-400">{$t('Add a household task or adjust your filters.')}</p></div>
			{:else}
				<ul class="mt-5 space-y-3">
					{#each tasks as task (task.id)}
						<li id="task-{task.id}" class="rounded-2xl border border-slate-800 bg-slate-950/50 p-4 sm:p-5">
							<div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
								<div class="min-w-0 flex-1">
									<div class="flex flex-wrap items-center gap-2"><h3 class="text-base font-semibold text-white">{task.title}</h3><span class="rounded-full px-2.5 py-1 text-xs font-medium {task.priority === 'high' ? 'bg-rose-400/10 text-rose-200' : task.priority === 'medium' ? 'bg-amber-300/10 text-amber-200' : 'bg-slate-800 text-slate-300'}">{$t(task.priority[0].toUpperCase() + task.priority.slice(1))}</span><span class="rounded-full border border-slate-700 px-2.5 py-1 text-xs text-slate-300">{$t(task.visibility === 'private' ? 'Private' : 'Shared')}</span>{#if task.is_completed}<span class="rounded-full bg-emerald-400/10 px-2.5 py-1 text-xs text-emerald-200">{$t('Completed')}</span>{/if}</div>
									{#if task.description}<p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-300">{task.description}</p>{/if}
									<div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-xs text-slate-400">
						{#if task.due_at}<span>{$t('Due')} {formatDateTime(task.due_at)}</span>{:else}<span>{$t('No due date')}</span>{/if}
										{#if task.assignee}<span>{$t('Assigned to')} {task.assignee.name}</span>{:else}<span>{$t('Unassigned')}</span>{/if}
										{#if task.recurrence_frequency}<span>{describeRecurrence(task)}</span>{/if}
									</div>
								</div>
								<div class="flex shrink-0 flex-wrap gap-2">
									{#if task.can_complete}<Button size="small" onclick={() => void completeTask(task)}>{$t(task.recurrence_frequency ? 'Complete occurrence' : 'Complete')}</Button>{/if}
									{#if task.can_edit}<Button variant="secondary" size="small" onclick={() => editTask(task)}>{$t('Edit')}</Button><Button variant="danger" size="small" onclick={() => { deleteTarget = task; deleteOpen = true; }}>{$t('Delete')}</Button>{/if}
									<Button variant="ghost" size="small" onclick={() => void toggleHistory(task)}>{$t(historyOpen === task.id ? 'Hide history' : 'History')}</Button>
								</div>
							</div>
							<div class="mt-4 border-t border-slate-800 pt-3">
								<div class="flex flex-wrap items-center justify-between gap-2">
									<p class="text-sm font-medium text-slate-200">{$t('Checklist')} <span class="text-xs text-slate-400">{task.checklist_items.filter((item) => item.is_completed).length}/{task.checklist_items.length}</span></p>
									<Button variant="ghost" size="small" aria-expanded={checklistOpenId === task.id} onclick={() => (checklistOpenId = checklistOpenId === task.id ? null : task.id)}>{$t(checklistOpenId === task.id ? 'Hide checklist' : 'Show checklist')}</Button>
								</div>
								{#if checklistOpenId === task.id}
									{#if task.checklist_items.length === 0}<p class="mt-2 text-sm text-slate-400">{$t('No checklist items yet.')}</p>{/if}
									<ol class="mt-3 space-y-3">
										{#each task.checklist_items as item, index (item.id)}
											<li id="task-{task.id}-checklist-item-{item.id}" class="rounded-xl border border-slate-800 bg-slate-900/60 p-3">
												{#if editingChecklistItemId === item.id}
													<form class="grid gap-3 sm:grid-cols-2" onsubmit={(event) => void saveChecklistItem(event, task, item)}>
														<label class="sm:col-span-2"><span class="mb-1 block text-xs text-slate-400">{$t('Checklist item')}</span><input required maxlength="180" bind:value={checklistEditTitle} class="ui-field w-full" /></label>
														<label><span class="mb-1 block text-xs text-slate-400">{$t('Due date')}</span><input type="datetime-local" bind:value={checklistEditDueInput} class="ui-field w-full" /></label>
														<label><span class="mb-1 block text-xs text-slate-400">{$t('Remind before due')}</span><select bind:value={checklistEditLead} disabled={!checklistEditDueInput} class="ui-field w-full"><option value="0">{$t('At due time')}</option><option value="15">15 {$t('minutes')}</option><option value="60">1 {$t('hour')}</option><option value="1440">1 {$t('day')}</option><option value="10080">1 {$t('week')}</option></select></label>
														<div class="flex gap-2 sm:col-span-2"><Button type="submit" size="small">{$t('Save item')}</Button><Button type="button" variant="ghost" size="small" onclick={() => (editingChecklistItemId = null)}>{$t('Cancel')}</Button></div>
													</form>
												{:else}
													<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
														<label class="flex min-w-0 items-start gap-3 text-sm text-slate-200">
															<input type="checkbox" checked={item.is_completed} disabled={!task.can_complete || task.is_completed} onchange={(event) => void setChecklistItemCompleted(task, item, event.currentTarget.checked)} class="mt-0.5 size-4 rounded border-slate-600 bg-slate-950 text-cyan-400 focus:ring-cyan-400" />
															<span class={item.is_completed ? 'text-slate-500 line-through' : ''}>{item.title}<span class="mt-1 block text-xs text-slate-500">{#if item.due_at}{$t('Due')} {formatDateTime(item.due_at)}{#if item.reminder_lead_minutes > 0} · {$t('Reminder set')}{/if}{:else}{$t('No due date')}{/if}{#if item.completed_by} · {$t('Completed by')} {item.completed_by}{/if}</span></span>
														</label>
														{#if task.can_edit_checklist && !task.is_completed}<div class="flex flex-wrap gap-1"><Button variant="ghost" size="small" aria-label={$t('Move up')} disabled={index === 0} onclick={() => void moveChecklistItem(task, item, -1)}>↑</Button><Button variant="ghost" size="small" aria-label={$t('Move down')} disabled={index === task.checklist_items.length - 1} onclick={() => void moveChecklistItem(task, item, 1)}>↓</Button><Button variant="ghost" size="small" onclick={() => editChecklistItem(item)}>{$t('Edit')}</Button><Button variant="danger" size="small" onclick={() => { checklistDeleteTarget = { task, item }; checklistDeleteOpen = true; }}>{$t('Delete')}</Button></div>{/if}
													</div>
												{/if}
											</li>
										{/each}
									</ol>
									{#if task.can_edit_checklist && !task.is_completed}
										<form class="mt-4 grid gap-3 border-t border-slate-800 pt-4 sm:grid-cols-2" onsubmit={(event) => void addChecklistItem(event, task)}>
											<label class="sm:col-span-2"><span class="mb-1 block text-xs text-slate-400">{$t('New checklist item')}</span><input required maxlength="180" bind:value={checklistTitle} placeholder={$t('e.g. Take a photo of the meter')} class="ui-field w-full" /></label>
											<label><span class="mb-1 block text-xs text-slate-400">{$t('Due date')}</span><input type="datetime-local" bind:value={checklistDueInput} class="ui-field w-full" /></label>
											<label><span class="mb-1 block text-xs text-slate-400">{$t('Remind before due')}</span><select bind:value={checklistLead} disabled={!checklistDueInput} class="ui-field w-full"><option value="0">{$t('At due time')}</option><option value="15">15 {$t('minutes')}</option><option value="60">1 {$t('hour')}</option><option value="1440">1 {$t('day')}</option><option value="10080">1 {$t('week')}</option></select></label>
											<div class="sm:col-span-2"><Button type="submit">{$t('Add checklist item')}</Button></div>
										</form>
									{/if}
								{/if}
							</div>
							{#if historyOpen === task.id}
								<div class="mt-4 border-t border-slate-800 pt-4"><p class="text-xs font-semibold uppercase tracking-[0.15em] text-slate-400">{$t('Completion history')}</p>
									{#if (histories[task.id] ?? []).length === 0}<p class="mt-2 text-sm text-slate-400">{$t('No completed occurrences yet.')}</p>{:else}<ol class="mt-3 space-y-3">{#each histories[task.id] as completion (completion.id)}<li class="rounded-xl border border-slate-800 p-3"><div class="flex flex-wrap justify-between gap-2 text-sm text-slate-300"><span>{$t('Occurrence')} {completion.occurrence_number} · {completion.completed_by ?? $t('Former member')}</span><time datetime={completion.completed_at}>{formatDateTime(completion.completed_at)}</time></div>{#if completion.checklist_items.length > 0}<ul class="mt-2 space-y-1 text-xs text-slate-400">{#each completion.checklist_items as item, itemIndex (`${completion.id}-${itemIndex}`)}<li>{item.is_completed ? '✓' : '○'} {item.title}{#if item.completed_by} · {item.completed_by}{/if}</li>{/each}</ul>{/if}</li>{/each}</ol>{/if}
								</div>
							{/if}
						</li>
					{/each}
				</ul>
				<p class="mt-4 text-xs text-slate-500">{$t('Showing up to 200 visible tasks.')}</p>
			{/if}
		</Panel>
	{/if}
	{#if deleteTarget}<ConfirmModal bind:open={deleteOpen} title={$t('Delete task?')} message={$t('Delete this task and its completion history?')} onConfirm={() => void deleteTask(deleteTarget!)} />{/if}
	{#if checklistDeleteTarget}<ConfirmModal bind:open={checklistDeleteOpen} title={$t('Delete checklist item?')} message={$t('Delete this checklist item from the task and future occurrences?')} onConfirm={() => void deleteChecklistItem()} />{/if}
</div>
