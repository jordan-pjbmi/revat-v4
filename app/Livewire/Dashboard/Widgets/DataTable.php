<?php

namespace App\Livewire\Dashboard\Widgets;

use Illuminate\View\View;

class DataTable extends BaseWidget
{
    public string $sortBy = 'date';

    public string $sortDir = 'asc';

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    protected function fetchData(): array
    {
        $data = $this->getDataService()->fetchTable($this->config, $this->startDate, $this->endDate);

        // Apply client-side sort to rows
        $rows = collect($data['rows']);

        if ($this->sortBy === 'date') {
            $rows = $this->sortDir === 'asc'
                ? $rows->sortBy('date')
                : $rows->sortByDesc('date');
        } else {
            $rows = $this->sortDir === 'asc'
                ? $rows->sortBy($this->sortBy)
                : $rows->sortByDesc($this->sortBy);
        }

        $data['rows'] = $rows->values()->all();

        return $data;
    }

    public function render(): View
    {
        return view('livewire.dashboard.widgets.data-table', [
            'title' => $this->getTitle(),
            'subtitle' => $this->getSubtitle(),
            'tableData' => $this->fetchData(),
            'sortBy' => $this->sortBy,
            'sortDir' => $this->sortDir,
        ]);
    }
}
