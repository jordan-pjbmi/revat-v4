<?php

namespace App\Services;

use App\Models\AttributionConnector;
use App\Models\AttributionResult;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AttributionEngine
{
    public const VALID_MODELS = ['first_touch', 'last_touch', 'linear'];

    /**
     * Run attribution for a workspace/connector/model combination.
     * Returns the count of results written.
     */
    public function run(Workspace $workspace, AttributionConnector $connector, string $model): int
    {
        if (! in_array($model, self::VALID_MODELS, true)) {
            throw new InvalidArgumentException("Invalid attribution model: {$model}. Must be one of: ".implode(', ', self::VALID_MODELS));
        }

        // Clear existing results for this connector/model
        AttributionResult::where('connector_id', $connector->id)
            ->where('model', $model)
            ->delete();

        // Try click-based matching first, fall back to direct campaign matching
        $results = $this->matchViaClicks($workspace, $connector, $model);

        if (empty($results)) {
            $results = $this->matchViaCampaigns($workspace, $connector, $model);
        }

        if (empty($results)) {
            return 0;
        }

        // Bulk insert results
        $now = now();
        $inserts = [];

        foreach ($results as $result) {
            $inserts[] = [
                'workspace_id' => $workspace->id,
                'connector_id' => $connector->id,
                'conversion_type' => 'conversion_sale',
                'conversion_id' => $result['conversion_id'],
                'effort_id' => $result['effort_id'],
                'campaign_type' => $result['campaign_type'],
                'campaign_id' => $result['campaign_id'],
                'model' => $model,
                'weight' => $result['weight'],
                'matched_at' => $result['matched_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Upsert on the unique constraint
        DB::table('attribution_results')->upsert(
            $inserts,
            ['conversion_type', 'conversion_id', 'effort_id', 'campaign_type', 'campaign_id', 'model'],
            ['weight', 'matched_at', 'connector_id', 'workspace_id', 'updated_at']
        );

        return count($inserts);
    }

    /**
     * Match conversions to campaigns via click data.
     * Join: conversion_sales → ark(conversion) → attribution_keys → ark(click) → campaign_email_clicks → campaign_emails
     */
    protected function matchViaClicks(Workspace $workspace, AttributionConnector $connector, string $model): array
    {
        $query = DB::table('conversion_sales as cs')
            ->join('attribution_record_keys as ark_conv', function ($join) use ($connector) {
                $join->on('ark_conv.record_id', '=', 'cs.id')
                    ->where('ark_conv.record_type', '=', 'conversion_sale')
                    ->where('ark_conv.connector_id', '=', $connector->id);
            })
            ->join('attribution_keys as ak', 'ak.id', '=', 'ark_conv.attribution_key_id')
            ->join('attribution_record_keys as ark_click', function ($join) use ($connector) {
                $join->on('ark_click.attribution_key_id', '=', 'ak.id')
                    ->where('ark_click.record_type', '=', 'campaign_email_click')
                    ->where('ark_click.connector_id', '=', $connector->id);
            })
            ->join('campaign_email_clicks as cec', 'cec.id', '=', 'ark_click.record_id')
            ->join('campaign_emails as ce', 'ce.id', '=', 'cec.campaign_email_id')
            ->where('cs.workspace_id', $workspace->id)
            ->whereNull('cs.deleted_at')
            ->whereNull('cec.deleted_at')
            ->whereNull('ce.deleted_at')
            ->whereNotNull('ak.effort_id')
            ->select([
                'cs.id as conversion_id',
                'ak.effort_id',
                'cec.clicked_at',
                'cec.id as click_id',
                'ce.id as campaign_id',
                DB::raw("'campaign_email' as campaign_type"),
            ]);

        $matches = $query->get();

        if ($matches->isEmpty()) {
            return [];
        }

        return $this->applyModel($matches, $model, 'clicked_at');
    }

    /**
     * Fallback: match conversions to campaigns directly (no click data).
     * Join: conversion_sales → ark(conversion) → attribution_keys → ark(campaign) → campaign_emails
     */
    protected function matchViaCampaigns(Workspace $workspace, AttributionConnector $connector, string $model): array
    {
        $query = DB::table('conversion_sales as cs')
            ->join('attribution_record_keys as ark_conv', function ($join) use ($connector) {
                $join->on('ark_conv.record_id', '=', 'cs.id')
                    ->where('ark_conv.record_type', '=', 'conversion_sale')
                    ->where('ark_conv.connector_id', '=', $connector->id);
            })
            ->join('attribution_keys as ak', 'ak.id', '=', 'ark_conv.attribution_key_id')
            ->join('attribution_record_keys as ark_camp', function ($join) use ($connector) {
                $join->on('ark_camp.attribution_key_id', '=', 'ak.id')
                    ->where('ark_camp.record_type', '=', 'campaign_email')
                    ->where('ark_camp.connector_id', '=', $connector->id);
            })
            ->join('campaign_emails as ce', 'ce.id', '=', 'ark_camp.record_id')
            ->where('cs.workspace_id', $workspace->id)
            ->whereNull('cs.deleted_at')
            ->whereNull('ce.deleted_at')
            ->whereNotNull('ak.effort_id')
            ->select([
                'cs.id as conversion_id',
                'ak.effort_id',
                'ce.sent_at as clicked_at',
                'ce.id as click_id',
                'ce.id as campaign_id',
                DB::raw("'campaign_email' as campaign_type"),
            ]);

        $matches = $query->get();

        if ($matches->isEmpty()) {
            return [];
        }

        return $this->applyModel($matches, $model, 'clicked_at');
    }

    /**
     * Apply the attribution model to matched records.
     */
    protected function applyModel($matches, string $model, string $timestampField): array
    {
        $grouped = $matches->groupBy('conversion_id');
        $results = [];

        foreach ($grouped as $conversionId => $conversionMatches) {
            match ($model) {
                'first_touch' => $this->applyFirstTouch($results, $conversionId, $conversionMatches, $timestampField),
                'last_touch' => $this->applyLastTouch($results, $conversionId, $conversionMatches, $timestampField),
                'linear' => $this->applyLinear($results, $conversionId, $conversionMatches, $timestampField),
            };
        }

        return $results;
    }

    /**
     * First touch: earliest timestamp wins, weight = 1.0.
     */
    protected function applyFirstTouch(array &$results, $conversionId, $matches, string $timestampField): void
    {
        $winner = $matches->sortBy($timestampField)->first();

        $results[] = [
            'conversion_id' => $conversionId,
            'effort_id' => $winner->effort_id,
            'campaign_type' => $winner->campaign_type,
            'campaign_id' => $winner->campaign_id,
            'weight' => 1.0,
            'matched_at' => $winner->$timestampField,
        ];
    }

    /**
     * Last touch: latest timestamp wins, weight = 1.0.
     */
    protected function applyLastTouch(array &$results, $conversionId, $matches, string $timestampField): void
    {
        $winner = $matches->sortByDesc($timestampField)->first();

        $results[] = [
            'conversion_id' => $conversionId,
            'effort_id' => $winner->effort_id,
            'campaign_type' => $winner->campaign_type,
            'campaign_id' => $winner->campaign_id,
            'weight' => 1.0,
            'matched_at' => $winner->$timestampField,
        ];
    }

    /**
     * Linear: equal weight across all unique (effort, campaign) touchpoint pairs.
     */
    protected function applyLinear(array &$results, $conversionId, $matches, string $timestampField): void
    {
        // Group by unique (effort_id, campaign_id) pairs
        $uniqueTouchpoints = $matches->unique(fn ($m) => $m->effort_id.'|'.$m->campaign_id);
        $weight = round(1.0 / $uniqueTouchpoints->count(), 4);

        foreach ($uniqueTouchpoints as $touchpoint) {
            $results[] = [
                'conversion_id' => $conversionId,
                'effort_id' => $touchpoint->effort_id,
                'campaign_type' => $touchpoint->campaign_type,
                'campaign_id' => $touchpoint->campaign_id,
                'weight' => $weight,
                'matched_at' => $touchpoint->$timestampField,
            ];
        }
    }
}
