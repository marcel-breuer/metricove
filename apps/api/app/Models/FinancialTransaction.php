<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTransaction extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'finance_category_id', 'finance_recurring_entry_id', 'owner_user_id', 'created_by_user_id',
        'is_private', 'type', 'description', 'amount', 'currency', 'occurred_on', 'recurring_occurrence_on',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'amount' => 'decimal:2',
            'occurred_on' => 'date:Y-m-d',
            'recurring_occurrence_on' => 'date:Y-m-d',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    public function recurringEntry(): BelongsTo
    {
        return $this->belongsTo(FinanceRecurringEntry::class, 'finance_recurring_entry_id');
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
