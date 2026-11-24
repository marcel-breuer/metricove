# Metricove web app

The web app uses SvelteKit, TypeScript, Tailwind CSS 4, shadcn-svelte and the Bun server adapter. Use Bun 1.4 or newer.

```sh
bun install --frozen-lockfile
bun run dev --host 0.0.0.0
```

The app is served at `http://localhost:5173`; its JSON health check is at `/health`.

Add registry components from `apps/web` with `bunx shadcn-svelte@latest add <component>`. The CLI installs them in `src/lib/components/ui`. Use existing wrappers in `src/lib/ui` where they provide the app's shared behavior. The semantic colors and glass utilities live in `src/app.css` and follow the app's light and dark theme preference.

Run the frontend checks with

```sh
bun run check
bun run --bun build
```
