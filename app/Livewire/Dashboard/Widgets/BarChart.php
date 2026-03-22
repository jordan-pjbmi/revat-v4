<?php

namespace App\Livewire\Dashboard\Widgets;

use Illuminate\View\View;

class BarChart extends BaseWidget
{
    protected function fetchData(): array
    {
        return $this->getDataService()->fetchGrouped($this->config, $this->startDate, $this->endDate);
    }

    public function render(): View
    {
        $variant = $this->config['display']['variant'] ?? 'vertical';

        return view('livewire.dashboard.widgets.bar-chart', [
            'title' => $this->getTitle(),
            'subtitle' => $this->getSubtitle(),
            'chartData' => $this->fetchData(),
            'chartType' => 'bar',
            'variant' => $variant,
        ]);
    }
}
