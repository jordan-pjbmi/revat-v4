<?php

namespace App\Observers;

use App\Models\Initiative;
use App\Models\Program;
use App\Models\Workspace;

class WorkspaceObserver
{
    public function created(Workspace $workspace): void
    {
        $program = Program::create([
            'workspace_id' => $workspace->id,
            'name' => 'Default Program',
            'code' => 'DEFAULT',
            'status' => 'active',
            'is_default' => true,
        ]);

        Initiative::create([
            'workspace_id' => $workspace->id,
            'program_id' => $program->id,
            'name' => 'Default Initiative',
            'code' => 'DEFAULT',
            'status' => 'active',
            'is_default' => true,
        ]);
    }
}
