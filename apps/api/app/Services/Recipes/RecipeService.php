<?php

namespace App\Services\Recipes;

use App\Enums\ModuleCapability;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ResourceUserPermission;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class RecipeService
{
    private const MODULE = 'recipes';

    public function __construct(private readonly ModuleAccessService $access) {}

    /** @return list<array<string, mixed>> */
    public function index(User $actor): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $recipes = $this->visibleRecipes($actor, [ModuleCapability::View], Recipe::query())
            ->with('ingredients')
            ->orderBy('title')->orderBy('id')->limit(200)->get();
        $favoriteIds = $recipes->isEmpty() ? [] : DB::table('recipe_favorites')
            ->where('user_id', $actor->id)->whereIn('recipe_id', $recipes->modelKeys())
            ->pluck('recipe_id')->map(fn ($id): int => (int) $id)->all();

        return $recipes->map(fn (Recipe $recipe): array => $this->present($actor, $recipe, in_array($recipe->id, $favoriteIds, true)))->all();
    }

    /** @return array<string, mixed> */
    public function show(User $actor, int $recipeId): array
    {
        $recipe = $this->findVisible($actor, $recipeId, ModuleCapability::View)->load('ingredients');

        return $this->present($actor, $recipe, $recipe->favoritedBy()->whereKey($actor->id)->exists());
    }

    /** Resolve a linked recipe through the same module and resource checks as a recipe detail request. */
    public function findForMealPlan(User $actor, int $recipeId): Recipe
    {
        return $this->findVisible($actor, $recipeId, ModuleCapability::View);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(User $actor, array $data): array
    {
        $isPrivate = $data['visibility'] === 'private';
        $capability = $isPrivate ? ModuleCapability::Create : ModuleCapability::Manage;
        $this->access->authorize($actor, self::MODULE, $capability);
        $photoPath = $this->storePhoto($data['photo'] ?? null, $actor);

        try {
            $recipe = DB::transaction(function () use ($actor, $data, $isPrivate, $capability, $photoPath): Recipe {
                if ($isPrivate) {
                    $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);
                } else {
                    $this->authorizeSharedManagement($actor);
                }

                $recipe = Recipe::query()->create([
                    'owner_user_id' => $isPrivate ? $actor->id : null,
                    'created_by_user_id' => $actor->id,
                    'is_private' => $isPrivate,
                    'title' => trim($data['title']),
                    'category' => $this->nullableTrim($data['category'] ?? null),
                    'instructions' => trim($data['instructions']),
                    'servings' => (int) $data['servings'],
                    'preparation_minutes' => $data['preparation_minutes'] ?? null,
                    'photo_path' => $photoPath,
                ]);
                $this->access->authorize($actor, self::MODULE, $capability, $recipe);
                $this->replaceIngredients($recipe, $data['ingredients']);

                return $recipe->load('ingredients');
            }, 3);
        } catch (Throwable $exception) {
            if ($photoPath !== null) {
                Storage::disk('local')->delete($photoPath);
            }

            throw $exception;
        }

        return $this->present($actor, $recipe, false);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $actor, int $recipeId, array $data): array
    {
        $newPhotoPath = $this->storePhoto($data['photo'] ?? null, $actor);
        $oldPhotoPath = null;

        try {
            [$recipe, $oldPhotoPath] = DB::transaction(function () use ($actor, $recipeId, $data, $newPhotoPath): array {
                $recipe = $this->findForMutation($actor, $recipeId);
                $capability = $recipe->isPrivate() ? ModuleCapability::Edit : ModuleCapability::Manage;

                if ($capability === ModuleCapability::Manage) {
                    $this->authorizeSharedManagement($actor);
                }

                $this->access->authorize($actor, self::MODULE, $capability, $recipe);
                $oldPath = null;

                foreach (['title', 'instructions', 'servings', 'preparation_minutes'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $recipe->{$field} = in_array($field, ['title', 'instructions'], true) ? trim($data[$field]) : $data[$field];
                    }
                }

                if (array_key_exists('category', $data)) {
                    $recipe->category = $this->nullableTrim($data['category']);
                }

                if ($newPhotoPath !== null) {
                    $oldPath = $recipe->photo_path;
                    $recipe->photo_path = $newPhotoPath;
                } elseif (($data['remove_photo'] ?? false) === true || ($data['remove_photo'] ?? false) === '1') {
                    $oldPath = $recipe->photo_path;
                    $recipe->photo_path = null;
                }

                $recipe->save();

                if (array_key_exists('ingredients', $data)) {
                    $recipe->ingredients()->delete();
                    $this->replaceIngredients($recipe, $data['ingredients']);
                }

                return [$recipe->fresh()->load('ingredients'), $oldPath];
            }, 3);
        } catch (Throwable $exception) {
            if ($newPhotoPath !== null) {
                Storage::disk('local')->delete($newPhotoPath);
            }

            throw $exception;
        }

        if ($oldPhotoPath !== null && $oldPhotoPath !== $newPhotoPath) {
            Storage::disk('local')->delete($oldPhotoPath);
        }

        return $this->present($actor, $recipe, $recipe->favoritedBy()->whereKey($actor->id)->exists());
    }

    public function delete(User $actor, int $recipeId): void
    {
        $photoPath = DB::transaction(function () use ($actor, $recipeId): ?string {
            $recipe = $this->findForMutation($actor, $recipeId);
            $capability = $recipe->isPrivate() ? ModuleCapability::Edit : ModuleCapability::Manage;

            if ($capability === ModuleCapability::Manage) {
                $this->authorizeSharedManagement($actor);
            }

            $this->access->authorize($actor, self::MODULE, $capability, $recipe);
            ResourceUserPermission::query()
                ->where('resource_type', $recipe->getMorphClass())
                ->where('resource_id', $recipe->id)->delete();
            $path = $recipe->photo_path;
            $recipe->delete();

            return $path;
        }, 3);

        if ($photoPath !== null) {
            Storage::disk('local')->delete($photoPath);
        }
    }

    /** @return array<string, mixed> */
    public function favorite(User $actor, int $recipeId): array
    {
        $recipe = $this->findVisible($actor, $recipeId, ModuleCapability::View);
        DB::table('recipe_favorites')->insertOrIgnore(['recipe_id' => $recipe->id, 'user_id' => $actor->id]);

        return $this->show($actor, $recipeId);
    }

    public function unfavorite(User $actor, int $recipeId): void
    {
        $recipe = $this->findVisible($actor, $recipeId, ModuleCapability::View);
        DB::table('recipe_favorites')->where('recipe_id', $recipe->id)->where('user_id', $actor->id)->delete();
    }

    public function photo(User $actor, int $recipeId): string
    {
        $recipe = $this->findVisible($actor, $recipeId, ModuleCapability::View);
        abort_if($recipe->photo_path === null || ! Storage::disk('local')->exists($recipe->photo_path), 404);

        return Storage::disk('local')->path($recipe->photo_path);
    }

    private function findVisible(User $actor, int $recipeId, ModuleCapability $capability): Recipe
    {
        $this->access->authorize($actor, self::MODULE, $capability);
        $recipe = $this->visibleRecipes($actor, [$capability], Recipe::query())->whereKey($recipeId)->firstOrFail();
        $this->access->authorize($actor, self::MODULE, $capability, $recipe);

        return $recipe;
    }

    private function findForMutation(User $actor, int $recipeId): Recipe
    {
        $recipe = $this->visibleRecipes($actor, [ModuleCapability::Edit, ModuleCapability::Manage], Recipe::query())
            ->whereKey($recipeId)->lockForUpdate()->firstOrFail();

        return $recipe;
    }

    /** @param list<ModuleCapability> $capabilities */
    private function visibleRecipes(User $actor, array $capabilities, Builder $query): Builder
    {
        $morphType = (new Recipe)->getMorphClass();
        $values = array_map(fn (ModuleCapability $capability): string => $capability->value, $capabilities);

        return $query->where(function (Builder $visible) use ($actor, $morphType, $values): void {
            $visible->where('is_private', false)
                ->orWhere('owner_user_id', $actor->id)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $morphType, $values): void {
                    $permissions->selectRaw('1')->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', 'recipes.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->whereIn('resource_user_permissions.capability', $values);
                });
        });
    }

    private function authorizeSharedManagement(User $actor): void
    {
        $admin = User::query()->whereKey($actor->id)->where('is_admin', true)->where('is_active', true)->lockForUpdate()->first();

        if (! $admin instanceof User) {
            throw new AuthorizationException('Only an active household administrator can create or manage shared recipes.');
        }

        $this->access->authorize($admin, self::MODULE, ModuleCapability::Manage);
    }

    /** @param list<array{name: string, quantity: mixed, unit: string, note?: ?string}> $ingredients */
    private function replaceIngredients(Recipe $recipe, array $ingredients): void
    {
        $rows = [];

        foreach (array_values($ingredients) as $position => $ingredient) {
            $quantity = RecipeQuantity::normalize($ingredient['quantity'] ?? null);

            if ($quantity === null) {
                throw ValidationException::withMessages(["ingredients.{$position}.quantity" => 'Enter a positive recipe quantity.']);
            }

            $rows[] = [
                'position' => $position,
                'name' => trim($ingredient['name']),
                'quantity' => $quantity,
                'unit' => trim($ingredient['unit']),
                'note' => $this->nullableTrim($ingredient['note'] ?? null),
            ];
        }

        $recipe->ingredients()->createMany($rows);
    }

    private function storePhoto(mixed $photo, User $actor): ?string
    {
        if (! $photo instanceof UploadedFile) {
            return null;
        }

        $path = $photo->store('recipes/'.$actor->id, 'local');

        if (! is_string($path)) {
            throw new \RuntimeException('The private recipe photo could not be stored.');
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private function present(User $actor, Recipe $recipe, bool $isFavorite): array
    {
        if (! $recipe->relationLoaded('ingredients')) {
            $recipe->load('ingredients');
        }

        return [
            'id' => (int) $recipe->id,
            'title' => $recipe->title,
            'category' => $recipe->category,
            'instructions' => $recipe->instructions,
            'servings' => (int) $recipe->servings,
            'preparation_minutes' => $recipe->preparation_minutes === null ? null : (int) $recipe->preparation_minutes,
            'visibility' => $recipe->is_private ? 'private' : 'household',
            'can_edit' => $this->canEdit($actor, $recipe),
            'is_favorite' => $isFavorite,
            'photo_url' => $recipe->photo_path === null ? null : '/api/recipes/'.$recipe->id.'/photo',
            'ingredients' => $recipe->ingredients->map(fn (RecipeIngredient $ingredient): array => [
                'id' => (int) $ingredient->id,
                'position' => (int) $ingredient->position,
                'name' => $ingredient->name,
                'quantity' => (string) $ingredient->quantity,
                'unit' => $ingredient->unit,
                'note' => $ingredient->note,
            ])->all(),
            'updated_at' => $recipe->updated_at?->toISOString(),
        ];
    }

    private function canEdit(User $actor, Recipe $recipe): bool
    {
        if (! $recipe->isPrivate() && (! $actor->is_admin || ! $actor->is_active)) {
            return false;
        }

        $capability = $recipe->isPrivate() ? ModuleCapability::Edit : ModuleCapability::Manage;

        try {
            $this->access->authorize($actor, self::MODULE, $capability, $recipe);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
