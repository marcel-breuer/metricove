<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use App\Services\Modules\ModuleStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModuleDiscoveryController extends Controller
{
    public function __invoke(Request $request, ModuleStateService $states, ModuleRegistry $registry): JsonResponse
    {
        $actor = $request->user();

        abort_unless($actor instanceof User && $actor->is_active, 401);

        $enabledKeys = $states->enabledKeys();
        $optionalEnabledKeys = array_values(array_filter($enabledKeys, fn (string $key): bool => $key !== 'core'));
        $permissions = ModuleUserPermission::query()
            ->where('user_id', $actor->id)
            ->whereIn('module_key', $optionalEnabledKeys)
            ->where(function ($query): void {
                $query->where('can_view', true)
                    ->orWhere('can_create', true)
                    ->orWhere('can_edit', true)
                    ->orWhere('can_manage', true);
            })
            ->get()
            ->keyBy('module_key');

        $availableModules = [];

        foreach ($registry->all() as $module) {
            $permission = $permissions->get($module->key);

            if ($module->alwaysEnabled || $permission === null) {
                continue;
            }

            $availableModules[] = [
                'key' => $module->key,
                'label' => $module->label,
                'description' => $module->description,
                'capabilities' => $permission->capabilities(),
            ];
        }

        return response()->json([
            'enabled_modules' => $enabledKeys,
            'available_modules' => $availableModules,
        ]);
    }
}
