<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ModuleNotFound;
use App\Exceptions\ModuleStateConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMemberModulePermissionsRequest;
use App\Services\Modules\MemberModulePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMemberModulePermissionController extends Controller
{
    public function index(Request $request, int $member, MemberModulePermissionService $permissions): JsonResponse
    {
        $result = $permissions->forMember($request->user('web'), $member);

        return response()->json([
            'member' => ['id' => $result['id'], 'name' => $result['name']],
            'modules' => $result['modules'],
        ]);
    }

    public function update(
        UpdateMemberModulePermissionsRequest $request,
        int $member,
        string $moduleKey,
        MemberModulePermissionService $permissions,
    ): JsonResponse {
        try {
            $result = $permissions->updateForMember(
                $request->user('web'),
                $member,
                $moduleKey,
                $request->validated('capabilities'),
            );
        } catch (ModuleNotFound) {
            return response()->json(['message' => 'This module is not registered.'], 404);
        } catch (ModuleStateConflict $exception) {
            return response()->json([
                'message' => $exception->reason === 'core_permissions_managed_by_core'
                    ? 'Core access is managed by Core account roles.'
                    : 'Module permissions could not be changed.',
                'reason' => $exception->reason,
                'module_keys' => $exception->moduleKeys,
            ], 409);
        }

        return response()->json([
            'message' => 'Member module permissions updated.',
            'module' => $result,
        ]);
    }
}
