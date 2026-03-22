<?php

namespace App\Http\Controllers;

use App\Models\Dashboard;
use App\Models\DashboardExport;
use App\Models\DashboardSnapshot;
use App\Models\DashboardWidget;
use App\Models\UserDashboardPreference;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'template_slug' => ['nullable', 'string'],
        ]);

        $workspace = app(WorkspaceContext::class)->getWorkspace();
        abort_unless($workspace, 403);

        if (! empty($validated['template_slug'])) {
            $template = Dashboard::templates()
                ->where('template_slug', $validated['template_slug'])
                ->firstOrFail();

            $dashboard = $template->cloneToWorkspace($workspace->id, $request->user()->id);
            $dashboard->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);
        } else {
            $dashboard = Dashboard::create([
                'workspace_id' => $workspace->id,
                'created_by' => $request->user()->id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);
        }

        UserDashboardPreference::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'workspace_id' => $workspace->id,
            ],
            ['active_dashboard_id' => $dashboard->id],
        );

        return redirect()->route('dashboard')->with('success', 'Dashboard created.');
    }

    public function update(Request $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorizeWorkspace($dashboard);

        if ($dashboard->is_locked && ! $request->user()->can('manage')) {
            abort(403, 'This dashboard is locked.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $dashboard->update($validated);

        return redirect()->route('dashboard')->with('success', 'Dashboard updated.');
    }

    public function destroy(Dashboard $dashboard): RedirectResponse
    {
        $this->authorizeWorkspace($dashboard);

        if ($dashboard->is_locked) {
            abort(403, 'This dashboard is locked.');
        }

        $dashboard->delete();

        UserDashboardPreference::where('active_dashboard_id', $dashboard->id)->delete();

        return redirect()->route('dashboard')->with('success', 'Dashboard deleted.');
    }

    public function toggleLock(Dashboard $dashboard): RedirectResponse
    {
        $this->authorizeWorkspace($dashboard);

        $dashboard->update(['is_locked' => ! $dashboard->is_locked]);

        $status = $dashboard->is_locked ? 'locked' : 'unlocked';

        return redirect()->route('dashboard')->with('success', "Dashboard {$status}.");
    }

    public function export(Request $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorizeWorkspace($dashboard);

        $layout = $dashboard->widgets->map(fn (DashboardWidget $w) => [
            'widget_type' => $w->widget_type,
            'grid_x' => $w->grid_x,
            'grid_y' => $w->grid_y,
            'grid_w' => $w->grid_w,
            'grid_h' => $w->grid_h,
            'config' => $w->config,
            'sort_order' => $w->sort_order,
        ])->toArray();

        $export = DashboardExport::create([
            'dashboard_id' => $dashboard->id,
            'created_by' => $request->user()->id,
            'token' => DashboardExport::generateToken(),
            'name' => $dashboard->name,
            'description' => $dashboard->description,
            'layout' => $layout,
            'widget_count' => count($layout),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);

        $shareUrl = route('dashboard.import.show', $export->token);

        return redirect()->route('dashboard')
            ->with('success', 'Share link created.')
            ->with('share_url', $shareUrl);
    }

    public function showImport(string $token)
    {
        $export = DashboardExport::valid()->where('token', $token)->firstOrFail();

        return view('pages.dashboard-import', ['export' => $export]);
    }

    public function import(Request $request, string $token): RedirectResponse
    {
        $export = DashboardExport::valid()->where('token', $token)->firstOrFail();
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        abort_unless($workspace, 403);

        $dashboard = DB::transaction(function () use ($export, $workspace, $request) {
            $dashboard = Dashboard::create([
                'workspace_id' => $workspace->id,
                'created_by' => $request->user()->id,
                'name' => $export->name,
                'description' => $export->description,
            ]);

            foreach ($export->layout as $widgetData) {
                DashboardWidget::create([
                    'dashboard_id' => $dashboard->id,
                    'widget_type' => $widgetData['widget_type'],
                    'grid_x' => $widgetData['grid_x'],
                    'grid_y' => $widgetData['grid_y'],
                    'grid_w' => $widgetData['grid_w'],
                    'grid_h' => $widgetData['grid_h'],
                    'config' => $widgetData['config'] ?? [],
                    'sort_order' => $widgetData['sort_order'] ?? 0,
                ]);
            }

            return $dashboard;
        });

        UserDashboardPreference::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'workspace_id' => $workspace->id,
            ],
            ['active_dashboard_id' => $dashboard->id],
        );

        return redirect()->route('dashboard')->with('success', 'Dashboard imported.');
    }

    public function restore(Request $request, Dashboard $dashboard, DashboardSnapshot $snapshot): RedirectResponse
    {
        $this->authorizeWorkspace($dashboard);
        abort_unless($snapshot->dashboard_id === $dashboard->id, 404);

        if ($dashboard->is_locked && ! $request->user()->can('manage')) {
            abort(403, 'This dashboard is locked.');
        }

        DB::transaction(function () use ($dashboard, $snapshot) {
            $dashboard->widgets()->delete();

            foreach ($snapshot->layout as $widgetData) {
                DashboardWidget::create([
                    'dashboard_id' => $dashboard->id,
                    'widget_type' => $widgetData['widget_type'],
                    'grid_x' => $widgetData['grid_x'],
                    'grid_y' => $widgetData['grid_y'],
                    'grid_w' => $widgetData['grid_w'],
                    'grid_h' => $widgetData['grid_h'],
                    'config' => $widgetData['config'] ?? [],
                    'sort_order' => $widgetData['sort_order'] ?? 0,
                ]);
            }
        });

        return redirect()->route('dashboard')->with('success', 'Dashboard restored from snapshot.');
    }

    public function revokeExport(Request $request, DashboardExport $dashboardExport): RedirectResponse
    {
        if ($dashboardExport->created_by !== $request->user()->id && ! $request->user()->can('manage')) {
            abort(403);
        }

        $dashboardExport->delete();

        return redirect()->route('dashboard')->with('success', 'Export link revoked.');
    }

    protected function authorizeWorkspace(Dashboard $dashboard): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        abort_unless($workspace && $dashboard->workspace_id === $workspace->id, 403);
    }
}
