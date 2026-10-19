<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectVehicleOdometerReadingRequest;
use App\Http\Requests\StoreVehicleCostRequest;
use App\Http\Requests\StoreVehicleInsuranceRequest;
use App\Http\Requests\StoreVehicleMaintenanceRequest;
use App\Http\Requests\StoreVehicleOdometerReadingRequest;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Models\User;
use App\Services\Vehicles\VehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehiclesController extends Controller
{
    public function index(Request $request, VehicleService $vehicles): JsonResponse
    {
        return response()->json(['vehicles' => $vehicles->index($this->actor($request))]);
    }

    public function store(StoreVehicleRequest $request, VehicleService $vehicles): JsonResponse
    {
        return response()->json([
            'message' => 'Vehicle created.',
            'vehicle' => $vehicles->create($this->actor($request), $request->validated()),
        ], 201);
    }

    public function show(Request $request, int $vehicle, VehicleService $vehicles): JsonResponse
    {
        return response()->json($vehicles->show($this->actor($request), $vehicle));
    }

    public function update(UpdateVehicleRequest $request, int $vehicle, VehicleService $vehicles): JsonResponse
    {
        return response()->json([
            'message' => 'Vehicle updated.',
            'vehicle' => $vehicles->update($this->actor($request), $vehicle, $request->validated()),
        ]);
    }

    public function storeOdometerReading(
        StoreVehicleOdometerReadingRequest $request,
        int $vehicle,
        VehicleService $vehicles,
    ): JsonResponse {
        return response()->json([
            'message' => 'Odometer reading saved.',
            'reading' => $vehicles->addOdometerReading($this->actor($request), $vehicle, $request->validated()),
        ], 201);
    }

    public function correctOdometerReading(
        CorrectVehicleOdometerReadingRequest $request,
        int $reading,
        VehicleService $vehicles,
    ): JsonResponse {
        return response()->json([
            'message' => 'Odometer reading corrected.',
            'reading' => $vehicles->correctOdometerReading($this->actor($request), $reading, $request->validated()),
        ]);
    }

    public function odometerRevisions(Request $request, int $reading, VehicleService $vehicles): JsonResponse
    {
        return response()->json(['revisions' => $vehicles->odometerRevisions($this->actor($request), $reading)]);
    }

    public function storeMaintenance(
        StoreVehicleMaintenanceRequest $request,
        int $vehicle,
        VehicleService $vehicles,
    ): JsonResponse {
        return response()->json([
            'message' => 'Maintenance record saved.',
            'maintenance_record' => $vehicles->addMaintenance($this->actor($request), $vehicle, $request->validated()),
        ], 201);
    }

    public function storeInsurance(
        StoreVehicleInsuranceRequest $request,
        int $vehicle,
        VehicleService $vehicles,
    ): JsonResponse {
        return response()->json([
            'message' => 'Insurance policy saved.',
            'insurance_policy' => $vehicles->addInsurance($this->actor($request), $vehicle, $request->validated()),
        ], 201);
    }

    public function storeCost(
        StoreVehicleCostRequest $request,
        int $vehicle,
        VehicleService $vehicles,
    ): JsonResponse {
        return response()->json([
            'message' => 'Vehicle cost saved.',
            'cost' => $vehicles->addCost($this->actor($request), $vehicle, $request->validated()),
        ], 201);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');

        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
