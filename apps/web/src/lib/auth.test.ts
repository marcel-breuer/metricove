import { describe, expect, it } from 'bun:test';
import { firstError } from './auth';

describe('firstError', () => {
	it('returns the first validation error in response order', () => {
		expect(
			firstError({
				errors: {
					email: ['Enter a valid email address.'],
					password: ['Choose a longer password.']
				}
			})
		).toBe('Enter a valid email address.');
	});

	it('falls back to the response message when there are no field errors', () => {
		expect(firstError({ message: 'The request could not be completed.' })).toBe(
			'The request could not be completed.'
		);
	});

	it('returns an empty message when the response contains no error details', () => {
		expect(firstError({})).toBe('');
	});
});
