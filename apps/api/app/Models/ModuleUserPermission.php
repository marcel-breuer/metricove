<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModuleUserPermission extends Model
{
    protected $fillable = [
        'user_id',
        'module_key',
        'can_view',
        'can_create',
        'can_edit',
        'can_manage',
    ];

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_create' => 'boolean',
            'can_edit' => 'boolean',
            'can_manage' => 'boolean',
        ];
    }

    /** @return array{view: bool, create: bool, edit: bool, manage: bool} */
    public function capabilities(): array
    {
        return [
            'view' => $this->can_view,
            'create' => $this->can_create,
            'edit' => $this->can_edit,
            'manage' => $this->can_manage,
        ];
    }
}
