<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'owner_user_id', 'created_by_user_id', 'is_private', 'title', 'category', 'instructions',
        'servings', 'preparation_minutes', 'photo_path',
    ];

    protected function casts(): array
    {
        return ['is_private' => 'boolean', 'servings' => 'integer', 'preparation_minutes' => 'integer'];
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('position');
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'recipe_favorites');
    }

    public function moduleKey(): string
    {
        return 'recipes';
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
