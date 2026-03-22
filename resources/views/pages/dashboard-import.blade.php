<x-layouts.app>
    <x-slot:title>Import Dashboard</x-slot:title>

    <div class="max-w-xl mx-auto py-12 px-4">
        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-8 shadow-sm">

            {{-- Header --}}
            <div class="flex items-start gap-4 mb-6">
                <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center">
                    <flux:icon.squares-2x2 class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                </div>
                <div class="flex-1 min-w-0">
                    <h1 class="text-xl font-bold text-slate-900 dark:text-white leading-tight">{{ $export->name }}</h1>
                    @if ($export->description)
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">{{ $export->description }}</p>
                    @endif
                </div>
            </div>

            {{-- Widget summary --}}
            @php
                $widgetTypeLabels = [
                    'single_metric' => 'Metric',
                    'line_chart'    => 'Line Chart',
                    'bar_chart'     => 'Bar Chart',
                    'pie_chart'     => 'Pie Chart',
                    'table'         => 'Table',
                    'stat_row'      => 'Stat Row',
                    'funnel'        => 'Funnel',
                ];

                $typeCounts = collect($export->layout)
                    ->groupBy('widget_type')
                    ->map(fn ($items, $type) => [
                        'label' => $widgetTypeLabels[$type] ?? ucwords(str_replace('_', ' ', $type)),
                        'count' => $items->count(),
                    ])
                    ->values();
            @endphp

            <div class="bg-slate-50 dark:bg-slate-900/50 rounded-lg p-4 mb-6">
                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">
                    {{ $export->widget_count }} {{ Str::plural('widget', $export->widget_count) }}
                </p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($typeCounts as $item)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-300">
                            <span class="font-medium">{{ $item['count'] }}</span>
                            {{ Str::plural($item['label'], $item['count']) }}
                        </span>
                    @endforeach
                </div>
            </div>

            {{-- Action --}}
            @auth
                @can('integrate')
                    <form method="POST" action="{{ route('dashboard.import', $export->token) }}">
                        @csrf
                        <flux:button type="submit" variant="primary" class="w-full">
                            Import to My Workspace
                        </flux:button>
                    </form>
                @else
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        You need the integrate permission to import dashboards.
                    </p>
                @endcan
            @else
                <p class="text-sm text-slate-600 dark:text-slate-400">
                    <a href="{{ route('login') }}" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">Log in</a>
                    to import this dashboard to your workspace.
                </p>
            @endauth

        </div>
    </div>
</x-layouts.app>
