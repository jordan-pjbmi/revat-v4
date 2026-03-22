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

    {{-- Value --}}
    <div class="text-[32px] font-bold font-mono tabular-nums leading-none mb-2">
        @if ($metric['format'] === 'currency')
            ${{ number_format($metric['value'], 2) }}
        @elseif ($metric['format'] === 'percent')
            {{ number_format($metric['value'], 1) }}%
        @else
            {{ number_format($metric['value']) }}
        @endif
    </div>

    {{-- Trend --}}
    @if ($metric['change'] > 0)
        <span class="text-xs font-medium text-green-600">&uarr; {{ number_format(abs($metric['change']), 1) }}% vs prev</span>
    @elseif ($metric['change'] < 0)
        <span class="text-xs font-medium text-red-600">&darr; {{ number_format(abs($metric['change']), 1) }}% vs prev</span>
    @else
        <span class="text-xs font-medium text-slate-400">0.0% vs prev</span>
    @endif
</div>
