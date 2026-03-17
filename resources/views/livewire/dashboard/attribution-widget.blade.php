<div>
    <div class="flex justify-between items-center mb-4">
        <span class="text-[15px] font-semibold text-slate-800 dark:text-slate-200">Attribution</span>
        <flux:select wire:model.live="model" size="sm" class="w-36">
            <flux:select.option value="first_touch">First Touch</flux:select.option>
            <flux:select.option value="last_touch">Last Touch</flux:select.option>
            <flux:select.option value="linear">Linear</flux:select.option>
        </flux:select>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
        <div class="bg-slate-50 dark:bg-slate-900 rounded-lg px-4 py-3">
            <div class="text-[11px] font-medium uppercase tracking-[0.4px] text-slate-500 dark:text-slate-400 mb-1">Attributed Conversions</div>
            <div class="text-lg font-bold font-mono tabular-nums">{{ number_format($summary['attributed_conversions'] ?? 0) }}</div>
        </div>
        <div class="bg-slate-50 dark:bg-slate-900 rounded-lg px-4 py-3">
            <div class="text-[11px] font-medium uppercase tracking-[0.4px] text-slate-500 dark:text-slate-400 mb-1">Attributed Revenue</div>
            <div class="text-lg font-bold font-mono tabular-nums">${{ number_format($summary['attributed_revenue'] ?? 0, 2) }}</div>
        </div>
        <div class="bg-slate-50 dark:bg-slate-900 rounded-lg px-4 py-3">
            <div class="text-[11px] font-medium uppercase tracking-[0.4px] text-slate-500 dark:text-slate-400 mb-1">Total Weight</div>
            <div class="text-lg font-bold font-mono tabular-nums">{{ number_format($summary['total_weight'] ?? 0, 2) }}</div>
        </div>
    </div>

    @if (count($topEfforts) > 0)
        <div class="text-[12px] font-semibold uppercase tracking-[0.4px] text-slate-500 dark:text-slate-400 mb-2">Top Efforts</div>
        <div class="space-y-2">
            @foreach ($topEfforts as $effort)
                <div class="flex justify-between items-center text-sm">
                    <span class="text-slate-700 dark:text-slate-300 truncate max-w-[200px]">{{ $effort['effort_name'] }}</span>
                    <span class="font-mono text-xs text-slate-600 dark:text-slate-400">${{ number_format($effort['attributed_revenue'], 2) }}</span>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-4">
            <p class="text-sm text-slate-400">No attribution data available</p>
        </div>
    @endif
</div>
