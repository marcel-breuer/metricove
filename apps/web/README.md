# Metricove web app

The web app uses SvelteKit, TypeScript, Tailwind CSS and the Bun server adapter. Use Bun 1.4 or newer.

```sh
bun install --frozen-lockfile
bun run dev --host 0.0.0.0
```

The app is served at `http://localhost:5173`; its JSON health check is at `/health`.

Validate the web app with:

```sh
bun run check
bun run --bun build
```
