<?php

namespace App\Services\AI\Tools;

use App\Contracts\AssistantTool;
use App\Exceptions\InvalidAssistantToolCall;
use App\Models\User;
use App\Services\Vehicles\VehicleService;

class VehiclesSnapshotTool implements AssistantTool
{
    public function __construct(private readonly VehicleService $vehicles) {}

    public function name(): string
    {
        return 'summarize_visible_vehicle_odometer_readings';
    }

    public function moduleKey(): string
    {
        return 'vehicles';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Summarize up to 8 visible vehicles and their 5 most recent visible odometer readings. Notes, costs, insurance and maintenance details are excluded.',
                'strict' => true,
                'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => [], 'additionalProperties' => false],
            ],
        ];
    }

    /**
     * @param  array{}  $arguments
     * @return array{vehicles: list<array{name: string, unit: string, readings: list<array{date: string, value: string, distance_since_previous: ?string, unit: string}>}>, truncated: bool}
     */
    public function execute(User $actor, array $arguments): array
    {
        if ($arguments !== []) {
            throw new InvalidAssistantToolCall('The vehicles tool does not accept arguments.');
        }

        $allVehicles = $this->vehicles->index($actor);
        $vehicles = [];

        foreach (array_slice($allVehicles, 0, 8) as $vehicle) {
            $detail = $this->vehicles->show($actor, (int) $vehicle['id']);

            $vehicles[] = [
                'name' => $vehicle['name'],
                'unit' => $vehicle['odometer_unit'],
                'readings' => array_map(fn (array $reading): array => [
                    'date' => $reading['reading_date'],
                    'value' => $reading['value'],
                    'distance_since_previous' => $reading['distance_since_previous'],
                    'unit' => $reading['unit'],
                ], array_slice($detail['odometer_readings'], -5)),
            ];
        }

        return ['vehicles' => $vehicles, 'truncated' => count($allVehicles) > count($vehicles)];
    }
}
