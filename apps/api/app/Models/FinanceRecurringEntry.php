<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceRecurringEntry extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'finance_category_id', 'owner_user_id', 'created_by_user_id', 'is_private', 'type', 'description',
        'amount', 'currency', 'frequency', 'starts_on', 'next_due_on', 'ends_on', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'is_active' => 'boolean',
            'amount' => 'decimal:2',
            'starts_on' => 'date:Y-m-d',
            'next_due_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class, 'finance_recurring_entry_id');
    }

    public function moduleKey(): string
    {
        return 'finance';
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
