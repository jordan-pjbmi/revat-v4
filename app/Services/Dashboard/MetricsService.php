<?php

namespace App\Services\Dashboard;

use App\Models\SummaryAttributionByEffort;
use App\Models\SummaryAttributionDaily;
use App\Models\SummaryCampaignByPlatform;
use App\Models\SummaryCampaignDaily;
use App\Models\SummaryConversionDaily;
use App\Models\SummaryWorkspaceDaily;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MetricsService
{
    /** @var array<int> */
    protected array $workspaceIds;

    public function __construct(int|array $workspaceIds)
    {
        $this->workspaceIds = is_array($workspaceIds) ? $workspaceIds : [$workspaceIds];
    }

    /**
     * Factory: scope to a single workspace.
     */
    public static function forWorkspace(int $workspaceId): self
    {
        return new self($workspaceId);
    }

    /**
     * Factory: scope to multiple workspaces.
     *
     * @param  array<int>  $workspaceIds
     */
    public static function forWorkspaceIds(array $workspaceIds): self
    {
        return new self($workspaceIds);
    }

    /**
     * Campaign metrics from summary_campaign_daily.
     *
     * @return array{campaigns: int, sent: int, opens: int, unique_opens: int, clicks: int, unique_clicks: int, open_rate: float, click_rate: float, revenue: float}
     */
    public function getCampaignMetrics(Carbon $start, Carbon $end): array
    {
        $row = SummaryCampaignDaily::query()
            ->whereIn('workspace_id', $this->workspaceIds)
            ->forDateRange($start, $end)
            ->selectRaw('COALESCE(SUM(campaigns_count), 0) as campaigns')
            ->selectRaw('COALESCE(SUM(sent), 0) as sent')
            ->selectRaw('COALESCE(SUM(opens), 0) as opens')
            ->selectRaw('COALESCE(SUM(unique_opens), 0) as unique_opens')
            ->selectRaw('COALESCE(SUM(clicks), 0) as clicks')
            ->selectRaw('COALESCE(SUM(unique_clicks), 0) as unique_clicks')
            ->selectRaw('COALESCE(SUM(platform_revenue), 0) as revenue')
            ->first();

        $sent = (int) $row->sent;
        $uniqueOpens = (int) $row->unique_opens;
        $uniqueClicks = (int) $row->unique_clicks;

        return [
            'campaigns' => (int) $row->campaigns,
            'sent' => $sent,
            'opens' => (int) $row->opens,
            'unique_opens' => $uniqueOpens,
            'clicks' => (int) $row->clicks,
            'unique_clicks' => $uniqueClicks,
            'open_rate' => $sent > 0 ? round($uniqueOpens / $sent * 100, 2) : 0.00,
            'click_rate' => $sent > 0 ? round($uniqueClicks / $sent * 100, 2) : 0.00,
            'revenue' => round((float) $row->revenue, 2),
        ];
    }

    /**
     * Conversion metrics from summary_conversion_daily.
     *
     * @return array{conversions: int, conversion_revenue: float, payout: float, cost: float}
     */
    public function getConversionMetrics(Carbon $start, Carbon $end): array
    {
        $row = SummaryConversionDaily::query()
            ->whereIn('workspace_id', $this->workspaceIds)
            ->forDateRange($start, $end)
            ->selectRaw('COALESCE(SUM(conversions_count), 0) as conversions')
            ->selectRaw('COALESCE(SUM(revenue), 0) as conversion_revenue')
            ->selectRaw('COALESCE(SUM(payout), 0) as payout')
            ->selectRaw('COALESCE(SUM(cost), 0) as cost')
            ->first();

        return [
            'conversions' => (int) $row->conversions,
            'conversion_revenue' => round((float) $row->conversion_revenue, 2),
            'payout' => round((float) $row->payout, 2),
            'cost' => round((float) $row->cost, 2),
        ];
    }

    /**
     * Merged campaign + conversion metrics.
     */
    public function getOverview(Carbon $start, Carbon $end): array
    {
        return array_merge(
            $this->getCampaignMetrics($start, $end),
            $this->getConversionMetrics($start, $end),
        );
    }

    /**
     * Current vs previous period comparison with percent changes.
     *
     * @return array{current: array, previous: array, changes: array}
     */
    public function getPreviousPeriodComparison(Carbon $start, Carbon $end): array
    {
        $days = $start->diffInDays($end);
        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($days);

        $current = $this->getOverview($start, $end);
        $previous = $this->getOverview($prevStart, $prevEnd);

        $changes = [];
        foreach ($current as $key => $value) {
            $changes[$key] = self::percentChange($previous[$key] ?? 0, $value);
        }

        return [
            'current' => $current,
            'previous' => $previous,
            'changes' => $changes,
        ];
    }

    /**
     * Daily trend data from summary_workspace_daily with missing-date backfill.
     *
     * @return array{dates: array, sent: array, opens: array, clicks: array, conversions: array, revenue: array, cost: array}
     */
    public function getDailyTrend(Carbon $start, Carbon $end): array
    {
        $rows = SummaryWorkspaceDaily::query()
            ->whereIn('workspace_id', $this->workspaceIds)
            ->forDateRange($start, $end)
            ->selectRaw('summary_date')
            ->selectRaw('COALESCE(SUM(sent), 0) as sent')
            ->selectRaw('COALESCE(SUM(opens), 0) as opens')
            ->selectRaw('COALESCE(SUM(clicks), 0) as clicks')
            ->selectRaw('COALESCE(SUM(conversions_count), 0) as conversions')
            ->selectRaw('COALESCE(SUM(revenue), 0) as revenue')
            ->selectRaw('COALESCE(SUM(cost), 0) as cost')
            ->groupBy('summary_date')
            ->orderBy('summary_date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->summary_date)->toDateString());

        $dates = [];
        $sent = [];
        $opens = [];
        $clicks = [];
        $conversions = [];
        $revenue = [];
        $cost = [];

        $period = CarbonPeriod::create($start, $end);

        foreach ($period as $date) {
            $dateStr = $date->toDateString();
            $row = $rows->get($dateStr);

            $dates[] = $dateStr;
            $sent[] = $row ? (int) $row->sent : 0;
            $opens[] = $row ? (int) $row->opens : 0;
            $clicks[] = $row ? (int) $row->clicks : 0;
            $conversions[] = $row ? (int) $row->conversions : 0;
            $revenue[] = $row ? round((float) $row->revenue, 2) : 0.00;
            $cost[] = $row ? round((float) $row->cost, 2) : 0.00;
        }

        return compact('dates', 'sent', 'opens', 'clicks', 'conversions', 'revenue', 'cost');
    }

    /**
     * Grouped report with multiple grouping modes.
     *
     * @return array{rows: array, totals: array}
     */
    public function getGroupedReport(Carbon $start, Carbon $end, string $groupBy = 'day'): array
    {
        return match ($groupBy) {
            'day' => $this->groupedByPeriod($start, $end, 'day'),
            'week' => $this->groupedByPeriod($start, $end, 'week'),
            'month' => $this->groupedByPeriod($start, $end, 'month'),
            'platform' => $this->groupedByPlatform($start, $end),
            'campaign' => $this->groupedByCampaign($start, $end),
            default => $this->groupedByPeriod($start, $end, 'day'),
        };
    }

    /**
     * Attribution summary from summary_attribution_daily.
     *
     * @return array{attributed_conversions: int, attributed_revenue: float, total_weight: float}
     */
    public function getAttributionSummary(Carbon $start, Carbon $end, string $model = 'first_touch'): array
    {
        $row = SummaryAttributionDaily::query()
            ->whereIn('workspace_id', $this->workspaceIds)
            ->forDateRange($start, $end)
            ->forModel($model)
            ->selectRaw('COALESCE(SUM(attributed_conversions), 0) as attributed_conversions')
            ->selectRaw('COALESCE(SUM(attributed_revenue), 0) as attributed_revenue')
            ->selectRaw('COALESCE(SUM(total_weight), 0) as total_weight')
            ->first();

        return [
            'attributed_conversions' => (int) $row->attributed_conversions,
            'attributed_revenue' => round((float) $row->attributed_revenue, 2),
            'total_weight' => round((float) $row->total_weight, 4),
        ];
    }

    /**
     * Attribution breakdown by effort from summary_attribution_by_effort.
     *
     * @return array<int, array{effort_id: int, effort_name: string, attributed_conversions: int, attributed_revenue: float, total_weight: float}>
     */
    public function getAttributionByEffort(Carbon $start, Carbon $end, string $model = 'first_touch'): array
    {
        $rows = SummaryAttributionByEffort::query()
            ->whereIn('summary_attribution_by_effort.workspace_id', $this->workspaceIds)
            ->forDateRange($start, $end)
            ->forModel($model)
            ->join('efforts', 'summary_attribution_by_effort.effort_id', '=', 'efforts.id')
            ->selectRaw('summary_attribution_by_effort.effort_id')
            ->selectRaw('efforts.name as effort_name')
            ->selectRaw('COALESCE(SUM(summary_attribution_by_effort.attributed_conversions), 0) as attributed_conversions')
            ->selectRaw('COALESCE(SUM(summary_attribution_by_effort.attributed_revenue), 0) as attributed_revenue')
            ->selectRaw('COALESCE(SUM(summary_attribution_by_effort.total_weight), 0) as total_weight')
            ->groupBy('summary_attribution_by_effort.effort_id', 'efforts.name')
            ->orderByDesc('attributed_revenue')
            ->get();

        return $rows->map(fn ($row) => [
            'effort_id' => (int) $row->effort_id,
            'effort_name' => $row->effort_name,
            'attributed_conversions' => (int) $row->attributed_conversions,
            'attributed_revenue' => round((float) $row->attributed_revenue, 2),
            'total_weight' => round((float) $row->total_weight, 4),
        ])->values()->all();
    }

    // ── Private Helpers ─────────────────────────────────────────────────

    /**
     * Compute percent change, handling zero-to-nonzero and zero-to-zero.
     */
    public static function percentChange(float|int $previous, float|int $current): float
    {
        if ($previous == 0 && $current == 0) {
            return 0.00;
        }

        if ($previous == 0) {
            return 100.00;
        }

        return round(($current - $previous) / $previous * 100, 2);
    }

    /**
     * Group by day/week/month using campaign daily + conversion daily summaries.
     */
    protected function groupedByPeriod(Carbon $start, Carbon $end, string $period): array
    {
        // Fetch campaign daily data
        $campaignRows = SummaryCampaignDaily::query()
            ->whereIn('workspace_id', $this->workspaceIds)
            ->forDateRange($start, $end)
            ->get();

        // Fetch conversion daily data
        $conversionRows = SummaryConversionDaily::query()
            ->whereIn('workspace_id', $this->workspaceIds)
            ->forDateRange($start, $end)
            ->get();

        // Group campaign data by period label
        $campaignGrouped = $campaignRows->groupBy(fn ($row) => $this->periodLabel($row->summary_date, $period));
        $conversionGrouped = $conversionRows->groupBy(fn ($row) => $this->periodLabel($row->summary_date, $period));

        $allLabels = $campaignGrouped->keys()->merge($conversionGrouped->keys())->unique()->sort();

        $rows = [];
        $totals = $this->emptyReportTotals();

        foreach ($allLabels as $label) {
            $cRows = $campaignGrouped->get($label, collect());
            $cvRows = $conversionGrouped->get($label, collect());

            $row = $this->buildReportRow($label, $cRows, $cvRows);
            $rows[] = $row;
            $totals = $this->addToTotals($totals, $row);
        }

        $totals = $this->finalizeTotals($totals);

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Group by platform using summary_campaign_by_platform.
     */
    protected function groupedByPlatform(Carbon $start, Carbon $end): array
    {
        $platformRows = SummaryCampaignByPlatform::query()
            ->whereIn('workspace_id', $this->workspaceIds)
            ->forDateRange($start, $end)
            ->get()
            ->groupBy('platform');

        // Conversion data is not platform-grouped, so we aggregate it once
        $conversionRows = SummaryConversionDaily::query()
            ->whereIn('workspace_id', $this->workspaceIds)
            ->forDateRange($start, $end)
            ->get();

        $rows = [];
        $totals = $this->emptyReportTotals();

        foreach ($platformRows as $platform => $cRows) {
            // Platform-specific conversions not available; pass empty
            $row = $this->buildReportRow($platform, $cRows, collect());
            $rows[] = $row;
            $totals = $this->addToTotals($totals, $row);
        }

        // Add conversions as aggregate to totals
        if ($conversionRows->isNotEmpty()) {
            $totals['conversions'] += $conversionRows->sum('conversions_count');
            $totals['revenue'] += (float) $conversionRows->sum('revenue');
            $totals['cost'] += (float) $conversionRows->sum('cost');
        }

        $totals = $this->finalizeTotals($totals);

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Group by campaign — falls back to direct campaign_emails query.
     */
    protected function groupedByCampaign(Carbon $start, Carbon $end): array
    {
        $campaignRows = DB::table('campaign_emails')
            ->whereIn('workspace_id', $this->workspaceIds)
            ->whereBetween('sent_at', [$start->toDateString(), $end->endOfDay()->toDateTimeString()])
            ->whereNull('deleted_at')
            ->select(
                'id',
                'name',
                DB::raw('COALESCE(sent, 0) as sent'),
                DB::raw('COALESCE(opens, 0) as opens'),
                DB::raw('COALESCE(unique_opens, 0) as unique_opens'),
                DB::raw('COALESCE(clicks, 0) as clicks'),
                DB::raw('COALESCE(unique_clicks, 0) as unique_clicks'),
                DB::raw('COALESCE(bounced, 0) as bounced'),
                DB::raw('COALESCE(unsubscribes, 0) as unsubscribes'),
                DB::raw('COALESCE(complaints, 0) as complaints'),
                DB::raw('COALESCE(platform_revenue, 0) as platform_revenue'),
            )
            ->orderByDesc('sent')
            ->get();

        // Get conversion totals for the period (not per-campaign)
        $conversionTotals = SummaryConversionDaily::query()
            ->whereIn('workspace_id', $this->workspaceIds)
            ->forDateRange($start, $end)
            ->selectRaw('COALESCE(SUM(conversions_count), 0) as conversions')
            ->selectRaw('COALESCE(SUM(revenue), 0) as revenue')
            ->selectRaw('COALESCE(SUM(cost), 0) as cost')
            ->first();

        $rows = [];
        $totals = $this->emptyReportTotals();

        foreach ($campaignRows as $campaign) {
            $sent = (int) $campaign->sent;
            $uniqueOpens = (int) $campaign->unique_opens;
            $uniqueClicks = (int) $campaign->unique_clicks;
            $bounced = (int) $campaign->bounced;
            $unsubscribes = (int) $campaign->unsubscribes;
            $complaints = (int) $campaign->complaints;

            $row = [
                'group_label' => $campaign->name ?? "Campaign #{$campaign->id}",
                'sent' => $sent,
                'opens' => (int) $campaign->opens,
                'open_rate' => $sent > 0 ? round($uniqueOpens / $sent * 100, 2) : 0.00,
                'clicks' => (int) $campaign->clicks,
                'click_rate' => $sent > 0 ? round($uniqueClicks / $sent * 100, 2) : 0.00,
                'bounced' => $bounced,
                'bounce_rate' => $sent > 0 ? round($bounced / $sent * 100, 2) : 0.00,
                'unsubscribes' => $unsubscribes,
                'unsubscribe_rate' => $sent > 0 ? round($unsubscribes / $sent * 100, 2) : 0.00,
                'complaints' => $complaints,
                'complaint_rate' => $sent > 0 ? round($complaints / $sent * 100, 2) : 0.00,
                'conversions' => 0,
                'revenue' => round((float) $campaign->platform_revenue, 2),
                'cost' => 0.00,
                'profit' => round((float) $campaign->platform_revenue, 2),
            ];

            $rows[] = $row;
            $totals = $this->addToTotals($totals, $row);
        }

        // Override conversion totals at the report level
        $totals['conversions'] = (int) $conversionTotals->conversions;
        $totals['revenue'] += round((float) $conversionTotals->revenue, 2);
        $totals['cost'] = round((float) $conversionTotals->cost, 2);

        $totals = $this->finalizeTotals($totals);

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Build a report row from campaign and conversion summary collections.
     */
    protected function buildReportRow(string $label, Collection $campaignRows, Collection $conversionRows): array
    {
        $sent = (int) $campaignRows->sum('sent');
        $uniqueOpens = (int) $campaignRows->sum('unique_opens');
        $uniqueClicks = (int) $campaignRows->sum('unique_clicks');
        $bounced = (int) $campaignRows->sum('bounced');
        $unsubscribes = (int) $campaignRows->sum('unsubscribes');
        $complaints = (int) $campaignRows->sum('complaints');
        $conversionRevenue = (float) $conversionRows->sum('revenue');
        $conversionCost = (float) $conversionRows->sum('cost');
        $platformRevenue = (float) $campaignRows->sum('platform_revenue');

        $revenue = round($conversionRevenue + $platformRevenue, 2);
        $cost = round($conversionCost, 2);

        return [
            'group_label' => $label,
            'sent' => $sent,
            'opens' => (int) $campaignRows->sum('opens'),
            'open_rate' => $sent > 0 ? round($uniqueOpens / $sent * 100, 2) : 0.00,
            'clicks' => (int) $campaignRows->sum('clicks'),
            'click_rate' => $sent > 0 ? round($uniqueClicks / $sent * 100, 2) : 0.00,
            'bounced' => $bounced,
            'bounce_rate' => $sent > 0 ? round($bounced / $sent * 100, 2) : 0.00,
            'unsubscribes' => $unsubscribes,
            'unsubscribe_rate' => $sent > 0 ? round($unsubscribes / $sent * 100, 2) : 0.00,
            'complaints' => $complaints,
            'complaint_rate' => $sent > 0 ? round($complaints / $sent * 100, 2) : 0.00,
            'conversions' => (int) $conversionRows->sum('conversions_count'),
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => round($revenue - $cost, 2),
        ];
    }

    /**
     * Generate period label from a date.
     */
    protected function periodLabel(mixed $date, string $period): string
    {
        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return match ($period) {
            'day' => $carbon->toDateString(),
            'week' => $carbon->startOfWeek()->toDateString().' - '.$carbon->endOfWeek()->toDateString(),
            'month' => $carbon->format('Y-m'),
            default => $carbon->toDateString(),
        };
    }

    /**
     * Empty totals structure for report aggregation.
     */
    protected function emptyReportTotals(): array
    {
        return [
            'sent' => 0,
            'opens' => 0,
            'clicks' => 0,
            'bounced' => 0,
            'unsubscribes' => 0,
            'complaints' => 0,
            'conversions' => 0,
            'revenue' => 0.00,
            'cost' => 0.00,
        ];
    }

    /**
     * Add a row's values to running totals.
     */
    protected function addToTotals(array $totals, array $row): array
    {
        $totals['sent'] += $row['sent'];
        $totals['opens'] += $row['opens'];
        $totals['clicks'] += $row['clicks'];
        $totals['bounced'] += $row['bounced'];
        $totals['unsubscribes'] += $row['unsubscribes'];
        $totals['complaints'] += $row['complaints'];
        $totals['conversions'] += $row['conversions'];
        $totals['revenue'] += $row['revenue'];
        $totals['cost'] += $row['cost'];

        return $totals;
    }

    /**
     * Compute rates and profit for totals row.
     */
    protected function finalizeTotals(array $totals): array
    {
        $sent = $totals['sent'];

        $totals['open_rate'] = $sent > 0 ? round($totals['opens'] / $sent * 100, 2) : 0.00;
        $totals['click_rate'] = $sent > 0 ? round($totals['clicks'] / $sent * 100, 2) : 0.00;
        $totals['bounce_rate'] = $sent > 0 ? round($totals['bounced'] / $sent * 100, 2) : 0.00;
        $totals['unsubscribe_rate'] = $sent > 0 ? round($totals['unsubscribes'] / $sent * 100, 2) : 0.00;
        $totals['complaint_rate'] = $sent > 0 ? round($totals['complaints'] / $sent * 100, 2) : 0.00;
        $totals['revenue'] = round($totals['revenue'], 2);
        $totals['cost'] = round($totals['cost'], 2);
        $totals['profit'] = round($totals['revenue'] - $totals['cost'], 2);

        return $totals;
    }
}
