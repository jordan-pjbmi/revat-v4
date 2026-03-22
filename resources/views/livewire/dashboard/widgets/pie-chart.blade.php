<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-5 h-full flex flex-col"
    x-data="chartWidget()"
    x-init="$nextTick(() => initChart($refs.canvas, @js($chartData), @js($chartType), false))"
    @widget-resized.window="resize()">

    {{-- Header --}}
    @if ($title)
        <div class="mb-3 shrink-0">
            <span class="text-[11.5px] font-medium uppercase tracking-[0.5px] text-slate-500 dark:text-slate-400">{{ $title }}</span>
            @if ($subtitle)
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ $subtitle }}</p>
            @endif
        </div>
    @endif

    {{-- Canvas --}}
    <div class="flex-1 min-h-0 relative">
        <canvas x-ref="canvas" class="w-full h-full"></canvas>
    </div>
</div>
