<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlphaAgreement extends Model
{
    protected $fillable = [
        'email',
        'agreement_version',
        'accepted_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }
}
