<?php

namespace App\Livewire\Dashboard\Widgets;

use Illuminate\View\View;

class LineChart extends BaseWidget
{
    protected function fetchData(): array
    {
        return $this->getDataService()->fetchTrend($this->config, $this->startDate, $this->endDate);
    }

    public function render(): View
    {
        return view('livewire.dashboard.widgets.line-chart', [
            'title' => $this->getTitle(),
            'subtitle' => $this->getSubtitle(),
            'chartData' => $this->fetchData(),
            'chartType' => 'line',
        ]);
    }
}
