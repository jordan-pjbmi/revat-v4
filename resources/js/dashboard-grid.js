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
        _styleCheckFrame: null,

        init() {
            const gridEl = this.$el;
            this.grid = GridStack.init({
                column: 12,
                cellHeight: 80,
                margin: 12,
                animate: true,
                float: false,
                alwaysShowResizeHandle: true,
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
            this.$wire.createSnapshot();
            this.editing = true;
            this.grid.enableMove(true);
            this.grid.enableResize(true);
            // GridStack v12 + Livewire workaround: resizestop/dragstop events
            // don't fire (onEndMoving early-returns when gridstackNode is broken
            // by wire:ignore), _cleanHelper restores original inline styles, and
            // _writePosAttr skips setting new ones (_resizing flag). Poll via rAF
            // during edit mode to reconcile inline styles with gs-* attributes.
            // The check is cheap — it skips elements that already have correct
            // styles and elements currently being dragged/resized.
            this._startStyleCheck();
        },

        _startStyleCheck() {
            const self = this;
            const check = () => {
                if (!self.editing) return;
                self._reconcileStyles();
                self._styleCheckFrame = requestAnimationFrame(check);
            };
            self._styleCheckFrame = requestAnimationFrame(check);
        },

        _stopStyleCheck() {
            if (this._styleCheckFrame) {
                cancelAnimationFrame(this._styleCheckFrame);
                this._styleCheckFrame = null;
            }
        },

        exitEditMode() {
            this.editing = false;
            this._stopStyleCheck();
            this.grid.enableMove(false);
            this.grid.enableResize(false);
            this._reconcileStyles();
            // Reload page to restore Livewire components after drag/resize
            if (this.needsReload) {
                this.needsReload = false;
                window.location.reload();
            }
        },

        cancelEdit() {
            // Server-side cancelEdit restores the pre-edit snapshot and redirects.
            this.editing = false;
            this._stopStyleCheck();
            this.grid.enableMove(false);
            this.grid.enableResize(false);
            this.$wire.cancelEdit();
        },

        /** @internal Reconciles inline CSS variable styles with gs-* attributes.
         *  GridStack v12 updates gs-* attributes correctly but sometimes fails to
         *  set the corresponding inline styles. This is a no-op when styles match. */
        _reconcileStyles() {
            this.$el.querySelectorAll('.grid-stack-item').forEach(el => {
                if (el.classList.contains('ui-draggable-dragging') || el.classList.contains('ui-resizable-resizing')) return;

                const w = parseInt(el.getAttribute('gs-w') || '1');
                const h = parseInt(el.getAttribute('gs-h') || '1');
                const x = parseInt(el.getAttribute('gs-x') || '0');
                const y = parseInt(el.getAttribute('gs-y') || '0');
                const wantW = w > 1 ? `calc(${w} * var(--gs-column-width))` : '';
                const wantH = h > 1 ? `calc(${h} * var(--gs-cell-height))` : '';
                if (el.style.width !== wantW) el.style.width = wantW || null;
                if (el.style.height !== wantH) el.style.height = wantH || null;
                const wantL = x ? `calc(${x} * var(--gs-column-width))` : '';
                const wantT = y ? `calc(${y} * var(--gs-cell-height))` : '';
                if (el.style.left !== wantL) el.style.left = wantL || null;
                if (el.style.top !== wantT) el.style.top = wantT || null;
            });
        },

        removeWidget(el) {
            const widgetId = el.getAttribute('gs-id');
            this.grid.removeWidget(el);
            this.$wire.removeWidget(parseInt(widgetId));
        },
    }));
});
