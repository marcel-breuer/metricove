export type TaskPriority = 'low' | 'medium' | 'high';
export type TaskFrequency = 'daily' | 'weekly' | 'monthly' | 'yearly';
export type TaskVisibility = 'private' | 'household';

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
	can_complete: boolean;
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
};

export type TasksResponse = {
	tasks?: HouseholdTask[];
	task?: HouseholdTask;
	assignees?: TaskAssignee[];
	completions?: TaskCompletion[];
	message?: string;
	errors?: Record<string, string[]>;
};
