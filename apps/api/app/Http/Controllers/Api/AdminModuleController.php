<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ModuleNotFound;
use App\Exceptions\ModuleStateConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateModuleRequest;
use App\Services\Modules\ModuleManagementService;
use App\Services\Modules\ModuleStateService;
use Illuminate\Http\JsonResponse;

class AdminModuleController extends Controller
{
    public function index(ModuleStateService $modules): JsonResponse
    {
        return response()->json(['modules' => $modules->catalog()]);
    }

    public function update(
        UpdateModuleRequest $request,
        string $moduleKey,
        ModuleManagementService $modules,
    ): JsonResponse {
        try {
            $updated = $modules->setEnabled(
                $request->user('web'),
                $moduleKey,
                $request->boolean('enabled'),
            );
        } catch (ModuleNotFound) {
            return response()->json(['message' => 'This module is not registered.'], 404);
        } catch (ModuleStateConflict $exception) {
            $message = match ($exception->reason) {
                'core_always_enabled' => 'Core is always enabled and cannot be disabled.',
                'dependencies_disabled' => 'Enable the required modules before enabling this module.',
                'dependents_enabled' => 'Disable dependent modules before disabling this module.',
                default => 'The module state could not be changed.',
            };

            return response()->json([
                'message' => $message,
                'reason' => $exception->reason,
                'module_keys' => $exception->moduleKeys,
            ], $exception->reason === 'dependencies_disabled' ? 422 : 409);
        }

        return response()->json([
            'message' => $updated['enabled'] ? 'Module enabled.' : 'Module disabled. Existing data is preserved.',
            'module' => $updated,
        ]);
    }
}
