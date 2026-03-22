<?php

use App\Dashboard\WidgetRegistry;

it('returns all widget types', function () {
    $widgets = WidgetRegistry::all();
    expect($widgets)->toHaveKeys(['single_metric', 'stat_row', 'line_chart', 'bar_chart', 'pie_chart', 'table']);
});

it('returns null for unknown widget type', function () {
    expect(WidgetRegistry::get('nonexistent'))->toBeNull();
    expect(WidgetRegistry::exists('nonexistent'))->toBeFalse();
});

it('groups widgets by category', function () {
    $grouped = WidgetRegistry::byCategory();
    expect($grouped)->toHaveKeys(['Key Metrics', 'Charts', 'Data']);
    expect($grouped['Key Metrics'])->toHaveKeys(['single_metric', 'stat_row']);
    expect($grouped['Charts'])->toHaveKeys(['line_chart', 'bar_chart', 'pie_chart']);
    expect($grouped['Data'])->toHaveKeys(['table']);
});

it('returns default grid dimensions for a widget type', function () {
    $defaults = WidgetRegistry::defaultsFor('single_metric');
    expect($defaults['w'])->toBe(3);
    expect($defaults['h'])->toBe(2);
    expect($defaults['min_w'])->toBe(2);
});

it('all widget types have required keys', function () {
    $requiredKeys = ['name', 'description', 'icon', 'category', 'default_w', 'default_h', 'min_w', 'min_h', 'max_w', 'max_h', 'component', 'supported_visualizations'];
    foreach (WidgetRegistry::all() as $type => $widget) {
        foreach ($requiredKeys as $key) {
            expect(array_key_exists($key, $widget))->toBeTrue("Widget '{$type}' missing key '{$key}'");
        }
    }
});
