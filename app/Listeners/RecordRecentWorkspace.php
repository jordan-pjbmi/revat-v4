<?php

namespace App\Listeners;

use App\Events\WorkspaceSwitched;
use App\Models\Workspace;
use App\Models\WorkspaceRecent;

class RecordRecentWorkspace
{
    public function handle(WorkspaceSwitched $event): void
    {
        $workspace = Workspace::find($event->to_workspace_id);
        if (! $workspace) {
            return;
        }

        WorkspaceRecent::upsert(
            [
                'user_id' => $event->user_id,
                'organization_id' => $workspace->organization_id,
                'workspace_id' => $event->to_workspace_id,
                'switched_at' => $event->occurred_at,
            ],
            ['user_id', 'organization_id', 'workspace_id'],
            ['switched_at'],
        );

        $this->prune($event->user_id, $workspace->organization_id);
    }

    private function prune(int $userId, int $orgId): void
    {
        $keepIds = WorkspaceRecent::where('user_id', $userId)
            ->where('organization_id', $orgId)
            ->orderByDesc('switched_at')
            ->limit(10)
            ->pluck('id');

        WorkspaceRecent::where('user_id', $userId)
            ->where('organization_id', $orgId)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
