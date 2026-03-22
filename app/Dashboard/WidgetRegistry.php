<?php

namespace App\Dashboard;

class WidgetRegistry
{
    protected static array $widgets = [
        'single_metric' => [
            'name' => 'Single Metric',
            'description' => 'Big number with trend indicator',
            'icon' => 'hashtag',
            'category' => 'Key Metrics',
            'default_w' => 3, 'default_h' => 2,
            'min_w' => 2, 'min_h' => 2, 'max_w' => 6, 'max_h' => 3,
            'component' => 'dashboard.widgets.single-metric',
            'supported_visualizations' => ['single_metric'],
        ],
        'stat_row' => [
            'name' => 'Stat Row',
            'description' => 'Row of 3-5 key metrics with trends',
            'icon' => 'squares-2x2',
            'category' => 'Key Metrics',
            'default_w' => 12, 'default_h' => 2,
            'min_w' => 6, 'min_h' => 2, 'max_w' => 12, 'max_h' => 3,
            'component' => 'dashboard.widgets.stat-row',
            'supported_visualizations' => ['stat_row'],
        ],
        'line_chart' => [
            'name' => 'Line Chart',
            'description' => 'Trend over time',
            'icon' => 'trending-up',
            'category' => 'Charts',
            'default_w' => 6, 'default_h' => 4,
            'min_w' => 4, 'min_h' => 3, 'max_w' => 12, 'max_h' => 8,
            'component' => 'dashboard.widgets.line-chart',
            'supported_visualizations' => ['line_chart', 'area_chart'],
        ],
        'bar_chart' => [
            'name' => 'Bar Chart',
            'description' => 'Compare values across categories',
            'icon' => 'chart-bar',
            'category' => 'Charts',
            'default_w' => 6, 'default_h' => 4,
            'min_w' => 3, 'min_h' => 3, 'max_w' => 12, 'max_h' => 8,
            'component' => 'dashboard.widgets.bar-chart',
            'supported_visualizations' => ['bar_chart', 'horizontal_bar', 'stacked_bar'],
        ],
        'pie_chart' => [
            'name' => 'Pie / Donut Chart',
            'description' => 'Show proportions',
            'icon' => 'chart-pie',
            'category' => 'Charts',
            'default_w' => 4, 'default_h' => 4,
            'min_w' => 3, 'min_h' => 3, 'max_w' => 6, 'max_h' => 6,
            'component' => 'dashboard.widgets.pie-chart',
            'supported_visualizations' => ['pie_chart', 'donut_chart'],
        ],
        'table' => [
            'name' => 'Data Table',
            'description' => 'Sortable tabular breakdown',
            'icon' => 'table',
            'category' => 'Data',
            'default_w' => 6, 'default_h' => 5,
            'min_w' => 4, 'min_h' => 3, 'max_w' => 12, 'max_h' => 10,
            'component' => 'dashboard.widgets.data-table',
            'supported_visualizations' => ['table'],
        ],
    ];

    public static function all(): array
    {
        return static::$widgets;
    }

    public static function get(string $type): ?array
    {
        return static::$widgets[$type] ?? null;
    }

    public static function exists(string $type): bool
    {
        return isset(static::$widgets[$type]);
    }

    public static function types(): array
    {
        return array_keys(static::$widgets);
    }

    public static function byCategory(): array
    {
        $grouped = [];
        foreach (static::$widgets as $type => $widget) {
            $grouped[$widget['category']][$type] = $widget;
        }

        return $grouped;
    }

    public static function defaultsFor(string $type): array
    {
        $widget = static::get($type);
        if (! $widget) {
            return [];
        }

        return [
            'w' => $widget['default_w'],
            'h' => $widget['default_h'],
            'min_w' => $widget['min_w'],
            'min_h' => $widget['min_h'],
            'max_w' => $widget['max_w'],
            'max_h' => $widget['max_h'],
        ];
    }
}
