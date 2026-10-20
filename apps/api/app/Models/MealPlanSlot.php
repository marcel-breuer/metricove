<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPlanSlot extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'owner_user_id', 'created_by_user_id', 'recipe_id', 'is_private', 'scope_key',
        'planned_for', 'meal_type', 'dish_type', 'dish_name', 'servings', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'planned_for' => 'date:Y-m-d',
            'servings' => 'integer',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function moduleKey(): string
    {
        return 'meal_planning';
    }

    public function ownerUserId(): ?int
    {
        return $this->owner_user_id === null ? null : (int) $this->owner_user_id;
    }

    public function isPrivate(): bool
    {
        return (bool) $this->is_private;
    }
}
