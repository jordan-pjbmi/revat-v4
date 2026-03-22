<div>
    <div wire:ignore x-data="dashboardGrid(@js($widgets->map(fn ($w) => [
        'id' => $w->id,
        'x' => $w->grid_x,
        'y' => $w->grid_y,
        'w' => $w->grid_w,
        'h' => $w->grid_h,
        'minW' => ($widgetRegistry[$w->widget_type]['min_w'] ?? 2),
        'minH' => ($widgetRegistry[$w->widget_type]['min_h'] ?? 2),
        'maxW' => ($widgetRegistry[$w->widget_type]['max_w'] ?? 12),
        'maxH' => ($widgetRegistry[$w->widget_type]['max_h'] ?? 10),
    ])->toArray()), @js($editing))"
    @dashboard-enter-edit-mode.window="enterEditMode()"
    @dashboard-exit-edit-mode.window="exitEditMode()"
    @dashboard-cancel-edit-mode.window="cancelEdit()"
    class="grid-stack">
        @foreach($widgets as $widget)
            @php
                $reg = $widgetRegistry[$widget->widget_type] ?? null;
                $componentName = $reg['component'] ?? null;
            @endphp
            @if($componentName)
                <div class="grid-stack-item"
                     gs-id="{{ $widget->id }}"
                     gs-x="{{ $widget->grid_x }}"
                     gs-y="{{ $widget->grid_y }}"
                     gs-w="{{ $widget->grid_w }}"
                     gs-h="{{ $widget->grid_h }}"
                     gs-min-w="{{ $reg['min_w'] ?? 2 }}"
                     gs-min-h="{{ $reg['min_h'] ?? 2 }}"
                     gs-max-w="{{ $reg['max_w'] ?? 12 }}"
                     gs-max-h="{{ $reg['max_h'] ?? 10 }}">
                    <div class="grid-stack-item-content">
                        <div class="relative h-full group">
                            {{-- Edit mode controls --}}
                            <div x-show="editing" x-cloak class="absolute top-2 right-2 z-20 flex gap-1">
                                <button class="p-1 bg-white dark:bg-slate-700 rounded shadow text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
                                        @click="$dispatch('open-widget-config', { widgetId: {{ $widget->id }} })">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                </button>
                                <button class="p-1 bg-white dark:bg-slate-700 rounded shadow text-red-400 hover:text-red-600"
                                        @click="removeWidget($el.closest('.grid-stack-item'))">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                </button>
                            </div>

                            <livewire:dynamic-component
                                :is="$componentName"
                                :widget-id="$widget->id"
                                :config="$widget->config"
                                :key="'widget-'.$widget->id"
                            />
                        </div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</div>
