<?php

use App\Models\AlphaAgreement;
use App\Services\AlphaAgreementService;

beforeEach(function () {
    $this->service = app(AlphaAgreementService::class);
});

it('records an agreement acceptance', function () {
    $this->service->record(
        email: 'alpha@example.com',
        ipAddress: '192.168.1.1',
        userAgent: 'Mozilla/5.0 Test'
    );

    $agreement = AlphaAgreement::where('email', 'alpha@example.com')->first();

    expect($agreement)->not->toBeNull();
    expect($agreement->agreement_version)->toBe('1.0');
    expect($agreement->ip_address)->toBe('192.168.1.1');
    expect($agreement->user_agent)->toBe('Mozilla/5.0 Test');
    expect($agreement->accepted_at)->not->toBeNull();
});

it('prevents duplicate agreement for same email and version', function () {
    $this->service->record('alpha@example.com', '192.168.1.1', 'UA1');
    $this->service->record('alpha@example.com', '192.168.1.2', 'UA2');
})->throws(\InvalidArgumentException::class);

it('uses the configured agreement version', function () {
    config(['alpha.agreement_version' => '2.0']);

    $this->service->record('alpha@example.com', '1.2.3.4', 'UA');

    $agreement = AlphaAgreement::where('email', 'alpha@example.com')->first();
    expect($agreement->agreement_version)->toBe('2.0');
});
