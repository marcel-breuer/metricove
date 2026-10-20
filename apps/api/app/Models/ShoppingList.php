<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShoppingList extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'owner_user_id', 'created_by_user_id', 'is_private', 'name', 'version',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShoppingItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShoppingListEvent::class);
    }

    public function moduleKey(): string
    {
        return 'shopping';
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
