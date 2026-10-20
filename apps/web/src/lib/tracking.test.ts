import { describe, expect, it } from 'bun:test';
import { newTrackerField } from './tracking';

describe('newTrackerField', () => {
	it('creates a number field draft with an independent local key', () => {
		expect(newTrackerField(12)).toEqual({
			local_key: 12,
			label: '',
			type: 'number',
			unit: '',
			required: false,
			minimum: '',
			maximum: ''
		});
	});
});
