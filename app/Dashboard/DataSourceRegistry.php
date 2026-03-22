<?php

namespace App\Dashboard;

use App\Models\SummaryAttributionByEffort;
use App\Models\SummaryCampaignByPlatform;
use App\Models\SummaryCampaignDaily;
use App\Models\SummaryConversionDaily;

class DataSourceRegistry
{
    protected static array $sources = [
        'campaign_metrics' => [
            'name' => 'Campaign Metrics',
            'description' => 'Email send, open, and click performance',
            'summary_model' => SummaryCampaignDaily::class,
            'measures' => [
                'sent' => ['label' => 'Total Sent', 'format' => 'number'],
                'opens' => ['label' => 'Opens', 'format' => 'number'],
                'clicks' => ['label' => 'Clicks', 'format' => 'number'],
                'open_rate' => ['label' => 'Open Rate', 'format' => 'percent', 'computed' => true],
                'click_rate' => ['label' => 'Click Rate', 'format' => 'percent', 'computed' => true],
            ],
            'dimensions' => ['platform', 'campaign'],
            'supports_trend' => true,
        ],
        'conversion_metrics' => [
            'name' => 'Conversion Metrics',
            'description' => 'Conversions, revenue, and cost',
            'summary_model' => SummaryConversionDaily::class,
            'measures' => [
                'conversions' => ['label' => 'Conversions', 'format' => 'number'],
                'revenue' => ['label' => 'Revenue', 'format' => 'currency'],
                'cost' => ['label' => 'Cost', 'format' => 'currency'],
                'roas' => ['label' => 'ROAS', 'format' => 'decimal', 'computed' => true],
            ],
            'dimensions' => ['platform', 'campaign'],
            'supports_trend' => true,
        ],
        'attribution' => [
            'name' => 'Attribution',
            'description' => 'Attribution model results by effort',
            'summary_model' => SummaryAttributionByEffort::class,
            'measures' => [
                'attributed_conversions' => ['label' => 'Attributed Conversions', 'format' => 'number'],
                'attributed_revenue' => ['label' => 'Attributed Revenue', 'format' => 'currency'],
                'weight' => ['label' => 'Weight', 'format' => 'decimal'],
            ],
            'dimensions' => ['effort', 'model'],
            'supports_trend' => true,
            'extra_config' => [
                'attribution_model' => [
                    'type' => 'select',
                    'options' => ['first_touch', 'last_touch', 'linear'],
                    'default' => 'first_touch',
                ],
            ],
        ],
        'platform_breakdown' => [
            'name' => 'Platform Breakdown',
            'description' => 'Metrics split by platform',
            'summary_model' => SummaryCampaignByPlatform::class,
            'measures' => [
                'sent' => ['label' => 'Total Sent', 'format' => 'number'],
                'opens' => ['label' => 'Opens', 'format' => 'number'],
                'clicks' => ['label' => 'Clicks', 'format' => 'number'],
                'revenue' => ['label' => 'Revenue', 'format' => 'currency'],
            ],
            'dimensions' => ['platform'],
            'supports_trend' => true,
        ],
    ];

    public static function all(): array
    {
        return static::$sources;
    }

    public static function get(string $key): ?array
    {
        return static::$sources[$key] ?? null;
    }

    public static function exists(string $key): bool
    {
        return isset(static::$sources[$key]);
    }

    public static function keys(): array
    {
        return array_keys(static::$sources);
    }

    public static function measuresFor(string $key): array
    {
        $source = static::get($key);

        return $source ? $source['measures'] : [];
    }

    public static function dimensionsFor(string $key): array
    {
        $source = static::get($key);

        return $source ? $source['dimensions'] : [];
    }
}
