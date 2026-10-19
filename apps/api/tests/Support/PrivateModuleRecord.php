<?php

namespace Tests\Support;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;

class PrivateModuleRecord extends Model implements ModuleResource
{
    use HasResourcePermissions;

    public $timestamps = false;

    protected $table = 'authorization_test_records';

    protected $fillable = [
        'owner_user_id',
        'is_private',
        'value',
    ];

    protected function casts(): array
    {
        return ['is_private' => 'boolean'];
    }

    public function moduleKey(): string
    {
        return 'tracking';
    }

    public function ownerUserId(): ?int
    {
        return $this->owner_user_id === null ? null : (int) $this->owner_user_id;
    }

    public function isPrivate(): bool
    {
        return $this->is_private;
    }
}
