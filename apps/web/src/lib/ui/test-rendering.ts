import { plugin } from 'bun';
import { compile, compileModule } from 'svelte/compiler';

plugin({
	name: 'svelte-server-rendering',
	setup(build) {
		build.onLoad({ filter: /\.svelte$/ }, async ({ path }) => {
			// Render portal children inline to exercise dialog markup with the server renderer.
			const source = path.endsWith('/bits-ui/dist/bits/utilities/portal/portal.svelte')
				? '<script>let { children } = $props();<\/script>{@render children?.()}'
				: await Bun.file(path).text();
			return {
				contents: compile(source, { filename: path, generate: 'server' }).js.code,
				loader: 'js'
			};
		});
		build.onLoad({ filter: /\.svelte\.js$/ }, async ({ path }) => ({
			contents: compileModule(await Bun.file(path).text(), { filename: path, generate: 'server' }).js.code,
			loader: 'js'
		}));
	}
});
