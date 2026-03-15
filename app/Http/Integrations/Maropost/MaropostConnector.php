<?php

namespace App\Http\Integrations\Maropost;

use App\DTOs\Integrations\ConnectionTest;
use App\Http\Integrations\BasePlatformConnector;
use App\Http\Integrations\Maropost\Requests\GetClickReportRequest;
use App\Http\Integrations\Maropost\Requests\GraphqlCampaignsRequest;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;

class MaropostConnector extends BasePlatformConnector
{
    public function platform(): string
    {
        return 'maropost';
    }

    public function resolveBaseUrl(): string
    {
        $accountId = $this->credentials['account_id'] ?? '';

        return $this->validateUrl("https://api.maropost.com/accounts/{$accountId}");
    }

    protected function defaultQuery(): array
    {
        return [
            'auth_token' => $this->credentials['auth_token'] ?? '',
        ];
    }

    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
        ];
    }

    public function testConnection(): ConnectionTest
    {
        try {
            $request = new GraphqlCampaignsRequest(page: 1, per: 1);
            $request->query()->add('auth_token', $this->credentials['auth_token'] ?? '');
            $response = $this->send($request);
            $campaigns = $response->json('data.campaigns');

            $count = is_array($campaigns) ? count($campaigns) : 0;
            $accountId = $this->credentials['account_id'] ?? '';

            return ConnectionTest::ok(
                message: "Successfully connected to Maropost account {$accountId} ({$count} campaign(s) found).",
                details: ['account_id' => $accountId, 'campaign_count' => $count],
            );
        } catch (FatalRequestException|RequestException $e) {
            return ConnectionTest::fail("Maropost connection failed: {$e->getMessage()}");
        }
    }

    public function fetchCampaignEmails(Integration $integration, ?Carbon $since = null): Collection
    {
        $campaigns = collect();

        foreach ($this->paginateGraphql() as $campaign) {
            $id = (string) ($campaign['id'] ?? '');
            if ($id === '') {
                continue;
            }

            // Start with full GraphQL response
            $record = $campaign;

            // Add computed fields for the pipeline
            $record['external_id'] = $id;

            // Hash emails except sender
            $record = $this->hashEmails($record, ['from_email']);

            $campaigns->push($record);
        }

        return $campaigns;
    }

    /**
     * Paginate the GraphQL campaigns endpoint.
     */
    protected function paginateGraphql(int $per = 200): \Generator
    {
        $page = 1;

        do {
            $request = new GraphqlCampaignsRequest(page: $page, per: $per);
            $request->query()->add('auth_token', $this->credentials['auth_token'] ?? '');
            $response = $this->send($request);
            $campaigns = $response->json('data.campaigns');

            if (! is_array($campaigns)) {
                break;
            }

            foreach ($campaigns as $campaign) {
                yield $campaign;
            }

            $page++;
        } while (count($campaigns) >= $per);
    }

    public function fetchCampaignEmailClicks(Integration $integration, ?Carbon $since = null): Collection
    {
        $from = $since?->format('Y-m-d');

        // Fetch page 1 synchronously to discover total_pages
        $response = $this->send(new GetClickReportRequest(page: 1, from: $from));
        $firstPage = $response->json();

        if (! is_array($firstPage) || empty($firstPage)) {
            return collect();
        }

        // v3 response format: array of records, total_pages in first record
        $totalPages = (int) ($firstPage[0]['total_pages'] ?? 1);

        $allClicks = collect();
        $this->collectClickRecords($firstPage, $since, $allClicks);

        if ($totalPages <= 1) {
            return $allClicks;
        }

        // Fetch remaining pages in concurrent batches of 5
        $concurrency = 5;
        for ($batchStart = 2; $batchStart <= $totalPages; $batchStart += $concurrency) {
            $batchEnd = min($batchStart + $concurrency - 1, $totalPages);
            $batchResults = [];
            $failedPages = [];

            $pool = $this->pool(
                requests: function () use ($batchStart, $batchEnd, $from) {
                    for ($page = $batchStart; $page <= $batchEnd; $page++) {
                        yield $page => new GetClickReportRequest(page: $page, from: $from);
                    }
                },
                concurrency: $concurrency,
                responseHandler: function (Response $response, int $page) use (&$batchResults) {
                    $records = $response->json();
                    if (is_array($records)) {
                        $batchResults[$page] = $records;
                    }
                },
                exceptionHandler: function ($exception, int $page) use (&$failedPages) {
                    $failedPages[] = $page;
                    Log::warning("Maropost click report page {$page} failed in pool", [
                        'error' => $exception->getMessage(),
                    ]);
                },
            );

            $pool->send()->wait();

            // Retry failed pages synchronously
            foreach ($failedPages as $page) {
                $retryResponse = $this->send(new GetClickReportRequest(page: $page, from: $from));
                $records = $retryResponse->json();
                if (is_array($records)) {
                    $batchResults[$page] = $records;
                }
            }

            // Yield in page order; stop early if any page returned empty
            $hitEmptyPage = false;
            ksort($batchResults);
            foreach ($batchResults as $records) {
                if (empty($records)) {
                    $hitEmptyPage = true;

                    break;
                }
                $this->collectClickRecords($records, $since, $allClicks);
            }

            if ($hitEmptyPage) {
                break;
            }
        }

        return $allClicks;
    }

    /**
     * Normalize raw API click records and append to the collection.
     */
    protected function collectClickRecords(array $records, ?Carbon $since, Collection $clicks): void
    {
        foreach ($records as $record) {
            $email = strtolower(trim($record['contact']['email'] ?? $record['email'] ?? ''));
            if (empty($email)) {
                continue;
            }

            $clickedAt = $record['recorded_at'] ?? null;

            if ($since && $clickedAt && Carbon::parse($clickedAt)->lt($since)) {
                continue;
            }

            // Add computed fields for the pipeline
            $record['external_campaign_id'] = (string) ($record['campaign_id'] ?? '');
            $record['subscriber_email_hash'] = hash('sha256', $email);

            $clickUrl = $record['url'] ?? '';
            $urlParams = [];
            $parsedUrl = parse_url($clickUrl);
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $urlParams);
            }
            $record['url_params'] = $urlParams;

            // Hash emails in the record
            $record = $this->hashEmails($record);

            $clicks->push($record);
        }
    }

    public function getMatchableFields(Integration $integration): array
    {
        return [
            'campaign_emails' => [
                ['value' => 'name', 'label' => 'Campaign Name'],
                ['value' => 'subject', 'label' => 'Subject'],
                ['value' => 'from_name', 'label' => 'From Name'],
                ['value' => 'from_email', 'label' => 'From Email'],
                ['value' => 'external_id', 'label' => 'Campaign ID'],
                ['value' => 'send_at', 'label' => 'Send Date'],
            ],
            'campaign_email_clicks' => [
                ['value' => 'name', 'label' => 'Campaign Name'],
                ['value' => 'subject', 'label' => 'Subject'],
                ['value' => 'from_name', 'label' => 'From Name'],
                ['value' => 'from_email', 'label' => 'From Email'],
                ['value' => 'external_id', 'label' => 'Campaign ID'],
                ['value' => '__url_param__', 'label' => 'URL Query Parameter'],
            ],
        ];
    }

    protected function resolveStatus(array $campaign): ?string
    {
        return match ($campaign['status'] ?? '') {
            'sent' => 'sent',
            'sending' => 'active',
            'scheduled' => 'active',
            'paused' => 'paused',
            'draft' => 'draft',
            'archived' => 'deleted',
            default => null,
        };
    }

    protected function resolveCampaignType(array $campaign): string
    {
        $campaignType = $campaign['campaign_type'] ?? '';

        if ($campaignType === 'jrny') {
            return 'automation';
        }

        if ($campaignType === 'a/b campaign') {
            return 'split_test';
        }

        return match ($campaign['status'] ?? '') {
            'sent' => 'broadcast',
            'scheduled' => 'broadcast',
            'draft' => 'draft',
            default => $campaign['status'] ?? 'unknown',
        };
    }
}
