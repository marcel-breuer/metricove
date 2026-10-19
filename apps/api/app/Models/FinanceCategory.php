<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceCategory extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = ['owner_user_id', 'created_by_user_id', 'is_private', 'type', 'name'];

    protected function casts(): array
    {
        return ['is_private' => 'boolean'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class);
    }

    public function recurringEntries(): HasMany
    {
        return $this->hasMany(FinanceRecurringEntry::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(FinanceBudget::class);
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
