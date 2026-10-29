export type TaskPriority = 'low' | 'medium' | 'high';
export type TaskFrequency = 'daily' | 'weekly' | 'monthly' | 'yearly';
export type TaskVisibility = 'private' | 'household';

export type TaskChecklistItem = {
	id: number;
	title: string;
	position: number;
	due_at: string | null;
	reminder_lead_minutes: number;
	is_completed: boolean;
	completed_by: string | null;
	completed_at: string | null;
};

export type HouseholdTask = {
	id: number;
	title: string;
	description: string | null;
	due_at: string | null;
	timezone: string;
	priority: TaskPriority;
	recurrence_frequency: TaskFrequency | null;
	recurrence_interval: number;
	visibility: TaskVisibility;
	is_completed: boolean;
	completed_at: string | null;
	occurrence_number: number;
	version: number;
	assignee: { id: number; name: string } | null;
	can_edit: boolean;
	can_edit_checklist: boolean;
	can_complete: boolean;
	checklist_items: TaskChecklistItem[];
};

export type TaskAssignee = { id: number; name: string };

/** Extract filter choices from a task list the API has already authorized for the current member. */
export function visibleTaskAssignees(tasks: Pick<HouseholdTask, 'assignee'>[]): TaskAssignee[] {
	const options = new Map<number, TaskAssignee>();
	for (const task of tasks) {
		if (task.assignee) options.set(task.assignee.id, task.assignee);
	}
	return [...options.values()];
}

export type TaskCompletion = {
	id: number;
	occurrence_number: number;
	completed_by: string | null;
	completed_at: string;
	checklist_items: Array<{ title: string; is_completed: boolean; completed_by: string | null; completed_at: string | null }>;
};

export type TasksResponse = {
	tasks?: HouseholdTask[];
	task?: HouseholdTask;
	assignees?: TaskAssignee[];
	completions?: TaskCompletion[];
	message?: string;
	errors?: Record<string, string[]>;
};
