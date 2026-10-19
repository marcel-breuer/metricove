<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Modules\ModuleStateService;
use Illuminate\Http\JsonResponse;

class ModuleDiscoveryController extends Controller
{
    public function __invoke(ModuleStateService $modules): JsonResponse
    {
        return response()->json(['enabled_modules' => $modules->enabledKeys()]);
    }
}
