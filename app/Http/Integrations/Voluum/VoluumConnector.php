<?php

namespace App\Http\Integrations\Voluum;

use App\DTOs\Integrations\ConnectionTest;
use App\Http\Integrations\BasePlatformConnector;
use App\Http\Integrations\Voluum\Requests\AuthenticateRequest;
use App\Http\Integrations\Voluum\Requests\GetConversionsRequest;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

class VoluumConnector extends BasePlatformConnector
{
    use AlwaysThrowOnErrors;

    protected ?string $sessionToken = null;

    public function platform(): string
    {
        return 'voluum';
    }

    public function resolveBaseUrl(): string
    {
        return 'https://api.voluum.com';
    }

    protected function defaultHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Authenticate and cache the session token.
     */
    public function ensureAuthenticated(): void
    {
        if ($this->sessionToken) {
            return;
        }

        try {
            $response = $this->send(new AuthenticateRequest(
                accessKeyId: $this->credentials['access_key_id'] ?? '',
                accessKeySecret: $this->credentials['access_key_secret'] ?? '',
            ));
            $data = $response->json();
        } catch (FatalRequestException|RequestException $e) {
            throw new \RuntimeException(
                "Voluum API authentication failed: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }

        $this->sessionToken = $data['token'] ?? null;

        if (! $this->sessionToken) {
            throw new \RuntimeException('Voluum authentication response did not contain a token.');
        }

        $this->headers()->add('cwauth-token', $this->sessionToken);
    }

    public function testConnection(): ConnectionTest
    {
        try {
            $this->ensureAuthenticated();

            return ConnectionTest::ok('Successfully connected to Voluum.');
        } catch (\RuntimeException $e) {
            return ConnectionTest::fail("Voluum connection failed: {$e->getMessage()}");
        }
    }

    public function fetchConversionSales(Integration $integration, ?Carbon $since = null): Collection
    {
        $this->ensureAuthenticated();

        $conversions = collect();
        $from = $since ?? now()->subDays(30);

        try {
            $response = $this->send(new GetConversionsRequest(
                from: $from,
                to: now(),
            ));
            $data = $response->json();
        } catch (FatalRequestException|RequestException $e) {
            throw new \RuntimeException(
                "Voluum API error fetching conversions: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }

        $rows = $data['rows'] ?? $data['conversions'] ?? [];

        foreach ($rows as $conversion) {
            $conversions->push([
                'external_id' => (string) ($conversion['clickId'] ?? $conversion['id'] ?? ''),
                'revenue' => (float) ($conversion['revenue'] ?? 0),
                'payout' => (float) ($conversion['payout'] ?? 0),
                'cost' => (float) ($conversion['cost'] ?? 0),
                'converted_at' => $conversion['visitTimestamp'] ?? $conversion['conversionTimestamp'] ?? null,
                'campaign_id' => $conversion['campaignId'] ?? null,
                'offer_id' => $conversion['offerId'] ?? null,
                'country' => $conversion['country'] ?? null,
            ]);
        }

        return $conversions;
    }
}
