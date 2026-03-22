<?php

namespace App\Services\Dashboard;

use App\Dashboard\DataSourceRegistry;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class WidgetDataService
{
    public function __construct(protected int $workspaceId) {}

    /**
     * Factory: scope to a single workspace.
     */
    public static function forWorkspace(int $workspaceId): self
    {
        return new self($workspaceId);
    }

    /**
     * Fetch a single aggregated metric with previous period comparison.
     *
     * @param  array<string, mixed>  $config  Must include keys: data_source, measure
     * @return array{value: float, previous: float, change: float, format: string, label: string}
     */
    public function fetchMetric(array $config, Carbon $start, Carbon $end): array
    {
        $source = DataSourceRegistry::get($config['data_source']);
        $measureKey = $config['measure'];
        $measureDef = $source['measures'][$measureKey] ?? [];
        $format = $measureDef['format'] ?? 'number';
        $label = $measureDef['label'] ?? $measureKey;

        $days = $start->diffInDays($end);
        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($days);

        $value = $this->computeMeasureValue($source, $measureKey, $start, $end);
        $previous = $this->computeMeasureValue($source, $measureKey, $prevStart, $prevEnd);
        $change = MetricsService::percentChange($previous, $value);

        return [
            'value' => $value,
            'previous' => $previous,
            'change' => $change,
            'format' => $format,
            'label' => $label,
        ];
    }

    /**
     * Fetch a daily time series for a measure.
     *
     * @param  array<string, mixed>  $config  Must include: source, measure
     * @return array{labels: array<string>, datasets: array<int, array{label: string, data: array<int|float>}>}
     */
    public function fetchTrend(array $config, Carbon $start, Carbon $end): array
    {
        $source = DataSourceRegistry::get($config['data_source']);
        $measureKey = $config['measure'];
        $measureDef = $source['measures'][$measureKey] ?? [];
        $label = $measureDef['label'] ?? $measureKey;
        $isComputed = ! empty($measureDef['computed']);

        if ($isComputed) {
            $data = $this->computedTrendSeries($source, $measureKey, $start, $end);
        } else {
            $column = $measureDef['column'] ?? $measureKey;
            $model = $source['summary_model'];
            $rows = $model::query()
                ->where('workspace_id', $this->workspaceId)
                ->forDateRange($start, $end)
                ->selectRaw('summary_date')
                ->selectRaw("COALESCE(SUM(`{$column}`), 0) as measure_value")
                ->groupBy('summary_date')
                ->orderBy('summary_date')
                ->get()
                ->keyBy(fn ($row) => Carbon::parse($row->summary_date)->toDateString());

            $data = $this->backfillDates($rows, $start, $end, 'measure_value');
        }

        return [
            'labels' => $data['labels'],
            'datasets' => [
                ['label' => $label, 'data' => $data['values']],
            ],
        ];
    }

    /**
     * Fetch data grouped by a dimension or date, with optional limit.
     *
     * @param  array<string, mixed>  $config  Must include: source, measure. Optional: group_by (dimension column), limit
     * @return array{labels: array<string>, datasets: array<int, array{label: string, data: array<int|float>}>}
     */
    public function fetchGrouped(array $config, Carbon $start, Carbon $end): array
    {
        $source = DataSourceRegistry::get($config['data_source']);
        $measureKey = $config['measure'];
        $measureDef = $source['measures'][$measureKey] ?? [];
        $label = $measureDef['label'] ?? $measureKey;
        $column = $measureDef['column'] ?? $measureKey;
        $isComputed = ! empty($measureDef['computed']);
        $groupBy = $config['group_by'] ?? 'summary_date';
        $limit = isset($config['limit']) ? (int) $config['limit'] : null;
        $model = $source['summary_model'];

        if ($isComputed) {
            // For computed measures, we need raw fields and compute in PHP
            [$labels, $values] = $this->computedGroupedSeries($source, $measureKey, $groupBy, $limit, $start, $end);
        } else {
            $query = $model::query()
                ->where('workspace_id', $this->workspaceId)
                ->forDateRange($start, $end)
                ->selectRaw("`{$groupBy}`")
                ->selectRaw("COALESCE(SUM(`{$column}`), 0) as measure_value")
                ->groupBy($groupBy)
                ->orderByDesc('measure_value');

            if ($limit !== null) {
                $query->limit($limit);
            }

            $rows = $query->get();

            $labels = $rows->map(fn ($r) => (string) $r->{$groupBy})->values()->all();
            $values = $rows->map(fn ($r) => $this->castMeasureValue($r->measure_value, $measureDef['format'] ?? 'number'))->values()->all();
        }

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => $label, 'data' => $values],
            ],
        ];
    }

    /**
     * Fetch tabular data (non-computed measures only).
     *
     * @param  array<string, mixed>  $config  Must include: source. Optional: columns (array of measure keys), limit
     * @return array{columns: array<int, array{key: string, label: string, format: string}>, rows: array, totals: array<string, int|float>}
     */
    public function fetchTable(array $config, Carbon $start, Carbon $end): array
    {
        $source = DataSourceRegistry::get($config['data_source']);
        $allMeasures = $source['measures'];
        $model = $source['summary_model'];

        // Filter to only non-computed measures; optionally restrict to requested columns
        $requestedKeys = $config['columns'] ?? null;
        $measures = array_filter($allMeasures, function (array $def, string $key) use ($requestedKeys) {
            if (! empty($def['computed'])) {
                return false;
            }

            return $requestedKeys === null || in_array($key, $requestedKeys, true);
        }, ARRAY_FILTER_USE_BOTH);

        $columns = [];
        $selects = ['summary_date'];

        foreach ($measures as $key => $def) {
            $col = $def['column'] ?? $key;
            $selects[] = "COALESCE(SUM(`{$col}`), 0) as `{$key}`";
            $columns[] = ['key' => $key, 'label' => $def['label'], 'format' => $def['format'] ?? 'number'];
        }

        $limit = isset($config['limit']) ? (int) $config['limit'] : null;

        $query = $model::query()
            ->where('workspace_id', $this->workspaceId)
            ->forDateRange($start, $end)
            ->selectRaw(implode(', ', array_map(fn ($s) => $s === 'summary_date' ? '`summary_date`' : $s, $selects)))
            ->groupBy('summary_date')
            ->orderBy('summary_date');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $rows = $query->get();

        $totals = array_fill_keys(array_keys($measures), 0);
        $tableRows = [];

        foreach ($rows as $row) {
            $tableRow = ['date' => Carbon::parse($row->summary_date)->toDateString()];

            foreach ($measures as $key => $def) {
                $format = $def['format'] ?? 'number';
                $value = $this->castMeasureValue($row->{$key}, $format);
                $tableRow[$key] = $value;
                $totals[$key] += $value;
            }

            $tableRows[] = $tableRow;
        }

        // Round currency/decimal totals
        foreach ($measures as $key => $def) {
            $format = $def['format'] ?? 'number';
            if ($format === 'currency' || $format === 'decimal') {
                $totals[$key] = round((float) $totals[$key], 2);
            } else {
                $totals[$key] = (int) $totals[$key];
            }
        }

        return [
            'columns' => $columns,
            'rows' => $tableRows,
            'totals' => $totals,
        ];
    }

    // ── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Compute a single aggregated measure value, handling computed measures.
     */
    protected function computeMeasureValue(array $source, string $measureKey, Carbon $start, Carbon $end): float
    {
        $measureDef = $source['measures'][$measureKey] ?? [];
        $isComputed = ! empty($measureDef['computed']);
        $model = $source['summary_model'];

        if (! $isComputed) {
            $column = $measureDef['column'] ?? $measureKey;

            return (float) $model::query()
                ->where('workspace_id', $this->workspaceId)
                ->forDateRange($start, $end)
                ->selectRaw("COALESCE(SUM(`{$column}`), 0) as v")
                ->value('v');
        }

        return match ($measureKey) {
            'open_rate' => $this->computeRate($source, $model, 'unique_opens', 'sent', $start, $end),
            'click_rate' => $this->computeRate($source, $model, 'unique_clicks', 'sent', $start, $end),
            'roas' => $this->computeRoas($source, $model, $start, $end),
            default => 0.0,
        };
    }

    /**
     * Compute a rate: numerator / denominator * 100.
     */
    protected function computeRate(array $source, string $model, string $numeratorCol, string $denominatorCol, Carbon $start, Carbon $end): float
    {
        $row = $model::query()
            ->where('workspace_id', $this->workspaceId)
            ->forDateRange($start, $end)
            ->selectRaw("COALESCE(SUM(`{$numeratorCol}`), 0) as numerator, COALESCE(SUM(`{$denominatorCol}`), 0) as denominator")
            ->first();

        $denominator = (float) $row->denominator;

        return $denominator > 0 ? round((float) $row->numerator / $denominator * 100, 2) : 0.0;
    }

    /**
     * Compute ROAS: revenue / cost.
     */
    protected function computeRoas(array $source, string $model, Carbon $start, Carbon $end): float
    {
        $row = $model::query()
            ->where('workspace_id', $this->workspaceId)
            ->forDateRange($start, $end)
            ->selectRaw('COALESCE(SUM(`revenue`), 0) as revenue, COALESCE(SUM(`cost`), 0) as cost')
            ->first();

        $cost = (float) $row->cost;

        return $cost > 0 ? round((float) $row->revenue / $cost, 2) : 0.0;
    }

    /**
     * Build a backfilled date series from a keyed collection.
     *
     * @return array{labels: array<string>, values: array<int|float>}
     */
    protected function backfillDates(Collection $rows, Carbon $start, Carbon $end, string $valueKey): array
    {
        $labels = [];
        $values = [];

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $dateStr = $date->toDateString();
            $row = $rows->get($dateStr);
            $labels[] = $dateStr;
            $values[] = $row ? (float) $row->{$valueKey} : 0.0;
        }

        return compact('labels', 'values');
    }

    /**
     * Build a trend series for a computed measure by fetching raw columns and computing per day.
     *
     * @return array{labels: array<string>, values: array<float>}
     */
    protected function computedTrendSeries(array $source, string $measureKey, Carbon $start, Carbon $end): array
    {
        $model = $source['summary_model'];

        $rows = match ($measureKey) {
            'open_rate' => $model::query()
                ->where('workspace_id', $this->workspaceId)
                ->forDateRange($start, $end)
                ->selectRaw('summary_date, COALESCE(SUM(unique_opens), 0) as n, COALESCE(SUM(sent), 0) as d')
                ->groupBy('summary_date')
                ->orderBy('summary_date')
                ->get()
                ->keyBy(fn ($r) => Carbon::parse($r->summary_date)->toDateString()),
            'click_rate' => $model::query()
                ->where('workspace_id', $this->workspaceId)
                ->forDateRange($start, $end)
                ->selectRaw('summary_date, COALESCE(SUM(unique_clicks), 0) as n, COALESCE(SUM(sent), 0) as d')
                ->groupBy('summary_date')
                ->orderBy('summary_date')
                ->get()
                ->keyBy(fn ($r) => Carbon::parse($r->summary_date)->toDateString()),
            'roas' => $model::query()
                ->where('workspace_id', $this->workspaceId)
                ->forDateRange($start, $end)
                ->selectRaw('summary_date, COALESCE(SUM(revenue), 0) as n, COALESCE(SUM(cost), 0) as d')
                ->groupBy('summary_date')
                ->orderBy('summary_date')
                ->get()
                ->keyBy(fn ($r) => Carbon::parse($r->summary_date)->toDateString()),
            default => collect(),
        };

        $labels = [];
        $values = [];

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $dateStr = $date->toDateString();
            $row = $rows->get($dateStr);
            $labels[] = $dateStr;

            if ($row) {
                $d = (float) $row->d;
                $values[] = $d > 0 ? round((float) $row->n / $d * ($measureKey === 'roas' ? 1 : 100), 2) : 0.0;
            } else {
                $values[] = 0.0; // backfill
            }
        }

        return compact('labels', 'values');
    }

    /**
     * Build grouped data for a computed measure.
     *
     * @return array{0: array<string>, 1: array<float>}
     */
    protected function computedGroupedSeries(array $source, string $measureKey, string $groupBy, ?int $limit, Carbon $start, Carbon $end): array
    {
        $model = $source['summary_model'];

        $rows = match ($measureKey) {
            'open_rate' => $model::query()
                ->where('workspace_id', $this->workspaceId)
                ->forDateRange($start, $end)
                ->selectRaw("`{$groupBy}`, COALESCE(SUM(unique_opens), 0) as n, COALESCE(SUM(sent), 0) as d")
                ->groupBy($groupBy)
                ->get(),
            'click_rate' => $model::query()
                ->where('workspace_id', $this->workspaceId)
                ->forDateRange($start, $end)
                ->selectRaw("`{$groupBy}`, COALESCE(SUM(unique_clicks), 0) as n, COALESCE(SUM(sent), 0) as d")
                ->groupBy($groupBy)
                ->get(),
            'roas' => $model::query()
                ->where('workspace_id', $this->workspaceId)
                ->forDateRange($start, $end)
                ->selectRaw("`{$groupBy}`, COALESCE(SUM(revenue), 0) as n, COALESCE(SUM(cost), 0) as d")
                ->groupBy($groupBy)
                ->get(),
            default => collect(),
        };

        $mapped = $rows->map(function ($row) use ($groupBy, $measureKey) {
            $d = (float) $row->d;
            $value = $d > 0 ? round((float) $row->n / $d * ($measureKey === 'roas' ? 1 : 100), 2) : 0.0;

            return ['label' => (string) $row->{$groupBy}, 'value' => $value];
        })->sortByDesc('value');

        if ($limit !== null) {
            $mapped = $mapped->take($limit);
        }

        $mapped = $mapped->values();

        return [
            $mapped->pluck('label')->all(),
            $mapped->pluck('value')->all(),
        ];
    }

    /**
     * Cast a raw database value to the appropriate PHP type based on format.
     */
    protected function castMeasureValue(mixed $value, string $format): int|float
    {
        return match ($format) {
            'currency', 'decimal', 'percent' => round((float) $value, 2),
            default => (int) $value,
        };
    }
}
