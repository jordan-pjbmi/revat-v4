<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlphaInvite extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'token_hash',
        'last_sent_at',
        'registered_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_sent_at' => 'datetime',
            'registered_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public ?string $plaintext_token = null;

    public function isPending(): bool
    {
        return is_null($this->registered_at) && is_null($this->revoked_at);
    }

    public function isRegistered(): bool
    {
        return ! is_null($this->registered_at);
    }

    public function isRevoked(): bool
    {
        return ! is_null($this->revoked_at);
    }

    public function status(): string
    {
        if ($this->isRegistered()) {
            return 'registered';
        }
        if ($this->isRevoked()) {
            return 'revoked';
        }

        return 'pending';
    }

    public function scopePending($query)
    {
        return $query->whereNull('registered_at')->whereNull('revoked_at');
    }

    public function scopeRegistered($query)
    {
        return $query->whereNotNull('registered_at');
    }

    public function findAgreement(): ?AlphaAgreement
    {
        return AlphaAgreement::where('email', $this->email)
            ->where('agreement_version', config('alpha.agreement_version'))
            ->first();
    }

    public function hasSignedAgreement(): bool
    {
        return $this->findAgreement() !== null;
    }
}
