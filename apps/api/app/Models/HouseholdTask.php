<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $recurrence_anchor_date
 * @property-read User|null $assignee
 * @property-read User|null $createdBy
 * @property-read User|null $completedBy
 * @property-read Collection<int, HouseholdTaskCompletion> $completions
 */
class HouseholdTask extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'owner_user_id', 'created_by_user_id', 'assignee_user_id', 'is_private', 'title', 'description',
        'due_at', 'timezone', 'priority', 'recurrence_frequency', 'recurrence_interval', 'recurrence_anchor_date', 'is_completed',
        'completed_at', 'completed_by_user_id', 'occurrence_number', 'version',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'due_at' => 'immutable_datetime',
            'recurrence_interval' => 'integer',
            'recurrence_anchor_date' => 'immutable_date',
            'is_completed' => 'boolean',
            'completed_at' => 'immutable_datetime',
            'occurrence_number' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /** @return HasMany<HouseholdTaskCompletion, $this> */
    public function completions(): HasMany
    {
        return $this->hasMany(HouseholdTaskCompletion::class, 'task_id');
    }

    public function moduleKey(): string
    {
        return 'tasks';
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
