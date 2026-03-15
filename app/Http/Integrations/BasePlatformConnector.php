<?php

namespace App\Http\Integrations;

use App\Contracts\Integrations\PlatformConnector;
use App\DTOs\Integrations\ConnectionTest;
use App\Exceptions\AuthenticationException;
use App\Exceptions\InvalidConnectorUrlException;
use App\Exceptions\RateLimitException;
use App\Exceptions\UnsupportedDataTypeException;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Connector;
use Saloon\Http\Request;
use Saloon\RateLimitPlugin\Contracts\RateLimitStore;
use Saloon\RateLimitPlugin\Limit;
use Saloon\RateLimitPlugin\Stores\LaravelCacheStore;
use Saloon\RateLimitPlugin\Traits\HasRateLimits;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

abstract class BasePlatformConnector extends Connector implements PlatformConnector
{
    use AcceptsJson;
    use AlwaysThrowOnErrors;
    use HasRateLimits;

    // ── Retry Configuration ──────────────────────────────────────────

    public ?int $tries = 3;

    public ?int $retryInterval = 500;

    public ?bool $useExponentialBackoff = true;

    // ── Properties ───────────────────────────────────────────────────

    protected array $credentials;

    public function __construct(
        protected Integration $integration,
    ) {
        $this->credentials = $integration->credentials ?? [];
    }

    // ── Retry Handler ────────────────────────────────────────────────

    public function handleRetry(FatalRequestException|RequestException $exception, Request $request): bool
    {
        // FatalRequestException = connection-level failure (DNS, timeout, etc.) — always retry
        if ($exception instanceof FatalRequestException) {
            return true;
        }

        $response = $exception->getResponse();
        $status = $response->status();

        // 401/403 — authentication problem, stop retrying and throw specific exception
        if (in_array($status, [401, 403], true)) {
            throw new AuthenticationException(
                "Authentication failed ({$status}): {$response->body()}",
                $status,
                $exception,
                $this->platform(),
            );
        }

        // 429 — rate limited, allow retry (Saloon's backoff will handle delay)
        if ($status === 429) {
            return true;
        }

        // 5xx — server error, allow retry
        if ($status >= 500) {
            return true;
        }

        // 4xx (other than 401/403/429) — client error, don't retry
        return false;
    }

    // ── PlatformConnector Interface ─────────────────────────────────────

    public function testConnection(): ConnectionTest
    {
        return ConnectionTest::fail('Connection testing is not implemented for this platform.');
    }

    public function fetchCampaignEmails(Integration $integration, ?Carbon $since = null): Collection
    {
        throw UnsupportedDataTypeException::forPlatform($this->platform(), 'campaign_emails');
    }

    public function fetchCampaignEmailClicks(Integration $integration, ?Carbon $since = null): Collection
    {
        throw UnsupportedDataTypeException::forPlatform($this->platform(), 'campaign_email_clicks');
    }

    public function fetchConversionSales(Integration $integration, ?Carbon $since = null): Collection
    {
        throw UnsupportedDataTypeException::forPlatform($this->platform(), 'conversion_sales');
    }

    public function supportsDataType(string $dataType): bool
    {
        $platformConfig = config("integrations.platforms.{$this->platform()}");

        if (! $platformConfig) {
            return false;
        }

        return in_array($dataType, $platformConfig['data_types'] ?? [], true);
    }

    // ── Saloon Connector ────────────────────────────────────────────────

    protected function defaultConfig(): array
    {
        return [
            'connect_timeout' => config('integrations.http.connect_timeout', 10),
            'timeout' => config('integrations.http.timeout', 30),
        ];
    }

    // ── Rate Limiting ─────────────────────────────────────────────────

    protected function resolveRateLimitStore(): RateLimitStore
    {
        return new LaravelCacheStore(cache()->store('redis'));
    }

    protected function resolveLimits(): array
    {
        $config = config("integrations.rate_limits.{$this->platform()}");

        if (! $config) {
            return [];
        }

        return [
            Limit::allow($config['requests'])
                ->everySeconds($config['per_seconds'])
                ->name("{$this->platform()}:{$this->integration->id}")
                ->sleep(),
        ];
    }

    // ── SSRF Protection ─────────────────────────────────────────────────

    /**
     * Validate a URL for SSRF protection before using it as a base URL.
     */
    protected function validateUrl(string $url): string
    {
        $parsed = parse_url($url);

        // Must be HTTPS
        if (! isset($parsed['scheme']) || strtolower($parsed['scheme']) !== 'https') {
            throw InvalidConnectorUrlException::notHttps($url);
        }

        // Must have a valid host
        if (! isset($parsed['host']) || empty($parsed['host'])) {
            throw InvalidConnectorUrlException::invalidHost($url);
        }

        // Block non-standard ports
        if (isset($parsed['port']) && $parsed['port'] !== 443) {
            throw InvalidConnectorUrlException::nonStandardPort($url);
        }

        // Resolve DNS and block private IPs
        $ips = gethostbynamel($parsed['host']);
        if ($ips === false) {
            throw InvalidConnectorUrlException::invalidHost($url);
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateIp($ip)) {
                throw InvalidConnectorUrlException::privateIp($url);
            }
        }

        return $url;
    }

    /**
     * Check if an IP address is in a private or reserved range.
     */
    protected function isPrivateIp(string $ip): bool
    {
        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
