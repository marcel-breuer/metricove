# Skill: Food Domain

**Purpose:** Work across independently optional Recipes, MealPlanning, Pantry and Shopping. **Use when:** a food-related issue changes schemas, APIs or UI.

**Context:** AGENTS.md, module contract, affected food services/models, permission tests.

**Procedure:**
1. Confirm the affected module's standalone behavior, and any optional integration.
2. Use decimal quantities with explicit compatible units and optional conversion factors.
3. Support manual recipes/ingredients, editable weekly meal slots and free-text dishes.
4. Record inventory movements and optional best-before dates without inventing stock.
5. Keep shopping items editable independently, with opt-in import of meal ingredients.
6. Gate cross-module operations and AI suggestions on all necessary permissions.
7. Test missing links, disabled integrations, shared/private access and user confirmation.

**Validation:** Domain tests, cross-module negative tests, SvelteKit checks.

**Expected output:** Changes, quantities/ownership behavior, migration notes and test results.

**Constraints:** No automatic purchasing or silent stock decrements.

**Completion:** Food functionality remains correct with any optional neighboring module disabled.
