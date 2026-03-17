<?php

namespace App\Jobs\Summarization;

use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SummarizeAttribution implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $workspaceId,
        public ?Carbon $since = null,
    ) {
        $this->onQueue(config('queues.summarization'));
    }

    /**
     * @return array<int>
     */
    public function backoff(): array
    {
        return [60];
    }

    public function handle(): void
    {
        Log::info("SummarizeAttribution: Starting for workspace [{$this->workspaceId}]");

        $this->summarizeAttributionDaily();
        $this->summarizeAttributionByEffort();
        $this->summarizeAttributionByCampaign();

        Log::info("SummarizeAttribution: Completed for workspace [{$this->workspaceId}]");
    }

    protected function summarizeAttributionDaily(): void
    {
        $query = DB::table('attribution_results')
            ->join('conversion_sales', function ($join) {
                $join->on('attribution_results.conversion_id', '=', 'conversion_sales.id')
                    ->where('attribution_results.conversion_type', '=', 'conversion_sale');
            })
            ->select(
                'attribution_results.workspace_id',
                DB::raw('DATE(COALESCE(conversion_sales.converted_at, conversion_sales.created_at)) as summary_date'),
                'attribution_results.model',
                DB::raw('COUNT(DISTINCT attribution_results.conversion_id) as attributed_conversions'),
                DB::raw('COALESCE(SUM(attribution_results.weight * COALESCE(conversion_sales.revenue, 0)), 0) as attributed_revenue'),
                DB::raw('COALESCE(SUM(attribution_results.weight), 0) as total_weight'),
            )
            ->where('attribution_results.workspace_id', $this->workspaceId)
            ->groupBy('attribution_results.workspace_id', DB::raw('DATE(COALESCE(conversion_sales.converted_at, conversion_sales.created_at))'), 'attribution_results.model');

        if ($this->since) {
            $query->where('attribution_results.updated_at', '>=', $this->since);
        }

        $rows = $query->get();

        foreach ($rows as $row) {
            DB::table('summary_attribution_daily')->upsert(
                [
                    'workspace_id' => $row->workspace_id,
                    'summary_date' => $row->summary_date,
                    'model' => $row->model,
                    'attributed_conversions' => $row->attributed_conversions,
                    'attributed_revenue' => $row->attributed_revenue,
                    'total_weight' => $row->total_weight,
                    'summarized_at' => now(),
                ],
                ['workspace_id', 'summary_date', 'model'],
                ['attributed_conversions', 'attributed_revenue', 'total_weight', 'summarized_at'],
            );
        }
    }

    protected function summarizeAttributionByEffort(): void
    {
        $query = DB::table('attribution_results')
            ->join('conversion_sales', function ($join) {
                $join->on('attribution_results.conversion_id', '=', 'conversion_sales.id')
                    ->where('attribution_results.conversion_type', '=', 'conversion_sale');
            })
            ->select(
                'attribution_results.workspace_id',
                'attribution_results.effort_id',
                DB::raw('DATE(COALESCE(conversion_sales.converted_at, conversion_sales.created_at)) as summary_date'),
                'attribution_results.model',
                DB::raw('COUNT(DISTINCT attribution_results.conversion_id) as attributed_conversions'),
                DB::raw('COALESCE(SUM(attribution_results.weight * COALESCE(conversion_sales.revenue, 0)), 0) as attributed_revenue'),
                DB::raw('COALESCE(SUM(attribution_results.weight), 0) as total_weight'),
            )
            ->where('attribution_results.workspace_id', $this->workspaceId)
            ->whereNotNull('attribution_results.effort_id')
            ->groupBy('attribution_results.workspace_id', 'attribution_results.effort_id', DB::raw('DATE(COALESCE(conversion_sales.converted_at, conversion_sales.created_at))'), 'attribution_results.model');

        if ($this->since) {
            $query->where('attribution_results.updated_at', '>=', $this->since);
        }

        $rows = $query->get();

        foreach ($rows as $row) {
            DB::table('summary_attribution_by_effort')->upsert(
                [
                    'workspace_id' => $row->workspace_id,
                    'effort_id' => $row->effort_id,
                    'summary_date' => $row->summary_date,
                    'model' => $row->model,
                    'attributed_conversions' => $row->attributed_conversions,
                    'attributed_revenue' => $row->attributed_revenue,
                    'total_weight' => $row->total_weight,
                    'summarized_at' => now(),
                ],
                ['workspace_id', 'effort_id', 'summary_date', 'model'],
                ['attributed_conversions', 'attributed_revenue', 'total_weight', 'summarized_at'],
            );
        }
    }

    protected function summarizeAttributionByCampaign(): void
    {
        $query = DB::table('attribution_results')
            ->join('conversion_sales', function ($join) {
                $join->on('attribution_results.conversion_id', '=', 'conversion_sales.id')
                    ->where('attribution_results.conversion_type', '=', 'conversion_sale');
            })
            ->select(
                'attribution_results.workspace_id',
                'attribution_results.campaign_type',
                'attribution_results.campaign_id',
                DB::raw('DATE(COALESCE(conversion_sales.converted_at, conversion_sales.created_at)) as summary_date'),
                'attribution_results.model',
                DB::raw('COUNT(DISTINCT attribution_results.conversion_id) as attributed_conversions'),
                DB::raw('COALESCE(SUM(attribution_results.weight * COALESCE(conversion_sales.revenue, 0)), 0) as attributed_revenue'),
                DB::raw('COALESCE(SUM(attribution_results.weight), 0) as total_weight'),
            )
            ->where('attribution_results.workspace_id', $this->workspaceId)
            ->where('attribution_results.campaign_type', '!=', '')
            ->where('attribution_results.campaign_id', '!=', 0)
            ->groupBy(
                'attribution_results.workspace_id',
                'attribution_results.campaign_type',
                'attribution_results.campaign_id',
                DB::raw('DATE(COALESCE(conversion_sales.converted_at, conversion_sales.created_at))'),
                'attribution_results.model'
            );

        if ($this->since) {
            $query->where('attribution_results.updated_at', '>=', $this->since);
        }

        $rows = $query->get();

        foreach ($rows as $row) {
            DB::table('summary_attribution_by_campaign')->upsert(
                [
                    'workspace_id' => $row->workspace_id,
                    'campaign_type' => $row->campaign_type,
                    'campaign_id' => $row->campaign_id,
                    'summary_date' => $row->summary_date,
                    'model' => $row->model,
                    'attributed_conversions' => $row->attributed_conversions,
                    'attributed_revenue' => $row->attributed_revenue,
                    'total_weight' => $row->total_weight,
                    'summarized_at' => now(),
                ],
                ['workspace_id', 'campaign_type', 'campaign_id', 'summary_date', 'model'],
                ['attributed_conversions', 'attributed_revenue', 'total_weight', 'summarized_at'],
            );
        }
    }
}
