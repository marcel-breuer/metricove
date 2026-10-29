<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property string|null $due_time */
class HouseholdTaskChecklistItem extends Model
{
    protected $fillable = ['task_id', 'title', 'position', 'due_offset_days', 'due_time', 'reminder_lead_minutes'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'due_offset_days' => 'integer',
            'reminder_lead_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<HouseholdTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(HouseholdTask::class, 'task_id');
    }

    /** @return HasMany<HouseholdTaskChecklistOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(HouseholdTaskChecklistOccurrence::class, 'checklist_item_id');
    }

    /** @return HasOne<HouseholdTaskChecklistOccurrence, $this> */
    public function currentOccurrence(): HasOne
    {
        return $this->hasOne(HouseholdTaskChecklistOccurrence::class, 'checklist_item_id')->latestOfMany();
    }
}
