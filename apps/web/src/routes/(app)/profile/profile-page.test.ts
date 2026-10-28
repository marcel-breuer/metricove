import { readFile } from 'node:fs/promises';
import { expect, test } from 'bun:test';
import { compile } from 'svelte/compiler';

test('profile page keeps mutable state reactive in runes mode', async () => {
	const source = await readFile(new URL('./+page.svelte', import.meta.url), 'utf8');
	const { warnings } = compile(source, {
		filename: 'profile/+page.svelte',
		generate: 'client'
	});

	const nonReactiveUpdates = warnings
		.filter((warning) => warning.code === 'non_reactive_update')
		.map((warning) => warning.message);

	expect(nonReactiveUpdates).toEqual([]);
});
