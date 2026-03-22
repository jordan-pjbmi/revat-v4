<?php

namespace App\Livewire\Dashboard\Widgets;

use Illuminate\View\View;

class SingleMetric extends BaseWidget
{
    protected function fetchData(): array
    {
        return $this->getDataService()->fetchMetric($this->config, $this->startDate, $this->endDate);
    }

    public function render(): View
    {
        return view('livewire.dashboard.widgets.single-metric', [
            'title' => $this->getTitle(),
            'subtitle' => $this->getSubtitle(),
            'metric' => $this->fetchData(),
        ]);
    }
}
