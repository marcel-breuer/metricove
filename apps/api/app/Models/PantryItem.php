<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PantryItem extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'owner_user_id', 'created_by_user_id', 'is_private', 'name', 'quantity', 'unit',
        'location', 'best_before_on', 'version',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'quantity' => 'decimal:3',
            'best_before_on' => 'date:Y-m-d',
            'version' => 'integer',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(PantryStockMovement::class);
    }

    public function moduleKey(): string
    {
        return 'pantry';
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
