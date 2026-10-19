<?php

namespace App\Enums;

enum ModuleCapability: string
{
    case View = 'view';
    case Create = 'create';
    case Edit = 'edit';
    case Manage = 'manage';

    public function column(): string
    {
        return 'can_'.$this->value;
    }
}
