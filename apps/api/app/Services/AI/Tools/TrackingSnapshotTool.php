<?php

namespace App\Services\AI\Tools;

use App\Contracts\AssistantTool;
use App\Exceptions\InvalidAssistantToolCall;
use App\Models\User;
use App\Services\Tracking\TrackerEntryService;
use App\Services\Tracking\TrackerSchemaService;

class TrackingSnapshotTool implements AssistantTool
{
    public function __construct(
        private readonly TrackerSchemaService $schemas,
        private readonly TrackerEntryService $entries,
    ) {}

    public function name(): string
    {
        return 'summarize_visible_tracker_entries';
    }

    public function moduleKey(): string
    {
        return 'tracking';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Summarize up to 8 visible trackers and 5 recent entries per tracker. Free-text notes and text field values are excluded.',
                'strict' => true,
                'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => [], 'additionalProperties' => false],
            ],
        ];
    }

    /**
     * @param  array{}  $arguments
     * @return array{trackers: list<array{name: string, fields: list<array{label: string, type: string, unit: ?string}>, fields_truncated: bool, entries: list<array{recorded_at: ?string, values: list<array{label: string, type: string, unit: ?string, value: mixed}>, values_truncated: bool}>}>, truncated: bool}
     */
    public function execute(User $actor, array $arguments): array
    {
        if ($arguments !== []) {
            throw new InvalidAssistantToolCall('The tracking tool does not accept arguments.');
        }

        $allTrackers = $this->schemas->index($actor);
        $trackers = [];

        foreach (array_slice($allTrackers, 0, 8) as $tracker) {
            $entries = $this->entries->history($actor, (int) $tracker['id'], limit: 5)['entries'];
            $fields = array_slice($tracker['fields'], 0, 12);
            $trackers[] = [
                'name' => $tracker['name'],
                'fields' => array_map(fn (array $field): array => [
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'unit' => $field['unit'],
                ], $fields),
                'fields_truncated' => count($tracker['fields']) > count($fields),
                'entries' => array_map(function (array $entry): array {
                    $values = array_values(array_filter(
                        array_map(fn (array $value): ?array => in_array($value['type'], ['number', 'date', 'boolean'], true)
                            ? ['label' => $value['label'], 'type' => $value['type'], 'unit' => $value['unit'], 'value' => $value['value']]
                            : null, $entry['values']),
                    ));

                    return [
                        'recorded_at' => $entry['recorded_at'],
                        'values' => array_slice($values, 0, 12),
                        'values_truncated' => count($values) > 12,
                    ];
                }, $entries),
            ];
        }

        return ['trackers' => $trackers, 'truncated' => count($allTrackers) > count($trackers)];
    }
}
