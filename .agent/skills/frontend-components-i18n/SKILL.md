---
name: frontend-components-i18n
description: Use for any Metricove frontend change to reuse or create accessible Svelte components and keep user-facing UI copy translated in every supported language.
---

# Frontend Components and i18n

**Purpose:** Keep the SvelteKit frontend consistent, reusable, accessible and fully localized. **Use when:** making any frontend change, including routes, forms, dialogs, navigation, shared UI or user-facing text.

## Components

1. Before adding UI markup, inspect `apps/web/src/lib/ui` and nearby feature components for an existing fit. Reuse the closest suitable component and its established props and styling conventions.
2. When no suitable component exists for a reusable visual or interaction pattern, create a typed, accessible component and use it in the current feature. Put cross-feature primitives in `apps/web/src/lib/ui`; keep domain-specific behavior with its feature so other screens can reuse it there.
3. Avoid duplicating component markup across routes. Keep one-off domain content in its route when extracting it would not create a useful reusable boundary; do not add wrapper components solely to satisfy this rule.
4. Preserve native semantics, keyboard behavior, labels, focus handling and responsive layouts when reusing or creating components.

## Localization

1. Treat every visible or assistive user-facing string as localizable: headings, labels, placeholders, button text, validation and error messages, empty states, status text, tooltips and accessible names.
2. Use the established `t` helper from `#lib/i18n`; do not add hard-coded UI copy to Svelte markup or scripts. Add translations for every supported UI language when adding or changing copy. The current catalog supports English and German in `apps/web/src/lib/i18n.ts`.
3. Preserve interpolation values across translations. Keep user-provided content, domain values and API identifiers intact; localize only their presentation.
4. Use the existing locale-aware date, number and currency formatters instead of manually formatting those values.

**Context:** The shared UI components live in `apps/web/src/lib/ui`; `apps/web/src/lib/i18n.ts` and its tests define the current translation and formatting conventions.
