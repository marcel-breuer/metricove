<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $completed_at
 */
class HouseholdTaskChecklistOccurrence extends Model
{
    protected $fillable = [
        'task_id', 'checklist_item_id', 'task_occurrence_number', 'title_snapshot', 'due_at',
        'completed_at', 'completed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'task_occurrence_number' => 'integer',
            'due_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<HouseholdTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(HouseholdTask::class, 'task_id');
    }

    /** @return BelongsTo<HouseholdTaskChecklistItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(HouseholdTaskChecklistItem::class, 'checklist_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
