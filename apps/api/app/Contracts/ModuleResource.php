<?php

namespace App\Contracts;

/**
 * Explicit ownership metadata required before a module can authorize a record.
 * Private resources must have an owner; shared resources can use a null owner.
 */
interface ModuleResource
{
    public function moduleKey(): string;

    public function ownerUserId(): ?int;

    public function isPrivate(): bool;
}
