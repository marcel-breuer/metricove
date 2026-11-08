import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'bun:test';
import { compile } from 'svelte/compiler';

const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');

const detailRoutes = [
	{ module: 'recipes', list: './recipes/+page.svelte', detail: './recipes/[recipeId]/+page.svelte', editor: './recipes/RecipeEditor.svelte', api: '/api/recipes/' },
	{ module: 'notes', list: './notes/+page.svelte', detail: './notes/[noteId]/+page.svelte', editor: './notes/NoteEditor.svelte', api: '/api/notes/' }
];

describe('entity detail subpages', () => {
	for (const route of detailRoutes) {
		it(`${route.module} list links to its detail route instead of an inline or modal view`, () => {
			const list = read(route.list);
			expect(list).toContain(`href="/modules/${route.module}/{`);
			expect(list).not.toMatch(/detailOpen|viewedRecipe|selected\?\.id/);
		});

		it(`${route.module} detail page loads through the module API with View access and links back`, () => {
			const detail = read(route.detail);
			expect(detail).toContain(`module.key === '${route.module}'`);
			expect(detail).toContain('capabilities.view');
			expect(detail).toContain(route.api);
			expect(detail).toContain(`<BackLink href="/modules/${route.module}"`);
		});

		it(`${route.module} create/edit forms use the fullscreen modal`, () => {
			const editor = read(route.editor);
			expect(editor).toMatch(/<Modal bind:open size="fullscreen"/);
			expect(editor).toContain('{#snippet footer()}');
			expect(editor).toMatch(/type="submit" form=\{formId\}/);
		});

		for (const path of [route.list, route.detail, route.editor]) {
			it(`${path} compiles without non-reactive state warnings`, () => {
				const { warnings } = compile(read(path), { filename: path, generate: 'client' });
				expect(warnings.filter((warning) => warning.code === 'non_reactive_update').map((warning) => warning.message)).toEqual([]);
			});
		}
	}

	it('uses the fullscreen modal for the main create and edit forms of other modules', () => {
		for (const path of ['./tasks/+page.svelte', './pantry/+page.svelte', './meal_planning/+page.svelte', './tracking/+page.svelte', './tracking/[trackerId]/+page.svelte', './vehicles/+page.svelte', './vehicles/[vehicleId]/+page.svelte', './finance/+page.svelte', './utilities/+page.svelte', './receipts/+page.svelte']) {
			expect(read(path)).toContain('size="fullscreen"');
		}
	});
});
