<?php

namespace App\Livewire\Dashboard\Widgets;

use Illuminate\View\View;

class PieChart extends BaseWidget
{
    protected function fetchData(): array
    {
        return $this->getDataService()->fetchGrouped($this->config, $this->startDate, $this->endDate);
    }

    public function render(): View
    {
        $variant = $this->config['display']['variant'] ?? 'pie';

        return view('livewire.dashboard.widgets.pie-chart', [
            'title' => $this->getTitle(),
            'subtitle' => $this->getSubtitle(),
            'chartData' => $this->fetchData(),
            'chartType' => $variant === 'donut' ? 'doughnut' : 'pie',
            'variant' => $variant,
        ]);
    }
}
