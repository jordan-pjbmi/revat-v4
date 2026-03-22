<?php

namespace App\Console\Commands;

use App\Models\Dashboard;
use App\Models\DashboardSnapshot;
use Illuminate\Console\Command;

class PruneDashboardSnapshots extends Command
{
    protected $signature = 'dashboard:prune-snapshots';

    protected $description = 'Prune dashboard snapshots keeping only the most recent 20 per dashboard';

    public function handle(): int
    {
        $totalPruned = 0;

        Dashboard::select('id')->chunk(100, function ($dashboards) use (&$totalPruned) {
            foreach ($dashboards as $dashboard) {
                $keepIds = DashboardSnapshot::where('dashboard_id', $dashboard->id)
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->pluck('id');

                $pruned = DashboardSnapshot::where('dashboard_id', $dashboard->id)
                    ->whereNotIn('id', $keepIds)
                    ->delete();

                $totalPruned += $pruned;
            }
        });

        $this->info("Pruned {$totalPruned} snapshots.");

        return self::SUCCESS;
    }
}
