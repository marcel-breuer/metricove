import { describe, expect, it } from 'bun:test';
import { visibleTaskAssignees } from './tasks';

describe('visibleTaskAssignees', () => {
	it('uses only assignees in the authorized task list and removes duplicates', () => {
		expect(
			visibleTaskAssignees([
				{ assignee: { id: 7, name: 'Jordan' } },
				{ assignee: null },
				{ assignee: { id: 7, name: 'Jordan' } },
				{ assignee: { id: 9, name: 'Sam' } }
			])
		).toEqual([
			{ id: 7, name: 'Jordan' },
			{ id: 9, name: 'Sam' }
		]);
	});
});
