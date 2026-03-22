<?php

use App\Dashboard\DataSourceRegistry;

it('returns all data sources', function () {
    $sources = DataSourceRegistry::all();
    expect($sources)->toHaveKeys(['campaign_metrics', 'conversion_metrics', 'attribution', 'platform_breakdown']);
});

it('returns measures for a data source', function () {
    $measures = DataSourceRegistry::measuresFor('conversion_metrics');
    expect($measures)->toHaveKeys(['conversions', 'revenue', 'cost', 'roas']);
    expect($measures['revenue']['format'])->toBe('currency');
});

it('returns dimensions for a data source', function () {
    $dimensions = DataSourceRegistry::dimensionsFor('campaign_metrics');
    expect($dimensions)->toBe(['platform', 'campaign']);
});

it('all data sources reference valid model classes', function () {
    foreach (DataSourceRegistry::all() as $key => $source) {
        expect(class_exists($source['summary_model']))
            ->toBeTrue("Data source '{$key}' references non-existent model '{$source['summary_model']}'");
    }
});

it('all measures have label and format', function () {
    foreach (DataSourceRegistry::all() as $key => $source) {
        foreach ($source['measures'] as $measure => $meta) {
            expect(array_key_exists('label', $meta) && array_key_exists('format', $meta))
                ->toBeTrue("Measure '{$measure}' in source '{$key}' missing required keys");
        }
    }
});
