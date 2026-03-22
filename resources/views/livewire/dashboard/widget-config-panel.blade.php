<div>
    <div x-show="$wire.open" x-cloak class="fixed inset-0 z-50 overflow-hidden" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        {{-- Backdrop --}}
        <div class="absolute inset-0 bg-black/30" @click="$wire.close()"></div>

        {{-- Panel --}}
        <div class="absolute inset-y-0 right-0 w-full max-w-md bg-white dark:bg-slate-800 shadow-xl overflow-y-auto"
             x-transition:enter="transition ease-out duration-300 transform"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200 transform"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full">

            {{-- Header --}}
            <div class="sticky top-0 z-10 p-4 border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 flex justify-between items-center">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">
                    @if ($editingWidgetId)
                        Edit Widget
                    @elseif ($mode === 'catalog')
                        Add Widget
                    @else
                        Configure Widget
                    @endif
                </h2>
                <button @click="$wire.close()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Content --}}
            <div class="p-4">
                @if ($mode === 'catalog')
                    {{-- Search --}}
                    <input
                        type="text"
                        wire:model.live="searchQuery"
                        placeholder="Search widgets..."
                        class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 mb-4"
                    />

                    {{-- Categorized widget list --}}
                    @forelse ($this->availableWidgetTypes as $category => $widgets)
                        <div class="mb-6">
                            <h3 class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">{{ $category }}</h3>
                            @foreach ($widgets as $type => $widget)
                                <button
                                    wire:click="selectWidgetType('{{ $type }}')"
                                    class="w-full text-left border border-slate-200 dark:border-slate-700 rounded-lg p-3 mb-2 hover:border-indigo-500 dark:hover:border-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-colors"
                                >
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 flex items-center justify-center text-slate-500 dark:text-slate-400">
                                            @switch($widget['icon'])
                                                @case('hashtag')
                                                    <flux:icon.hashtag class="w-5 h-5" />
                                                    @break
                                                @case('squares-2x2')
                                                    <flux:icon.squares-2x2 class="w-5 h-5" />
                                                    @break
                                                @case('trending-up')
                                                    <flux:icon.arrow-trending-up class="w-5 h-5" />
                                                    @break
                                                @case('chart-bar')
                                                    <flux:icon.chart-bar class="w-5 h-5" />
                                                    @break
                                                @case('chart-pie')
                                                    <flux:icon.chart-pie class="w-5 h-5" />
                                                    @break
                                                @case('table')
                                                    <flux:icon.table-cells class="w-5 h-5" />
                                                    @break
                                                @default
                                                    <flux:icon.squares-plus class="w-5 h-5" />
                                            @endswitch
                                        </div>
                                        <div>
                                            <div class="font-medium text-sm text-slate-900 dark:text-white">{{ $widget['name'] }}</div>
                                            <div class="text-xs text-slate-500 dark:text-slate-400">{{ $widget['description'] }}</div>
                                        </div>
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @empty
                        <div class="text-center py-8 text-slate-400 dark:text-slate-500 text-sm">
                            No widgets match your search.
                        </div>
                    @endforelse

                    {{-- Advanced toggle --}}
                    <div class="border-t border-slate-200 dark:border-slate-700 pt-4 mt-2">
                        <button
                            wire:click="$set('mode', 'config')"
                            class="text-sm text-indigo-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                        >
                            ▸ Advanced Configuration
                        </button>
                    </div>
                @else
                    {{-- Config form --}}

                    {{-- Back to catalog --}}
                    @if (!$editingWidgetId)
                        <button
                            wire:click="$set('mode', 'catalog')"
                            class="text-sm text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 mb-4 flex items-center gap-1 transition-colors"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                            Back to catalog
                        </button>
                    @endif

                    {{-- Display section --}}
                    <div class="mb-6">
                        <h3 class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Display</h3>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Title</label>
                                <input
                                    type="text"
                                    wire:model="title"
                                    class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Subtitle <span class="text-slate-400">(optional)</span></label>
                                <input
                                    type="text"
                                    wire:model="subtitle"
                                    class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                />
                            </div>
                        </div>
                    </div>

                    {{-- Data Source section --}}
                    <div class="mb-6">
                        <h3 class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Data Source</h3>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Source</label>
                                <select
                                    wire:model.live="dataSource"
                                    class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                >
                                    <option value="">Select...</option>
                                    @foreach ($this->availableDataSources as $key => $source)
                                        <option value="{{ $key }}">{{ $source['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            @if ($dataSource)
                                <div>
                                    <label class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Measure</label>
                                    <select
                                        wire:model="measure"
                                        class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <option value="">Select...</option>
                                        @foreach ($this->availableMeasures as $key => $meta)
                                            <option value="{{ $key }}">{{ $meta['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Group By</label>
                                    <select
                                        wire:model="groupBy"
                                        class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <option value="">None</option>
                                        @foreach ($this->availableDimensions as $dim)
                                            <option value="{{ $dim }}">{{ ucfirst($dim) }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Limit</label>
                                    <input
                                        type="number"
                                        wire:model="limit"
                                        min="1"
                                        max="100"
                                        class="w-20 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    />
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Visualization section (for chart types) --}}
                    @if (in_array($widgetType, ['bar_chart', 'line_chart', 'pie_chart']))
                        <div class="mb-6">
                            <h3 class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Visualization</h3>

                            {{-- Chart type buttons based on widget type --}}
                            <div class="flex gap-2 flex-wrap mb-3">
                                @if ($widgetType === 'bar_chart')
                                    @foreach (['bar' => 'Bar', 'horizontal_bar' => 'Horizontal', 'stacked_bar' => 'Stacked'] as $chartTypeKey => $chartTypeLabel)
                                        <button
                                            wire:click="$set('chartType', '{{ $chartTypeKey }}')"
                                            class="px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors {{ $chartType === $chartTypeKey ? 'bg-indigo-500 text-white border-indigo-500' : 'border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:border-indigo-400' }}"
                                        >
                                            {{ $chartTypeLabel }}
                                        </button>
                                    @endforeach
                                @elseif ($widgetType === 'line_chart')
                                    @foreach (['line' => 'Line', 'area_chart' => 'Area'] as $chartTypeKey => $chartTypeLabel)
                                        <button
                                            wire:click="$set('chartType', '{{ $chartTypeKey }}')"
                                            class="px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors {{ $chartType === $chartTypeKey ? 'bg-indigo-500 text-white border-indigo-500' : 'border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:border-indigo-400' }}"
                                        >
                                            {{ $chartTypeLabel }}
                                        </button>
                                    @endforeach
                                @elseif ($widgetType === 'pie_chart')
                                    @foreach (['pie' => 'Pie', 'donut' => 'Donut'] as $chartTypeKey => $chartTypeLabel)
                                        <button
                                            wire:click="$set('chartType', '{{ $chartTypeKey }}')"
                                            class="px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors {{ $chartType === $chartTypeKey ? 'bg-indigo-500 text-white border-indigo-500' : 'border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:border-indigo-400' }}"
                                        >
                                            {{ $chartTypeLabel }}
                                        </button>
                                    @endforeach
                                @endif
                            </div>

                            <div class="flex gap-4">
                                <label class="text-sm flex items-center gap-1.5 text-slate-700 dark:text-slate-300 cursor-pointer">
                                    <input type="checkbox" wire:model="showLabels" class="rounded border-slate-300 text-indigo-500 focus:ring-indigo-500" />
                                    Labels
                                </label>
                                <label class="text-sm flex items-center gap-1.5 text-slate-700 dark:text-slate-300 cursor-pointer">
                                    <input type="checkbox" wire:model="showLegend" class="rounded border-slate-300 text-indigo-500 focus:ring-indigo-500" />
                                    Legend
                                </label>
                            </div>
                        </div>
                    @endif

                    {{-- Filters section --}}
                    <div class="mb-6">
                        <h3 class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Filters</h3>

                        @foreach ($filters as $i => $filter)
                            <div class="flex items-center gap-2 mb-2">
                                <select
                                    wire:model="filters.{{ $i }}.dimension"
                                    class="flex-1 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 py-1 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                >
                                    <option value="">Dimension</option>
                                    @foreach ($this->availableDimensions as $dim)
                                        <option value="{{ $dim }}">{{ ucfirst($dim) }}</option>
                                    @endforeach
                                </select>

                                <select
                                    wire:model="filters.{{ $i }}.operator"
                                    class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 py-1 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                >
                                    <option value="in">in</option>
                                    <option value="not_in">not in</option>
                                    <option value="equals">equals</option>
                                </select>

                                <input
                                    type="text"
                                    wire:model="filters.{{ $i }}.values"
                                    placeholder="val1, val2"
                                    class="flex-1 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 py-1 text-sm text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                />

                                <button
                                    wire:click="removeFilter({{ $i }})"
                                    class="text-red-400 hover:text-red-600 transition-colors"
                                    aria-label="Remove filter"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        @endforeach

                        <button
                            wire:click="addFilter"
                            class="text-sm text-indigo-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                        >
                            + Add Filter
                        </button>
                    </div>

                    {{-- Date Range section --}}
                    <div class="mb-6">
                        <h3 class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Date Range</h3>
                        <div class="flex gap-2">
                            <button
                                wire:click="$set('useGlobalDate', true)"
                                class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ $useGlobalDate ? 'bg-indigo-500 text-white' : 'border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:border-indigo-400' }}"
                            >
                                Use Global
                            </button>
                            <button
                                wire:click="$set('useGlobalDate', false)"
                                class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ !$useGlobalDate ? 'bg-indigo-500 text-white' : 'border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:border-indigo-400' }}"
                            >
                                Fixed Range
                            </button>
                        </div>

                        @if (!$useGlobalDate)
                            <div class="flex gap-2 mt-3">
                                <input
                                    type="date"
                                    wire:model="fixedDateStart"
                                    class="flex-1 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 py-1 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                />
                                <input
                                    type="date"
                                    wire:model="fixedDateEnd"
                                    class="flex-1 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 py-1 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                />
                            </div>
                        @endif
                    </div>

                    {{-- Action buttons --}}
                    <div class="border-t border-slate-200 dark:border-slate-700 pt-4 flex gap-3">
                        <button
                            wire:click="save"
                            class="flex-1 bg-indigo-500 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-indigo-600 transition-colors"
                        >
                            {{ $editingWidgetId ? 'Update Widget' : 'Add to Dashboard' }}
                        </button>
                        <button
                            wire:click="close"
                            class="px-4 py-2 rounded-lg text-sm text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors"
                        >
                            Cancel
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
