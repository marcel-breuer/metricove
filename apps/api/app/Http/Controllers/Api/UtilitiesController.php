<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectUtilityReadingRequest;
use App\Http\Requests\StoreUtilityMeterRequest;
use App\Http\Requests\StoreUtilityReadingRequest;
use App\Http\Requests\UpdateUtilityMeterRequest;
use App\Models\User;
use App\Services\Utilities\UtilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UtilitiesController extends Controller
{
    public function index(Request $request, UtilityService $utilities): JsonResponse
    {
        return response()->json(['meters' => $utilities->index($this->actor($request))]);
    }

    public function store(StoreUtilityMeterRequest $request, UtilityService $utilities): JsonResponse
    {
        return response()->json([
            'message' => 'Meter created.',
            'meter' => $utilities->createMeter($this->actor($request), $request->validated()),
        ], 201);
    }

    public function show(Request $request, int $meter, UtilityService $utilities): JsonResponse
    {
        return response()->json($utilities->show($this->actor($request), $meter));
    }

    public function update(
        UpdateUtilityMeterRequest $request,
        int $meter,
        UtilityService $utilities,
    ): JsonResponse {
        return response()->json([
            'message' => 'Meter updated.',
            'meter' => $utilities->updateMeter($this->actor($request), $meter, $request->validated()),
        ]);
    }

    public function storeReading(
        StoreUtilityReadingRequest $request,
        int $meter,
        UtilityService $utilities,
    ): JsonResponse {
        return response()->json([
            'message' => 'Reading saved.',
            'reading' => $utilities->createReading($this->actor($request), $meter, $request->validated()),
        ], 201);
    }

    public function correctReading(
        CorrectUtilityReadingRequest $request,
        int $reading,
        UtilityService $utilities,
    ): JsonResponse {
        return response()->json([
            'message' => 'Reading corrected.',
            'reading' => $utilities->correctReading($this->actor($request), $reading, $request->validated()),
        ]);
    }

    public function revisions(Request $request, int $reading, UtilityService $utilities): JsonResponse
    {
        return response()->json(['revisions' => $utilities->revisions($this->actor($request), $reading)]);
    }

    public function photo(Request $request, int $reading, UtilityService $utilities): BinaryFileResponse
    {
        $response = response()->file($utilities->photo($this->actor($request), $reading), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');

        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
