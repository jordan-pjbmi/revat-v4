<?php

namespace App\Livewire\Dashboard\Widgets;

use Illuminate\View\View;

class StatRow extends BaseWidget
{
    protected function fetchData(): array
    {
        $measures = $this->config['measures'] ?? [];
        $metrics = [];

        foreach ($measures as $measure) {
            $measureConfig = array_merge($this->config, ['measure' => $measure]);
            $metrics[$measure] = $this->getDataService()->fetchMetric($measureConfig, $this->startDate, $this->endDate);
        }

        return $metrics;
    }

    public function render(): View
    {
        return view('livewire.dashboard.widgets.stat-row', [
            'title' => $this->getTitle(),
            'subtitle' => $this->getSubtitle(),
            'metrics' => $this->fetchData(),
        ]);
    }
}
