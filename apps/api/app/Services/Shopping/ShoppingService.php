<?php

namespace App\Services\Shopping;

use App\Enums\ModuleCapability;
use App\Exceptions\ModuleDisabled;
use App\Exceptions\ModuleNotFound;
use App\Models\HouseholdSettings;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListEvent;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShoppingService
{
    private const MODULE = 'shopping';

    public function __construct(private readonly ModuleAccessService $access) {}

    /** @return list<array<string, mixed>> */
    public function index(User $actor): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);

        return $this->withCounts($this->visibleLists($actor, [ModuleCapability::View], ShoppingList::query()))
            ->orderByDesc('updated_at')->orderBy('name')->orderBy('id')->limit(100)->get()
            ->map(fn (ShoppingList $list): array => $this->presentList($actor, $list))
            ->all();
    }

    /** @return array{list: array<string, mixed>, items: list<array<string, mixed>>} */
    public function show(User $actor, int $listId): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $list = $this->withCounts($this->visibleLists($actor, [ModuleCapability::View], ShoppingList::query()))
            ->whereKey($listId)->firstOrFail();
        $canEdit = $this->canUse($actor, $list, ModuleCapability::Edit);
        $items = $list->items()->orderBy('is_checked')->orderBy('sort_order')->orderBy('id')->get();

        return [
            'list' => $this->presentList($actor, $list, $canEdit),
            'items' => $items->map(fn (ShoppingItem $item): array => $this->presentItem($item, $canEdit))->all(),
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createList(User $actor, array $data): array
    {
        $private = $data['visibility'] === 'private';
        $list = DB::transaction(function () use ($actor, $data, $private): ShoppingList {
            if ($private) {
                $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);
            } else {
                $this->authorizeSharedManagement($actor);
            }

            $list = ShoppingList::query()->create([
                'owner_user_id' => $private ? $actor->id : null,
                'created_by_user_id' => $actor->id,
                'is_private' => $private,
                'name' => trim($data['name']),
                'version' => 1,
            ]);
            $this->recordEvent($list, null, $actor, 'list_created', null, $this->listSnapshot($list));

            return $list;
        }, 3);

        return $this->presentList($actor, $this->withCounts(ShoppingList::query())->findOrFail($list->id));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateList(User $actor, int $listId, array $data): array
    {
        $list = DB::transaction(function () use ($actor, $listId, $data): ShoppingList {
            $list = $this->findListForMutation($actor, $listId, [ModuleCapability::Edit, ModuleCapability::Manage]);
            $this->authorizeListCapability($actor, $list, ModuleCapability::Edit);
            $this->assertVersion($list->version, (int) $data['expected_version'], 'This shopping list changed since it was loaded. Refresh it and retry.');
            $before = $this->listSnapshot($list);
            $list->name = trim($data['name']);
            $list->version++;
            $list->save();
            $this->recordEvent($list, null, $actor, 'list_renamed', $before, $this->listSnapshot($list));

            return $list->fresh();
        }, 3);

        return $this->presentList($actor, $this->withCounts(ShoppingList::query())->findOrFail($list->id));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createItem(User $actor, int $listId, array $data): array
    {
        $quantity = ShoppingQuantity::normalize($data['quantity'] ?? null, $data['unit'] ?? null);
        [$item, $canEdit] = DB::transaction(function () use ($actor, $listId, $data, $quantity): array {
            $list = $this->findListForMutation($actor, $listId, [ModuleCapability::Create, ModuleCapability::Manage]);
            $this->authorizeListCapability($actor, $list, ModuleCapability::Create);
            $item = $list->items()->create([
                'created_by_user_id' => $actor->id,
                'name' => trim($data['name']),
                'quantity' => $quantity['quantity'],
                'unit' => $quantity['unit'],
                'sort_order' => ((int) $list->items()->max('sort_order')) + 100,
                'is_checked' => false,
                'version' => 1,
            ]);
            $this->bumpList($list);
            $this->recordEvent($list, $item, $actor, 'item_added', null, $this->itemSnapshot($item));

            return [$item, $this->canUse($actor, $list, ModuleCapability::Edit)];
        }, 3);

        return $this->presentItem($item, $canEdit);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateItem(User $actor, int $itemId, array $data): array
    {
        [$item, $canEdit] = DB::transaction(function () use ($actor, $itemId, $data): array {
            [$list, $item] = $this->lockItemForMutation($actor, $itemId, [ModuleCapability::Edit, ModuleCapability::Manage]);
            $this->authorizeListCapability($actor, $list, ModuleCapability::Edit);
            $this->assertVersion($item->version, (int) $data['expected_version'], 'This shopping item changed since it was loaded. Refresh it and retry.');
            $before = $this->itemSnapshot($item);
            $changed = false;

            if (array_key_exists('name', $data) && trim($data['name']) !== $item->name) {
                $item->name = trim($data['name']);
                $changed = true;
            }
            if (array_key_exists('quantity', $data) || array_key_exists('unit', $data)) {
                $quantity = ShoppingQuantity::normalize(
                    array_key_exists('quantity', $data) ? $data['quantity'] : ($item->quantity === null ? null : (string) $item->quantity),
                    array_key_exists('unit', $data) ? $data['unit'] : $item->unit,
                );
                if ($quantity['quantity'] !== ($item->quantity === null ? null : (string) $item->quantity)
                    || $quantity['unit'] !== $item->unit) {
                    $item->quantity = $quantity['quantity'];
                    $item->unit = $quantity['unit'];
                    $changed = true;
                }
            }

            $eventType = 'item_updated';
            if (array_key_exists('sort_order', $data) && (int) $data['sort_order'] !== $item->sort_order) {
                $item->sort_order = (int) $data['sort_order'];
                $eventType = $changed ? 'item_updated' : 'item_reordered';
                $changed = true;
            }

            if ($changed) {
                $item->version++;
                $item->save();
                $this->bumpList($list);
                $this->recordEvent($list, $item, $actor, $eventType, $before, $this->itemSnapshot($item));
            }

            return [$item->fresh(), $this->canUse($actor, $list, ModuleCapability::Edit)];
        }, 3);

        return $this->presentItem($item, $canEdit);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function setChecked(User $actor, int $itemId, array $data): array
    {
        [$item, $canEdit] = DB::transaction(function () use ($actor, $itemId, $data): array {
            [$list, $item] = $this->lockItemForMutation($actor, $itemId, [ModuleCapability::Edit, ModuleCapability::Manage]);
            $this->authorizeListCapability($actor, $list, ModuleCapability::Edit);
            $this->assertVersion($item->version, (int) $data['expected_version'], 'This shopping item changed since it was loaded. Refresh it and retry.');
            $checked = (bool) $data['checked'];

            if ($item->is_checked === $checked) {
                return [$item, $this->canUse($actor, $list, ModuleCapability::Edit)];
            }

            $before = $this->itemSnapshot($item);
            $item->is_checked = $checked;
            $item->checked_at = $checked ? now() : null;
            $item->checked_by_user_id = $checked ? $actor->id : null;
            $item->version++;
            $item->save();
            $this->bumpList($list);
            $this->recordEvent($list, $item, $actor, $checked ? 'item_checked' : 'item_unchecked', $before, $this->itemSnapshot($item));

            return [$item->fresh(), $this->canUse($actor, $list, ModuleCapability::Edit)];
        }, 3);

        return $this->presentItem($item, $canEdit);
    }

    public function moveItem(User $actor, int $itemId, array $data): void
    {
        DB::transaction(function () use ($actor, $itemId, $data): void {
            [$list, $item] = $this->lockItemForMutation($actor, $itemId, [ModuleCapability::Edit, ModuleCapability::Manage]);
            $this->authorizeListCapability($actor, $list, ModuleCapability::Edit);
            $this->assertVersion($item->version, (int) $data['expected_version'], 'This shopping item changed since it was loaded. Refresh it and retry.');
            $items = $list->items()->where('is_checked', $item->is_checked)
                ->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();
            $position = $items->search(fn (ShoppingItem $candidate): bool => $candidate->id === $item->id);
            $neighborPosition = $data['direction'] === 'up' ? $position - 1 : $position + 1;
            if ($position === false || ! $items->has($neighborPosition)) {
                return;
            }

            $neighbor = $items->get($neighborPosition);
            $itemBefore = $this->itemSnapshot($item);
            $neighborBefore = $this->itemSnapshot($neighbor);
            [$item->sort_order, $neighbor->sort_order] = [$neighbor->sort_order, $item->sort_order];
            $item->version++;
            $neighbor->version++;
            $item->save();
            $neighbor->save();
            $this->bumpList($list);
            $this->recordEvent($list, $item, $actor, 'item_reordered', $itemBefore, $this->itemSnapshot($item));
            $this->recordEvent($list, $neighbor, $actor, 'item_reordered', $neighborBefore, $this->itemSnapshot($neighbor));
        }, 3);
    }

    public function deleteItem(User $actor, int $itemId): void
    {
        DB::transaction(function () use ($actor, $itemId): void {
            [$list, $item] = $this->lockItemForMutation($actor, $itemId, [ModuleCapability::Edit, ModuleCapability::Manage]);
            $this->authorizeListCapability($actor, $list, ModuleCapability::Edit);
            $before = $this->itemSnapshot($item);
            $this->recordEvent($list, $item, $actor, 'item_deleted', $before, null);
            $item->delete();
            $this->bumpList($list);
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    public function history(User $actor, int $listId): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $list = $this->visibleLists($actor, [ModuleCapability::View], ShoppingList::query())
            ->whereKey($listId)->firstOrFail();

        return $list->events()->with('actor')->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get()
            ->map(fn (ShoppingListEvent $event): array => [
                'id' => $event->id,
                'type' => $event->event_type,
                'item_id' => $event->shopping_item_id === null ? null : (int) $event->shopping_item_id,
                'actor' => $event->actor?->name,
                'before' => $event->before_state,
                'after' => $event->after_state,
                'created_at' => $event->created_at?->toISOString(),
            ])->all();
    }

    private function findListForMutation(User $actor, int $listId, array $capabilities): ShoppingList
    {
        return $this->visibleLists($actor, $capabilities, ShoppingList::query())
            ->whereKey($listId)->lockForUpdate()->firstOrFail();
    }

    /** @return array{ShoppingList, ShoppingItem} */
    private function lockItemForMutation(User $actor, int $itemId, array $capabilities): array
    {
        $list = $this->visibleLists($actor, $capabilities, ShoppingList::query())
            ->whereHas('items', fn (Builder $items): Builder => $items->whereKey($itemId))
            ->lockForUpdate()->firstOrFail();
        $item = $list->items()->whereKey($itemId)->lockForUpdate()->firstOrFail();

        return [$list, $item];
    }

    /** @param list<ModuleCapability> $capabilities */
    private function visibleLists(User $actor, array $capabilities, Builder $query): Builder
    {
        $morphType = (new ShoppingList)->getMorphClass();
        $values = array_map(fn (ModuleCapability $capability): string => $capability->value, $capabilities);

        return $query->where(function (Builder $visible) use ($actor, $morphType, $values): void {
            $visible->where('is_private', false)
                ->orWhere('owner_user_id', $actor->id)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $morphType, $values): void {
                    $permissions->selectRaw('1')->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', 'shopping_lists.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->whereIn('resource_user_permissions.capability', $values);
                });
        });
    }

    private function authorizeListCapability(User $actor, ShoppingList $list, ModuleCapability $capability): void
    {
        try {
            $this->access->authorize($actor, self::MODULE, $capability, $list);

            return;
        } catch (AuthorizationException $exception) {
            if ($list->isPrivate() || $capability === ModuleCapability::Manage) {
                throw $exception;
            }
        }

        $this->authorizeSharedManagement($actor);
    }

    private function authorizeSharedManagement(User $actor): void
    {
        HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
        $admin = User::query()->whereKey($actor->id)
            ->where('is_admin', true)->where('is_active', true)->lockForUpdate()->first();

        if (! $admin instanceof User) {
            throw new AuthorizationException('Only an active household administrator with Shopping manage access can manage shared lists.');
        }

        $this->access->authorize($admin, self::MODULE, ModuleCapability::Manage);
    }

    private function canUse(User $actor, ShoppingList $list, ModuleCapability $capability): bool
    {
        try {
            $this->authorizeListCapability($actor, $list, $capability);

            return true;
        } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
            return false;
        }
    }

    private function assertVersion(int $actual, int $expected, string $message): void
    {
        if ($actual !== $expected) {
            throw new ConflictHttpException($message);
        }
    }

    private function bumpList(ShoppingList $list): void
    {
        $list->version++;
        $list->save();
    }

    private function recordEvent(
        ShoppingList $list,
        ?ShoppingItem $item,
        User $actor,
        string $type,
        ?array $before,
        ?array $after,
    ): ShoppingListEvent {
        return $list->events()->create([
            'shopping_item_id' => $item?->id,
            'actor_user_id' => $actor->id,
            'event_type' => $type,
            'before_state' => $before,
            'after_state' => $after,
        ]);
    }

    /** @return Builder<ShoppingList> */
    private function withCounts(Builder $query): Builder
    {
        return $query->withCount('items')
            ->withCount(['items as open_items_count' => fn (Builder $items): Builder => $items->where('is_checked', false)]);
    }

    /** @return array<string, mixed> */
    private function presentList(User $actor, ShoppingList $list, ?bool $canEdit = null): array
    {
        $canEdit ??= $this->canUse($actor, $list, ModuleCapability::Edit);

        return [
            'id' => $list->id,
            'name' => $list->name,
            'visibility' => $list->isPrivate() ? 'private' : 'household',
            'is_private' => $list->isPrivate(),
            'version' => (int) $list->version,
            'item_count' => (int) ($list->items_count ?? $list->items()->count()),
            'open_item_count' => (int) ($list->open_items_count ?? $list->items()->where('is_checked', false)->count()),
            'can_edit' => $canEdit,
            'can_add_items' => $this->canUse($actor, $list, ModuleCapability::Create),
            'created_at' => $list->created_at?->toISOString(),
            'updated_at' => $list->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentItem(ShoppingItem $item, bool $canEdit): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'quantity' => $item->quantity === null ? null : (string) $item->quantity,
            'unit' => $item->unit,
            'sort_order' => (int) $item->sort_order,
            'is_checked' => (bool) $item->is_checked,
            'checked_at' => $item->checked_at?->toISOString(),
            'checked_by' => $item->checkedBy?->name,
            'version' => (int) $item->version,
            'can_edit' => $canEdit,
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function listSnapshot(ShoppingList $list): array
    {
        return [
            'name' => $list->name,
            'visibility' => $list->isPrivate() ? 'private' : 'household',
        ];
    }

    /** @return array<string, mixed> */
    private function itemSnapshot(ShoppingItem $item): array
    {
        return [
            'name' => $item->name,
            'quantity' => $item->quantity === null ? null : (string) $item->quantity,
            'unit' => $item->unit,
            'sort_order' => (int) $item->sort_order,
            'is_checked' => (bool) $item->is_checked,
        ];
    }
}
