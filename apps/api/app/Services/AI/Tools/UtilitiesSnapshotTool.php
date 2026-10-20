<?php

namespace App\Services\AI\Tools;

use App\Contracts\AssistantTool;
use App\Exceptions\InvalidAssistantToolCall;
use App\Models\User;
use App\Services\Utilities\UtilityService;

class UtilitiesSnapshotTool implements AssistantTool
{
    public function __construct(private readonly UtilityService $utilities) {}

    public function name(): string
    {
        return 'summarize_visible_utility_readings';
    }

    public function moduleKey(): string
    {
        return 'utilities';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Summarize up to 8 visible utility meters and their 5 most recent visible readings. Notes, photos and thresholds are excluded.',
                'strict' => true,
                'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => [], 'additionalProperties' => false],
            ],
        ];
    }

    /**
     * @param  array{}  $arguments
     * @return array{meters: list<array{name: string, type: string, unit: string, readings: list<array{date: string, value: string, consumption_since_previous: ?string, unit: string}>}>, truncated: bool}
     */
    public function execute(User $actor, array $arguments): array
    {
        if ($arguments !== []) {
            throw new InvalidAssistantToolCall('The utilities tool does not accept arguments.');
        }

        $allMeters = $this->utilities->index($actor);
        $meters = [];

        foreach (array_slice($allMeters, 0, 8) as $meter) {
            $detail = $this->utilities->show($actor, (int) $meter['id']);
            $readings = array_slice($detail['readings'], -5);

            $meters[] = [
                'name' => $meter['name'],
                'type' => $meter['meter_type'],
                'unit' => $meter['unit'],
                'readings' => array_map(fn (array $reading): array => [
                    'date' => $reading['reading_date'],
                    'value' => $reading['value'],
                    'consumption_since_previous' => $reading['consumption_since_previous'],
                    'unit' => $reading['unit'],
                ], $readings),
            ];
        }

        return ['meters' => $meters, 'truncated' => count($allMeters) > count($meters)];
    }
}
