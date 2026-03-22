<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-5 h-full flex flex-col">
    {{-- Header --}}
    @if ($title)
        <div class="mb-3 shrink-0">
            <span class="text-[11.5px] font-medium uppercase tracking-[0.5px] text-slate-500 dark:text-slate-400">{{ $title }}</span>
            @if ($subtitle)
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ $subtitle }}</p>
            @endif
        </div>
    @endif

    {{-- Table --}}
    <div class="flex-1 min-h-0 overflow-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100 dark:border-slate-700">
                    {{-- Date column --}}
                    <th class="py-2 pr-4 text-left">
                        <button
                            wire:click="sort('date')"
                            class="flex items-center gap-1 text-[11px] font-medium uppercase tracking-[0.5px] text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">
                            Date
                            @if ($sortBy === 'date')
                                <span class="text-slate-400">{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </button>
                    </th>

                    {{-- Measure columns --}}
                    @foreach ($tableData['columns'] as $col)
                        <th class="py-2 px-4 text-right">
                            <button
                                wire:click="sort('{{ $col['key'] }}')"
                                class="flex items-center gap-1 ml-auto text-[11px] font-medium uppercase tracking-[0.5px] text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">
                                {{ $col['label'] }}
                                @if ($sortBy === $col['key'])
                                    <span class="text-slate-400">{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </button>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50 dark:divide-slate-700/50">
                @forelse ($tableData['rows'] as $row)
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors">
                        <td class="py-2 pr-4 text-slate-600 dark:text-slate-300 font-mono text-xs tabular-nums">
                            {{ $row['date'] }}
                        </td>
                        @foreach ($tableData['columns'] as $col)
                            <td class="py-2 px-4 text-right font-mono text-xs tabular-nums text-slate-700 dark:text-slate-200">
                                @if ($col['format'] === 'currency')
                                    ${{ number_format($row[$col['key']], 2) }}
                                @elseif ($col['format'] === 'percent')
                                    {{ number_format($row[$col['key']], 1) }}%
                                @else
                                    {{ number_format($row[$col['key']]) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($tableData['columns']) + 1 }}" class="py-8 text-center text-sm text-slate-400 dark:text-slate-500">
                            No data for this period.
                        </td>
                    </tr>
                @endforelse
            </tbody>

            {{-- Totals row --}}
            @if (! empty($tableData['rows']))
                <tfoot>
                    <tr class="border-t border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700/30">
                        <td class="py-2 pr-4 text-[11px] font-semibold uppercase tracking-[0.5px] text-slate-500 dark:text-slate-400">
                            Totals
                        </td>
                        @foreach ($tableData['columns'] as $col)
                            <td class="py-2 px-4 text-right font-mono text-xs font-semibold tabular-nums text-slate-700 dark:text-slate-200">
                                @if ($col['format'] === 'currency')
                                    ${{ number_format($tableData['totals'][$col['key']], 2) }}
                                @elseif ($col['format'] === 'percent')
                                    {{ number_format($tableData['totals'][$col['key']], 1) }}%
                                @else
                                    {{ number_format($tableData['totals'][$col['key']]) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
