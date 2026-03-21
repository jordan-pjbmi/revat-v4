<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class WorkspaceUser extends Pivot
{
    protected $table = 'workspace_user';

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
        ];
    }
}
