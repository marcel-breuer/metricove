<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeIngredient extends Model
{
    public $timestamps = false;

    protected $fillable = ['position', 'name', 'quantity', 'unit', 'note'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'quantity' => 'decimal:4'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
