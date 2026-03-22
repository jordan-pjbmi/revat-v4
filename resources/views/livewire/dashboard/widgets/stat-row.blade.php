<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-5 h-full">
    {{-- Header --}}
    @if ($title)
        <div class="mb-3">
            <span class="text-[11.5px] font-medium uppercase tracking-[0.5px] text-slate-500 dark:text-slate-400">{{ $title }}</span>
            @if ($subtitle)
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ $subtitle }}</p>
            @endif
        </div>
    @endif

    {{-- Metrics grid --}}
    <div style="display: grid; grid-template-columns: repeat({{ count($metrics) }}, minmax(0, 1fr)); gap: 1rem;">
        @foreach ($metrics as $key => $metric)
            <div class="border-r border-slate-100 dark:border-slate-700 last:border-r-0 pr-4 last:pr-0">
                <div class="text-[11px] font-medium uppercase tracking-[0.5px] text-slate-500 dark:text-slate-400 mb-1 truncate">
                    {{ $metric['label'] }}
                </div>
                <div class="text-xl font-bold font-mono tabular-nums mb-1">
                    @if ($metric['format'] === 'currency')
                        ${{ number_format($metric['value'], 2) }}
                    @elseif ($metric['format'] === 'percent')
                        {{ number_format($metric['value'], 1) }}%
                    @else
                        {{ number_format($metric['value']) }}
                    @endif
                </div>
                @if ($metric['change'] > 0)
                    <span class="text-[11px] font-medium text-green-600">&uarr; {{ number_format(abs($metric['change']), 1) }}%</span>
                @elseif ($metric['change'] < 0)
                    <span class="text-[11px] font-medium text-red-600">&darr; {{ number_format(abs($metric['change']), 1) }}%</span>
                @else
                    <span class="text-[11px] font-medium text-slate-400">0.0%</span>
                @endif
            </div>
        @endforeach
    </div>
</div>
