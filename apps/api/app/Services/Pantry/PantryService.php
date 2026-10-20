<?php

namespace App\Services\Pantry;

use App\Enums\ModuleCapability;
use App\Exceptions\ModuleDisabled;
use App\Exceptions\ModuleNotFound;
use App\Models\HouseholdSettings;
use App\Models\PantryItem;
use App\Models\PantryStockMovement;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PantryService
{
    private const MODULE = 'pantry';

    public function __construct(private readonly ModuleAccessService $access) {}

    /** @return list<array<string, mixed>> */
    public function index(User $actor, ?string $expiry = null): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $today = CarbonImmutable::today();
        $query = $this->visibleItems($actor, [ModuleCapability::View], PantryItem::query());

        match ($expiry) {
            'expired' => $query->whereDate('best_before_on', '<', $today->toDateString()),
            'today' => $query->whereDate('best_before_on', '=', $today->toDateString()),
            'soon' => $query->whereDate('best_before_on', '>=', $today->toDateString())
                ->whereDate('best_before_on', '<=', $today->addDays(7)->toDateString()),
            'later' => $query->whereDate('best_before_on', '>', $today->addDays(7)->toDateString()),
            'none' => $query->whereNull('best_before_on'),
            default => null,
        };

        return $query->orderByRaw('CASE WHEN best_before_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('best_before_on')->orderBy('name')->orderBy('id')->limit(200)->get()
            ->map(fn (PantryItem $item): array => $this->presentItem($actor, $item, $today))
            ->all();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(User $actor, array $data): array
    {
        $quantity = PantryQuantity::quantity($data['quantity'] ?? null)
            ?? throw ValidationException::withMessages(['quantity' => 'Enter a non-negative quantity with up to 11 whole and 3 fractional digits.']);
        $private = $data['visibility'] === 'private';
        $capability = $private ? ModuleCapability::Create : ModuleCapability::Manage;

        $item = DB::transaction(function () use ($actor, $data, $quantity, $private, $capability): PantryItem {
            if ($private) {
                $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);
            } else {
                $this->authorizeSharedManagement($actor);
            }

            $item = PantryItem::query()->create([
                'owner_user_id' => $private ? $actor->id : null,
                'created_by_user_id' => $actor->id,
                'is_private' => $private,
                'name' => trim($data['name']),
                'quantity' => $quantity,
                'unit' => trim($data['unit']),
                'location' => $this->nullableTrim($data['location'] ?? null),
                'best_before_on' => $data['best_before_on'] ?? null,
                'version' => 1,
            ]);

            if ($quantity !== '0.000') {
                $this->recordMovement($item, $actor, 'initial', '0.000', $quantity, $quantity, $data['unit'], null);
            }

            $this->access->authorize($actor, self::MODULE, $capability, $item);

            return $item;
        }, 3);

        return $this->presentItem($actor, $item, CarbonImmutable::today());
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $actor, int $itemId, array $data): array
    {
        $item = DB::transaction(function () use ($actor, $itemId, $data): PantryItem {
            $item = $this->findForMutation($actor, $itemId);
            $this->authorizeMutation($actor, $item);
            $this->assertVersion($item, (int) $data['expected_version']);

            foreach (['name', 'location', 'best_before_on'] as $field) {
                if (array_key_exists($field, $data)) {
                    $item->{$field} = $field === 'name' ? trim($data[$field]) : $data[$field];
                }
            }

            $item->version++;
            $item->save();

            return $item->fresh();
        }, 3);

        return $this->presentItem($actor, $item, CarbonImmutable::today());
    }

    /** @param array<string, mixed> $data
     * @return array{item: array<string, mixed>, movement: array<string, mixed>}
     */
    public function adjust(User $actor, int $itemId, array $data): array
    {
        $delta = PantryQuantity::delta($data['quantity_delta'] ?? null)
            ?? throw ValidationException::withMessages(['quantity_delta' => 'Enter a non-zero adjustment with up to 11 whole and 3 fractional digits.']);

        [$item, $movement] = DB::transaction(function () use ($actor, $itemId, $data, $delta): array {
            $item = $this->findForMutation($actor, $itemId);
            $this->authorizeMutation($actor, $item);
            $this->assertVersion($item, (int) $data['expected_version']);

            $before = (string) $item->quantity;
            $after = PantryQuantity::add($before, $delta);

            if ($after === null) {
                throw ValidationException::withMessages(['quantity_delta' => 'This adjustment would make stock negative or exceed the supported quantity.']);
            }

            $item->quantity = $after;
            $item->version++;
            $item->save();

            $movement = $this->recordMovement(
                $item,
                $actor,
                'adjustment',
                $before,
                $delta,
                $after,
                $item->unit,
                $this->nullableTrim($data['note'] ?? null),
            );

            return [$item->fresh(), $movement];
        }, 3);

        return [
            'item' => $this->presentItem($actor, $item, CarbonImmutable::today()),
            'movement' => $this->presentMovement($movement),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function movements(User $actor, int $itemId): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $item = $this->visibleItems($actor, [ModuleCapability::View], PantryItem::query())
            ->whereKey($itemId)->firstOrFail();

        return $item->movements()->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get()
            ->map(fn (PantryStockMovement $movement): array => $this->presentMovement($movement))
            ->all();
    }

    private function findForMutation(User $actor, int $itemId): PantryItem
    {
        return $this->visibleItems($actor, [ModuleCapability::Edit, ModuleCapability::Manage], PantryItem::query())
            ->whereKey($itemId)->lockForUpdate()->firstOrFail();
    }

    /** @param list<ModuleCapability> $capabilities */
    private function visibleItems(User $actor, array $capabilities, Builder $query): Builder
    {
        $morphType = (new PantryItem)->getMorphClass();
        $values = array_map(fn (ModuleCapability $capability): string => $capability->value, $capabilities);

        return $query->where(function (Builder $visible) use ($actor, $morphType, $values): void {
            $visible->where('is_private', false)
                ->orWhere('owner_user_id', $actor->id)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $morphType, $values): void {
                    $permissions->selectRaw('1')->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', 'pantry_items.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->whereIn('resource_user_permissions.capability', $values);
                });
        });
    }

    private function authorizeMutation(User $actor, PantryItem $item): void
    {
        if (! $item->isPrivate()) {
            $this->authorizeSharedManagement($actor);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Manage, $item);

            return;
        }

        $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $item);
    }

    private function authorizeSharedManagement(User $actor): void
    {
        HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
        $admin = User::query()->whereKey($actor->id)
            ->where('is_admin', true)->where('is_active', true)->lockForUpdate()->first();

        if (! $admin instanceof User) {
            throw new AuthorizationException('Only an active household administrator can manage shared pantry stock.');
        }

        $this->access->authorize($admin, self::MODULE, ModuleCapability::Manage);
    }

    private function assertVersion(PantryItem $item, int $expectedVersion): void
    {
        if ($item->version !== $expectedVersion) {
            throw new ConflictHttpException('This pantry item changed since it was loaded. Refresh it and retry.');
        }
    }

    private function recordMovement(
        PantryItem $item,
        User $actor,
        string $type,
        string $before,
        string $delta,
        string $after,
        string $unit,
        ?string $note,
    ): PantryStockMovement {
        return $item->movements()->create([
            'actor_user_id' => $actor->id,
            'movement_type' => $type,
            'quantity_before' => $before,
            'quantity_delta' => $delta,
            'quantity_after' => $after,
            'unit' => trim($unit),
            'note' => $note,
        ]);
    }

    /** @return array<string, mixed> */
    private function presentItem(User $actor, PantryItem $item, CarbonImmutable $today): array
    {
        $bestBefore = $item->best_before_on;
        $daysUntil = null;
        $expiryStatus = 'none';

        if ($bestBefore !== null) {
            $expiry = CarbonImmutable::parse($bestBefore->format('Y-m-d'));
            $daysUntil = (int) ($expiry->lessThan($today)
                ? -$expiry->diffInDays($today)
                : $today->diffInDays($expiry));
            $expiryStatus = match (true) {
                $daysUntil < 0 => 'expired',
                $daysUntil === 0 => 'today',
                $daysUntil <= 7 => 'soon',
                default => 'later',
            };
        }

        return [
            'id' => $item->id,
            'name' => $item->name,
            'quantity' => (string) $item->quantity,
            'unit' => $item->unit,
            'location' => $item->location,
            'best_before_on' => $bestBefore?->format('Y-m-d'),
            'expiry_status' => $expiryStatus,
            'days_until_best_before' => $daysUntil,
            'visibility' => $item->isPrivate() ? 'private' : 'household',
            'is_private' => $item->isPrivate(),
            'version' => (int) $item->version,
            'can_edit' => $this->canEdit($actor, $item),
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentMovement(PantryStockMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'type' => $movement->movement_type,
            'quantity_before' => (string) $movement->quantity_before,
            'quantity_delta' => (string) $movement->quantity_delta,
            'quantity_after' => (string) $movement->quantity_after,
            'unit' => $movement->unit,
            'note' => $movement->note,
            'created_at' => $movement->created_at?->toISOString(),
        ];
    }

    private function canEdit(User $actor, PantryItem $item): bool
    {
        try {
            if ($item->isPrivate()) {
                $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $item);

                return true;
            }

            $this->access->authorize($actor, self::MODULE, ModuleCapability::Manage);

            return User::query()->whereKey($actor->id)->where('is_admin', true)->where('is_active', true)->exists();
        } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
            return false;
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
