<?php

namespace App\Services;

use App\Models\AlphaAgreement;

class AlphaAgreementService
{
    public function record(string $email, string $ipAddress, string $userAgent): AlphaAgreement
    {
        $version = config('alpha.agreement_version');

        $existing = AlphaAgreement::where('email', $email)
            ->where('agreement_version', $version)
            ->first();

        if ($existing) {
            throw new \InvalidArgumentException('Agreement already recorded for this email and version');
        }

        return AlphaAgreement::create([
            'email' => $email,
            'agreement_version' => $version,
            'accepted_at' => now(),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
