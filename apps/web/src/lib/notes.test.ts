import { describe, expect, it } from 'bun:test';
import { parseNoteTags } from './notes';

describe('parseNoteTags', () => {
	it('trims empty values and deduplicates without changing the first spelling', () => {
		expect(parseNoteTags(' Home, trip,home, , Trip plans ')).toEqual(['Home', 'trip', 'Trip plans']);
	});
});
