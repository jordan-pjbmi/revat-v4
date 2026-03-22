<?php

namespace Database\Seeders;

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use Illuminate\Database\Seeder;

class DashboardTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $createdBy = User::first()?->id ?? 1;

        $templates = [
            [
                'dashboard' => [
                    'name' => 'Executive Overview',
                    'description' => 'High-level KPIs and trends for executive reporting.',
                    'is_template' => true,
                    'template_slug' => 'executive-overview',
                    'workspace_id' => null,
                    'created_by' => $createdBy,
                    'is_locked' => true,
                ],
                'widgets' => [
                    [
                        'widget_type' => 'stat_row',
                        'grid_x' => 0,
                        'grid_y' => 0,
                        'grid_w' => 12,
                        'grid_h' => 2,
                        'sort_order' => 0,
                        'config' => [
                            'data_source' => 'conversion_metrics',
                            'measures' => ['revenue', 'conversions', 'roas', 'cost'],
                            'display' => ['title' => 'Key Metrics'],
                        ],
                    ],
                    [
                        'widget_type' => 'line_chart',
                        'grid_x' => 0,
                        'grid_y' => 2,
                        'grid_w' => 8,
                        'grid_h' => 4,
                        'sort_order' => 1,
                        'config' => [
                            'data_source' => 'conversion_metrics',
                            'measure' => 'revenue',
                            'visualization' => ['chart_type' => 'line', 'show_legend' => true],
                            'display' => ['title' => 'Revenue Trend'],
                        ],
                    ],
                    [
                        'widget_type' => 'pie_chart',
                        'grid_x' => 8,
                        'grid_y' => 2,
                        'grid_w' => 4,
                        'grid_h' => 4,
                        'sort_order' => 2,
                        'config' => [
                            'data_source' => 'platform_breakdown',
                            'measure' => 'revenue',
                            'group_by' => 'platform',
                            'visualization' => ['chart_type' => 'pie', 'show_legend' => true],
                            'display' => ['title' => 'Revenue by Platform'],
                        ],
                    ],
                    [
                        'widget_type' => 'bar_chart',
                        'grid_x' => 0,
                        'grid_y' => 6,
                        'grid_w' => 6,
                        'grid_h' => 4,
                        'sort_order' => 3,
                        'config' => [
                            'data_source' => 'platform_breakdown',
                            'measure' => 'revenue',
                            'group_by' => 'platform',
                            'limit' => 10,
                            'visualization' => ['chart_type' => 'horizontal_bar'],
                            'display' => ['title' => 'Revenue by Platform'],
                        ],
                    ],
                    [
                        'widget_type' => 'single_metric',
                        'grid_x' => 6,
                        'grid_y' => 6,
                        'grid_w' => 3,
                        'grid_h' => 2,
                        'sort_order' => 4,
                        'config' => [
                            'data_source' => 'conversion_metrics',
                            'measure' => 'conversions',
                            'display' => ['title' => 'Total Conversions'],
                        ],
                    ],
                    [
                        'widget_type' => 'single_metric',
                        'grid_x' => 9,
                        'grid_y' => 6,
                        'grid_w' => 3,
                        'grid_h' => 2,
                        'sort_order' => 5,
                        'config' => [
                            'data_source' => 'conversion_metrics',
                            'measure' => 'roas',
                            'display' => ['title' => 'Average ROAS'],
                        ],
                    ],
                ],
            ],
            [
                'dashboard' => [
                    'name' => 'Campaign Manager',
                    'description' => 'Email campaign performance metrics and send volume analysis.',
                    'is_template' => true,
                    'template_slug' => 'campaign-manager',
                    'workspace_id' => null,
                    'created_by' => $createdBy,
                    'is_locked' => true,
                ],
                'widgets' => [
                    [
                        'widget_type' => 'stat_row',
                        'grid_x' => 0,
                        'grid_y' => 0,
                        'grid_w' => 12,
                        'grid_h' => 2,
                        'sort_order' => 0,
                        'config' => [
                            'data_source' => 'campaign_metrics',
                            'measures' => ['sent', 'opens', 'clicks', 'open_rate', 'click_rate'],
                            'display' => ['title' => 'Email Metrics'],
                        ],
                    ],
                    [
                        'widget_type' => 'line_chart',
                        'grid_x' => 0,
                        'grid_y' => 2,
                        'grid_w' => 6,
                        'grid_h' => 4,
                        'sort_order' => 1,
                        'config' => [
                            'data_source' => 'campaign_metrics',
                            'measure' => 'sent',
                            'visualization' => ['chart_type' => 'line'],
                            'display' => ['title' => 'Send Volume'],
                        ],
                    ],
                    [
                        'widget_type' => 'bar_chart',
                        'grid_x' => 6,
                        'grid_y' => 2,
                        'grid_w' => 6,
                        'grid_h' => 4,
                        'sort_order' => 2,
                        'config' => [
                            'data_source' => 'platform_breakdown',
                            'measure' => 'sent',
                            'group_by' => 'platform',
                            'limit' => 10,
                            'visualization' => ['chart_type' => 'bar'],
                            'display' => ['title' => 'Sends by Platform'],
                        ],
                    ],
                    [
                        'widget_type' => 'data_table',
                        'grid_x' => 0,
                        'grid_y' => 6,
                        'grid_w' => 12,
                        'grid_h' => 5,
                        'sort_order' => 3,
                        'config' => [
                            'data_source' => 'campaign_metrics',
                            'display' => ['title' => 'Campaign Breakdown'],
                        ],
                    ],
                    [
                        'widget_type' => 'pie_chart',
                        'grid_x' => 0,
                        'grid_y' => 11,
                        'grid_w' => 4,
                        'grid_h' => 4,
                        'sort_order' => 4,
                        'config' => [
                            'data_source' => 'platform_breakdown',
                            'measure' => 'sent',
                            'group_by' => 'platform',
                            'visualization' => ['chart_type' => 'pie', 'show_legend' => true],
                            'display' => ['title' => 'Sends by Platform'],
                        ],
                    ],
                ],
            ],
            [
                'dashboard' => [
                    'name' => 'Attribution Analyst',
                    'description' => 'Multi-touch attribution analysis across efforts and channels.',
                    'is_template' => true,
                    'template_slug' => 'attribution-analyst',
                    'workspace_id' => null,
                    'created_by' => $createdBy,
                    'is_locked' => true,
                ],
                'widgets' => [
                    [
                        'widget_type' => 'stat_row',
                        'grid_x' => 0,
                        'grid_y' => 0,
                        'grid_w' => 12,
                        'grid_h' => 2,
                        'sort_order' => 0,
                        'config' => [
                            'data_source' => 'attribution',
                            'measures' => ['attributed_conversions', 'attributed_revenue', 'weight'],
                            'display' => ['title' => 'Attribution Summary'],
                        ],
                    ],
                    [
                        'widget_type' => 'bar_chart',
                        'grid_x' => 0,
                        'grid_y' => 2,
                        'grid_w' => 6,
                        'grid_h' => 4,
                        'sort_order' => 1,
                        'config' => [
                            'data_source' => 'attribution',
                            'measure' => 'attributed_revenue',
                            'group_by' => 'effort_id',
                            'limit' => 10,
                            'visualization' => ['chart_type' => 'horizontal_bar'],
                            'display' => ['title' => 'First Touch Attribution'],
                            'extra' => ['attribution_model' => 'first_touch'],
                        ],
                    ],
                    [
                        'widget_type' => 'bar_chart',
                        'grid_x' => 6,
                        'grid_y' => 2,
                        'grid_w' => 6,
                        'grid_h' => 4,
                        'sort_order' => 2,
                        'config' => [
                            'data_source' => 'attribution',
                            'measure' => 'attributed_revenue',
                            'group_by' => 'effort_id',
                            'limit' => 10,
                            'visualization' => ['chart_type' => 'horizontal_bar'],
                            'display' => ['title' => 'Last Touch Attribution'],
                            'extra' => ['attribution_model' => 'last_touch'],
                        ],
                    ],
                    [
                        'widget_type' => 'data_table',
                        'grid_x' => 0,
                        'grid_y' => 6,
                        'grid_w' => 12,
                        'grid_h' => 5,
                        'sort_order' => 3,
                        'config' => [
                            'data_source' => 'attribution',
                            'display' => ['title' => 'Attribution Detail'],
                        ],
                    ],
                    [
                        'widget_type' => 'pie_chart',
                        'grid_x' => 0,
                        'grid_y' => 11,
                        'grid_w' => 4,
                        'grid_h' => 4,
                        'sort_order' => 4,
                        'config' => [
                            'data_source' => 'attribution',
                            'measure' => 'attributed_revenue',
                            'group_by' => 'effort_id',
                            'visualization' => ['chart_type' => 'donut', 'show_legend' => true],
                            'display' => ['title' => 'Attribution Distribution'],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($templates as $template) {
            $dashboard = Dashboard::firstOrCreate(
                ['template_slug' => $template['dashboard']['template_slug']],
                $template['dashboard'],
            );

            if ($dashboard->wasRecentlyCreated) {
                foreach ($template['widgets'] as $widgetData) {
                    DashboardWidget::create(array_merge(
                        ['dashboard_id' => $dashboard->id],
                        $widgetData,
                    ));
                }
            }
        }
    }
}
