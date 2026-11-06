<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PantryStockMovement extends Model
{
    protected $fillable = [
        'pantry_item_id', 'actor_user_id', 'movement_type', 'quantity_before',
        'quantity_delta', 'quantity_after', 'unit', 'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity_before' => 'decimal:3',
            'quantity_delta' => 'decimal:3',
            'quantity_after' => 'decimal:3',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PantryItem::class, 'pantry_item_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
