<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $completed_at
 * @property-read User|null $completedBy
 */
class HouseholdTaskCompletion extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_id', 'occurrence_number', 'completed_by_user_id', 'completed_at'];

    protected function casts(): array
    {
        return ['occurrence_number' => 'integer', 'completed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<HouseholdTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(HouseholdTask::class, 'task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
