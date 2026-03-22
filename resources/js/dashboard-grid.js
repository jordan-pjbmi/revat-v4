import Chart from 'chart.js/auto';
import { GridStack } from 'gridstack';

document.addEventListener('alpine:init', () => {
    Alpine.data('chartWidget', () => ({
        chart: null,

        initChart(canvas, data, type, showLegend, options = {}) {
            if (!canvas || !data) return;

            const config = {
                type: type === 'horizontal_bar' ? 'bar' : type,
                data: {
                    labels: data.labels || [],
                    datasets: (data.datasets || []).map((ds, i) => ({
                        ...ds,
                        borderColor: this.getColor(i),
                        backgroundColor: type === 'line' ? 'transparent' : this.getColor(i, 0.6),
                        borderWidth: type === 'line' ? 2 : 1,
                        tension: 0.3,
                        fill: type === 'area_chart',
                    })),
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: options.horizontal ? 'y' : 'x',
                    plugins: {
                        legend: { display: showLegend },
                    },
                    scales: (type === 'pie' || type === 'doughnut' || type === 'donut_chart' || type === 'pie_chart') ? {} : {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true },
                    },
                },
            };

            if (type === 'pie_chart' || type === 'pie') {
                config.type = 'pie';
            } else if (type === 'donut_chart' || type === 'doughnut') {
                config.type = 'doughnut';
            }

            this.chart = new Chart(canvas, config);
        },

        handleResize(event, widgetId) {
            if (event.detail?.widgetId === widgetId && this.chart) {
                this.chart.resize();
            }
        },

        getColor(index, alpha = 1) {
            const colors = [
                `rgba(99, 102, 241, ${alpha})`,
                `rgba(16, 185, 129, ${alpha})`,
                `rgba(245, 158, 11, ${alpha})`,
                `rgba(239, 68, 68, ${alpha})`,
                `rgba(139, 92, 246, ${alpha})`,
                `rgba(6, 182, 212, ${alpha})`,
                `rgba(236, 72, 153, ${alpha})`,
                `rgba(34, 197, 94, ${alpha})`,
            ];
            return colors[index % colors.length];
        },

        destroy() {
            if (this.chart) {
                this.chart.destroy();
                this.chart = null;
            }
        },
    }));

    Alpine.data('dashboardGrid', (initialWidgets = [], editMode = false) => ({
        grid: null,
        editing: editMode,
        snapshotLayout: null,

        init() {
            const gridEl = this.$el;
            this.grid = GridStack.init({
                column: 12,
                cellHeight: 80,
                margin: 12,
                animate: true,
                float: false,
                disableResize: !this.editing,
                disableDrag: !this.editing,
                columnOpts: {
                    breakpoints: [
                        { w: 768, c: 1 },
                        { w: 1200, c: 6 },
                    ],
                },
            }, gridEl);

            this.grid.on('change', (event, items) => {
                this.debouncedSave(items);
            });

            // After drag/resize completes, reload to restore Livewire components
            // that break when GridStack moves DOM nodes.
            this.grid.on('dragstop', () => {
                this.needsReload = true;
            });

            this.grid.on('resizestop', (event, el) => {
                const widgetId = el.getAttribute('gs-id');
                if (widgetId) {
                    window.dispatchEvent(new CustomEvent('widget-resized', {
                        detail: { widgetId: parseInt(widgetId) },
                    }));
                }
                this.needsReload = true;
            });
        },

        needsReload: false,

        debouncedSave: Alpine.debounce(function (items) {
            if (!this.editing) return;
            const layout = items.map(item => ({
                id: parseInt(item.id),
                x: item.x,
                y: item.y,
                w: item.w,
                h: item.h,
            }));
            this.$wire.saveLayout(layout);
        }, 500),

        enterEditMode() {
            this.snapshotLayout = this.grid.save(true);
            this.$wire.createSnapshot();
            this.editing = true;
            this.grid.enableMove(true);
            this.grid.enableResize(true);
        },

        exitEditMode() {
            this.editing = false;
            this.grid.enableMove(false);
            this.grid.enableResize(false);
            this.snapshotLayout = null;
            // Reload page to restore Livewire components after drag/resize
            if (this.needsReload) {
                this.needsReload = false;
                window.location.reload();
            }
        },

        cancelEdit() {
            if (this.snapshotLayout) {
                this.grid.load(this.snapshotLayout);
                this.$wire.cancelEdit();
            }
            this.exitEditMode();
        },

        removeWidget(el) {
            const widgetId = el.getAttribute('gs-id');
            this.grid.removeWidget(el);
            this.$wire.removeWidget(parseInt(widgetId));
        },
    }));
});
