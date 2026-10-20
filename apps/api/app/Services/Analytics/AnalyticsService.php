<?php

namespace App\Services\Analytics;

use App\Enums\ModuleCapability;
use App\Exceptions\ModuleDisabled;
use App\Exceptions\ModuleNotFound;
use App\Models\AnalyticsWidget;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityReading;
use App\Models\Vehicle;
use App\Models\VehicleOdometerReading;
use App\Services\Modules\ModuleAccessService;
use App\Services\Modules\ModuleStateService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnalyticsService
{
    private const MAX_WIDGETS = 12;

    /** @var array<string, array{label: string, description: string, source_module: string, source_label: string, scale: int}> */
    private const METRICS = [
        'finance.expenses' => [
            'label' => 'Expenses by currency',
            'description' => 'Recorded expenses over time, kept separate by currency.',
            'source_module' => 'finance',
            'source_label' => 'Finance',
            'scale' => 2,
        ],
        'utilities.consumption' => [
            'label' => 'Utility consumption',
            'description' => 'Consumption between consecutive visible meter readings.',
            'source_module' => 'utilities',
            'source_label' => 'Utilities',
            'scale' => 6,
        ],
        'vehicles.distance' => [
            'label' => 'Distance travelled',
            'description' => 'Distance between consecutive visible odometer readings.',
            'source_module' => 'vehicles',
            'source_label' => 'Vehicles',
            'scale' => 3,
        ],
    ];

    public function __construct(
        private readonly ModuleAccessService $access,
        private readonly ModuleStateService $states,
    ) {}

    /** @return array<string, array{label: string, description: string, source_module: string, source_label: string, scale: int}> */
    public static function metrics(): array
    {
        return self::METRICS;
    }

    /** @return list<array{key: string, label: string, description: string, source_module: string, source_label: string}> */
    public function catalog(User $actor): array
    {
        $this->access->authorize($actor, 'analytics', ModuleCapability::View);
        $catalog = [];

        foreach (self::METRICS as $key => $metric) {
            if (! $this->canViewSource($actor, $metric['source_module'])) {
                continue;
            }

            $catalog[] = [
                'key' => $key,
                'label' => $metric['label'],
                'description' => $metric['description'],
                'source_module' => $metric['source_module'],
                'source_label' => $metric['source_label'],
            ];
        }

        return $catalog;
    }

    /** @return array{catalog: list<array<string, string>>, widgets: list<array<string, mixed>>} */
    public function dashboard(User $actor): array
    {
        $this->access->authorize($actor, 'analytics', ModuleCapability::View);
        $catalog = $this->catalog($actor);
        $available = array_fill_keys(array_column($catalog, 'key'), true);
        $widgets = AnalyticsWidget::query()
            ->where('user_id', $actor->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $rawMetrics = [];
        $result = [];

        foreach ($widgets as $widget) {
            $isAvailable = isset($available[$widget->metric_key]);

            if ($isAvailable && ! array_key_exists($widget->metric_key, $rawMetrics)) {
                $rawMetrics[$widget->metric_key] = $this->aggregate($actor, $widget->metric_key);
            }

            $result[] = [
                ...$this->presentWidget($widget),
                'available' => $isAvailable,
                'periods' => $isAvailable ? $this->periods($widget->range_key) : [],
                'series' => $isAvailable ? $this->renderSeries($rawMetrics[$widget->metric_key], $widget->range_key, self::METRICS[$widget->metric_key]['scale']) : [],
            ];
        }

        return ['catalog' => $catalog, 'widgets' => $result];
    }

    /** @param array{metric_key: string, title?: string|null, range_key: string, chart_type: string} $data
     * @return array<string, mixed>
     */
    public function createWidget(User $actor, array $data): array
    {
        $this->access->authorize($actor, 'analytics', ModuleCapability::Create);
        $metric = self::METRICS[$data['metric_key']];

        if (! $this->canViewSource($actor, $metric['source_module'])) {
            throw new AuthorizationException('The source module is unavailable to this member.');
        }

        $widget = DB::transaction(function () use ($actor, $data, $metric): AnalyticsWidget {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $widgets = AnalyticsWidget::query()->where('user_id', $actor->id);

            if ($widgets->count() >= self::MAX_WIDGETS) {
                throw ValidationException::withMessages(['widgets' => 'A dashboard can contain up to 12 widgets.']);
            }

            return AnalyticsWidget::query()->create([
                'user_id' => $actor->id,
                'metric_key' => $data['metric_key'],
                'title' => trim((string) ($data['title'] ?? '')) ?: $metric['label'],
                'range_key' => $data['range_key'],
                'chart_type' => $data['chart_type'],
                'position' => ((int) $widgets->max('position')) + 1,
                'version' => 1,
            ]);
        }, 3);

        return $this->presentWidget($widget);
    }

    /** @param array{expected_version: int, title: string, range_key: string, chart_type: string} $data
     * @return array<string, mixed>
     */
    public function updateWidget(User $actor, int $widgetId, array $data): array
    {
        $this->access->authorize($actor, 'analytics', ModuleCapability::Edit);

        return DB::transaction(function () use ($actor, $widgetId, $data): array {
            $widget = AnalyticsWidget::query()
                ->where('user_id', $actor->id)
                ->whereKey($widgetId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($widget->version !== (int) $data['expected_version']) {
                throw new HttpResponseException(response()->json(['message' => 'This widget changed elsewhere. Reload the dashboard and try again.'], 409));
            }

            $widget->forceFill([
                'title' => trim($data['title']),
                'range_key' => $data['range_key'],
                'chart_type' => $data['chart_type'],
                'version' => $widget->version + 1,
            ])->save();

            return $this->presentWidget($widget->fresh());
        }, 3);
    }

    public function deleteWidget(User $actor, int $widgetId): void
    {
        $this->access->authorize($actor, 'analytics', ModuleCapability::Edit);
        AnalyticsWidget::query()->where('user_id', $actor->id)->whereKey($widgetId)->firstOrFail()->delete();
    }

    /** @return array<string, mixed> */
    private function aggregate(User $actor, string $metricKey): array
    {
        $metric = self::METRICS[$metricKey];
        $this->access->authorize($actor, $metric['source_module'], ModuleCapability::View);
        [$start, $end] = $this->bounds('12m');

        return match ($metricKey) {
            'finance.expenses' => $this->financeExpenses($actor, $start, $end),
            'utilities.consumption' => $this->utilityConsumption($actor, $start, $end),
            'vehicles.distance' => $this->vehicleDistance($actor, $start, $end),
        };
    }

    /** @return array<string, array<string, string>> */
    private function financeExpenses(User $actor, string $start, string $end): array
    {
        $rows = $this->visibleResources($actor, FinancialTransaction::class, 'financial_transactions', FinancialTransaction::query())
            ->where('type', 'expense')
            ->whereBetween('occurred_on', [$start, $end])
            ->selectRaw('occurred_on as period, currency as unit, SUM(amount) as amount')
            ->groupBy('occurred_on', 'currency')
            ->orderBy('occurred_on')
            ->orderBy('currency')
            ->get();
        $daily = [];

        foreach ($rows as $row) {
            $this->addDecimal($daily, $row->unit, $row->period, (string) $row->amount, 2);
        }

        return $daily;
    }

    /** @return array<string, array<string, string>> */
    private function utilityConsumption(User $actor, string $start, string $end): array
    {
        $meters = $this->visibleResources($actor, UtilityMeter::class, 'utility_meters', UtilityMeter::query())
            ->orderBy('id')->get(['id', 'unit'])->keyBy('id');

        if ($meters->isEmpty()) {
            return [];
        }

        $morphType = (new UtilityReading)->getMorphClass();
        $rows = DB::table('utility_readings')
            ->whereIn('utility_meter_id', $meters->keys())
            ->where(function (QueryBuilder $query) use ($start, $end): void {
                $query->whereBetween('reading_date', [$start, $end])
                    ->orWhereRaw('utility_readings.reading_date = (SELECT MAX(prior.reading_date) FROM utility_readings AS prior WHERE prior.utility_meter_id = utility_readings.utility_meter_id AND prior.reading_date < ?)', [$start]);
            })
            ->select(['id', 'utility_meter_id', 'reading_date'])
            ->selectRaw('CASE WHEN utility_readings.is_private = ? OR utility_readings.owner_user_id = ? OR EXISTS (SELECT 1 FROM resource_user_permissions WHERE resource_user_permissions.resource_type = ? AND resource_user_permissions.resource_id = utility_readings.id AND resource_user_permissions.user_id = ? AND resource_user_permissions.capability = ?) THEN utility_readings.value ELSE NULL END AS visible_value', [false, $actor->id, $morphType, $actor->id, ModuleCapability::View->value])
            ->orderBy('utility_meter_id')->orderBy('reading_date')->orderBy('id')->get();
        $previous = [];
        $daily = [];

        foreach ($rows as $row) {
            if ($row->visible_value === null) {
                unset($previous[$row->utility_meter_id]);

                continue;
            }

            $value = (string) $row->visible_value;
            $meterId = (int) $row->utility_meter_id;

            if (isset($previous[$meterId]) && $row->reading_date >= $start) {
                $amount = BigDecimal::of($value)->minus($previous[$meterId])->toScale(6)->__toString();
                $this->addDecimal($daily, $meters[$meterId]->unit, $row->reading_date, $amount, 6);
            }

            $previous[$meterId] = $value;
        }

        return $daily;
    }

    /** @return array<string, array<string, string>> */
    private function vehicleDistance(User $actor, string $start, string $end): array
    {
        $vehicles = $this->visibleResources($actor, Vehicle::class, 'vehicles', Vehicle::query())
            ->orderBy('id')->get(['id', 'odometer_unit'])->keyBy('id');

        if ($vehicles->isEmpty()) {
            return [];
        }

        $morphType = (new VehicleOdometerReading)->getMorphClass();
        $rows = DB::table('vehicle_odometer_readings')
            ->whereIn('vehicle_id', $vehicles->keys())
            ->where(function (QueryBuilder $query) use ($start, $end): void {
                $query->whereBetween('reading_date', [$start, $end])
                    ->orWhereRaw('vehicle_odometer_readings.reading_date = (SELECT MAX(prior.reading_date) FROM vehicle_odometer_readings AS prior WHERE prior.vehicle_id = vehicle_odometer_readings.vehicle_id AND prior.reading_date < ?)', [$start]);
            })
            ->select(['id', 'vehicle_id', 'reading_date'])
            ->selectRaw('CASE WHEN vehicle_odometer_readings.is_private = ? OR vehicle_odometer_readings.owner_user_id = ? OR EXISTS (SELECT 1 FROM resource_user_permissions WHERE resource_user_permissions.resource_type = ? AND resource_user_permissions.resource_id = vehicle_odometer_readings.id AND resource_user_permissions.user_id = ? AND resource_user_permissions.capability = ?) THEN vehicle_odometer_readings.value ELSE NULL END AS visible_value', [false, $actor->id, $morphType, $actor->id, ModuleCapability::View->value])
            ->orderBy('vehicle_id')->orderBy('reading_date')->orderBy('id')->get();
        $previous = [];
        $daily = [];

        foreach ($rows as $row) {
            if ($row->visible_value === null) {
                unset($previous[$row->vehicle_id]);

                continue;
            }

            $value = (string) $row->visible_value;
            $vehicleId = (int) $row->vehicle_id;

            if (isset($previous[$vehicleId]) && $row->reading_date >= $start) {
                $amount = BigDecimal::of($value)->minus($previous[$vehicleId])->toScale(3)->__toString();
                $this->addDecimal($daily, $vehicles[$vehicleId]->odometer_unit, $row->reading_date, $amount, 3);
            }

            $previous[$vehicleId] = $value;
        }

        return $daily;
    }

    /** @param class-string<Model> $modelClass */
    private function visibleResources(User $actor, string $modelClass, string $table, Builder $query): Builder
    {
        $morphType = (new $modelClass)->getMorphClass();

        return $query->where(function (Builder $visible) use ($actor, $morphType, $table): void {
            $visible->where('is_private', false)
                ->orWhere('owner_user_id', $actor->id)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $morphType, $table): void {
                    $permissions->selectRaw('1')
                        ->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', $table.'.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->where('resource_user_permissions.capability', ModuleCapability::View->value);
                });
        });
    }

    /** @param array<string, array<string, string>> $totals */
    private function addDecimal(array &$totals, string $unit, string $period, string $amount, int $scale): void
    {
        $totals[$unit][$period] = isset($totals[$unit][$period])
            ? BigDecimal::of($totals[$unit][$period])->plus($amount)->toScale($scale)->__toString()
            : BigDecimal::of($amount)->toScale($scale)->__toString();
    }

    /** @param array<string, array<string, string>> $daily
     * @return list<array{label: string, unit: string, values: list<string>, total: string}>
     */
    private function renderSeries(array $daily, string $range, int $scale): array
    {
        $periods = $this->periods($range);
        [$start, $end] = $this->bounds($range);
        $series = [];

        foreach ($daily as $unit => $values) {
            $rolled = array_fill_keys($periods, BigDecimal::zero()->toScale($scale)->__toString());

            foreach ($values as $day => $value) {
                $date = CarbonImmutable::parse($day);
                if ($date->format('Y-m-d') < $start || $date->format('Y-m-d') > $end) {
                    continue;
                }

                $bucket = $this->bucketKey($date, $range);

                if (array_key_exists($bucket, $rolled)) {
                    $rolled[$bucket] = BigDecimal::of($rolled[$bucket])->plus($value)->toScale($scale)->__toString();
                }
            }

            $total = BigDecimal::zero()->toScale($scale);
            foreach ($rolled as $value) {
                $total = $total->plus($value);
            }

            $series[] = ['label' => (string) $unit, 'unit' => (string) $unit, 'values' => array_values($rolled), 'total' => $total->__toString()];
        }

        usort($series, fn (array $left, array $right): int => strcmp($left['label'], $right['label']));

        return $series;
    }

    /** @return list<string> */
    private function periods(string $range): array
    {
        [$start, $end] = $this->bounds($range);
        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::parse($end);

        if ($range === '90d') {
            $from = $from->startOfWeek();
            $to = $to->startOfWeek();
            $step = 'week';
        } elseif ($range === '12m') {
            $from = $from->startOfMonth();
            $to = $to->startOfMonth();
            $step = 'month';
        } else {
            $step = 'day';
        }

        $periods = [];
        for ($cursor = $from; $cursor <= $to; $cursor = match ($step) {
            'week' => $cursor->addWeek(),
            'month' => $cursor->addMonth(),
            default => $cursor->addDay(),
        }) {
            $periods[] = $range === '12m' ? $cursor->format('Y-m') : $cursor->format('Y-m-d');
        }

        return $periods;
    }

    /** @return array{string, string} */
    private function bounds(string $range): array
    {
        $today = CarbonImmutable::today();

        return match ($range) {
            '90d' => [$today->subDays(89)->format('Y-m-d'), $today->format('Y-m-d')],
            '12m' => [$today->startOfMonth()->subMonths(11)->format('Y-m-d'), $today->format('Y-m-d')],
            default => [$today->subDays(29)->format('Y-m-d'), $today->format('Y-m-d')],
        };
    }

    private function bucketKey(CarbonImmutable $date, string $range): string
    {
        return match ($range) {
            '90d' => $date->startOfWeek()->format('Y-m-d'),
            '12m' => $date->startOfMonth()->format('Y-m'),
            default => $date->format('Y-m-d'),
        };
    }

    private function canViewSource(User $actor, string $module): bool
    {
        if (! $this->states->isEnabled($module)) {
            return false;
        }

        try {
            $this->access->authorize($actor, $module, ModuleCapability::View);

            return true;
        } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function presentWidget(AnalyticsWidget $widget): array
    {
        return [
            'id' => (int) $widget->id,
            'metric_key' => $widget->metric_key,
            'title' => $widget->title,
            'range_key' => $widget->range_key,
            'chart_type' => $widget->chart_type,
            'position' => (int) $widget->position,
            'version' => (int) $widget->version,
        ];
    }
}
