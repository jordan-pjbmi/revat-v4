<?php

namespace App\Http\Integrations\ActiveCampaign;

use App\DTOs\Integrations\ConnectionTest;
use App\Http\Integrations\ActiveCampaign\Requests\GetAccountRequest;
use App\Http\Integrations\ActiveCampaign\Requests\GetCampaignReportLinksRequest;
use App\Http\Integrations\ActiveCampaign\Requests\GetCampaignsRequest;
use App\Http\Integrations\ActiveCampaign\Requests\GetMessagesRequest;
use App\Http\Integrations\BasePlatformConnector;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;

class ActiveCampaignConnector extends BasePlatformConnector
{
    public function platform(): string
    {
        return 'activecampaign';
    }

    public function resolveBaseUrl(): string
    {
        return $this->validateUrl($this->credentials['api_url'] ?? '');
    }

    protected function defaultHeaders(): array
    {
        return [
            'Api-Token' => $this->credentials['api_key'] ?? '',
        ];
    }

    public function testConnection(): ConnectionTest
    {
        try {
            $response = $this->send(new GetAccountRequest);
            $data = $response->json();

            $username = $data['user']['username'] ?? 'unknown';

            return ConnectionTest::ok(
                message: "Successfully connected to ActiveCampaign as {$username}.",
                details: ['username' => $username],
            );
        } catch (FatalRequestException|RequestException $e) {
            return ConnectionTest::fail("ActiveCampaign connection failed: {$e->getMessage()}");
        }
    }

    public function fetchCampaignEmails(Integration $integration, ?Carbon $since = null): Collection
    {
        // Pass 1: Paginate campaigns with sideloaded campaignMessages
        $campaignRows = [];
        $subjectByCampaignId = [];
        $messageIdByCampaignId = [];
        $offset = 0;
        $limit = 100;

        do {
            $response = $this->send(new GetCampaignsRequest($offset, $limit));
            $data = $response->json();

            $total = (int) ($data['meta']['total'] ?? 0);

            foreach ($data['campaigns'] ?? [] as $campaign) {
                $campaignId = (string) $campaign['id'];

                if ($since && isset($campaign['cdate'])) {
                    $createdAt = Carbon::parse($campaign['cdate']);
                    if ($createdAt->lt($since)) {
                        continue;
                    }
                }

                $campaignRows[$campaignId] = $campaign;
            }

            foreach ($data['campaignMessages'] ?? [] as $cm) {
                $cid = (string) $cm['campaignid'];
                $subjectByCampaignId[$cid] = $cm['subject'] ?? null;
                $messageIdByCampaignId[$cid] = (string) ($cm['messageid'] ?? $cm['message'] ?? '');
            }

            $offset += $limit;
        } while ($offset < $total);

        // Pass 2: Paginate /api/3/messages to build sender lookup
        $sendersByMessageId = $this->fetchSendersByMessageId();

        // Pass 3: Join all sources into complete records, preserving full API response
        $campaigns = collect();

        foreach ($campaignRows as $campaignId => $campaign) {
            $campaignId = (string) $campaignId;
            $messageId = $messageIdByCampaignId[$campaignId] ?? null;
            $sender = $messageId ? ($sendersByMessageId[$messageId] ?? []) : [];

            // Start with full campaign object from API
            $record = $campaign;

            // Add enrichment from other endpoints
            $record['external_id'] = $campaignId;
            $record['subject'] = $subjectByCampaignId[$campaignId] ?? $campaign['subject'] ?? '';
            $record['fromname'] = $sender['fromname'] ?? '';
            $record['fromemail'] = $sender['fromemail'] ?? '';

            // Computed: total bounces (hard + soft)
            $record['_bounces'] = (int) ($campaign['hardbounces'] ?? 0) + (int) ($campaign['softbounces'] ?? 0);

            // Hash emails except sender
            $record = $this->hashEmails($record, ['fromemail']);

            $campaigns->push($record);
        }

        return $campaigns;
    }

    /**
     * Paginate /api/3/messages and return a map of message ID → sender fields.
     */
    protected function fetchSendersByMessageId(): array
    {
        $senders = [];
        $offset = 0;
        $limit = 100;

        do {
            $response = $this->send(new GetMessagesRequest($offset, $limit));
            $data = $response->json();

            $total = (int) ($data['meta']['total'] ?? 0);

            foreach ($data['messages'] ?? [] as $m) {
                $senders[(string) $m['id']] = [
                    'fromname' => $m['fromname'] ?? null,
                    'fromemail' => $m['fromemail'] ?? null,
                ];
            }

            $offset += $limit;
        } while ($offset < $total);

        return $senders;
    }

    public function fetchCampaignEmailClicks(Integration $integration, ?Carbon $since = null): Collection
    {
        $clicks = collect();
        $offset = 0;
        $limit = 100;

        // Get campaigns first
        do {
            $response = $this->send(new GetCampaignsRequest($offset, $limit));
            $data = $response->json();

            $campaigns = $data['campaigns'] ?? [];

            foreach ($campaigns as $campaign) {
                $campaignId = (string) $campaign['id'];

                // Paginate the V1 campaign_report_link_list endpoint per campaign
                $this->fetchClicksForCampaign($campaignId, $since, $clicks);
            }

            $offset += $limit;
            $total = (int) ($data['meta']['total'] ?? 0);
        } while ($offset < $total);

        return $clicks;
    }

    /**
     * Paginate the V1 campaign_report_link_list endpoint for a single campaign.
     */
    protected function fetchClicksForCampaign(string $campaignId, ?Carbon $since, Collection $clicks): void
    {
        $page = 1;

        do {
            $response = $this->send(new GetCampaignReportLinksRequest($campaignId, $page));
            $data = $response->json();

            // Separate link objects (numeric keys) from metadata keys
            $links = [];
            foreach ($data as $key => $value) {
                if (is_numeric($key) && is_array($value)) {
                    $links[] = $value;
                }
            }

            foreach ($links as $link) {
                foreach ($link['info'] ?? [] as $info) {
                    if (empty($info['email'])) {
                        continue;
                    }

                    $clickedAt = $info['tstamp_iso'] ?? $info['tstamp'] ?? null;

                    if ($since && $clickedAt && Carbon::parse($clickedAt)->lt($since)) {
                        continue;
                    }

                    // Merge link + info into a single record, preserving all fields
                    $record = array_merge($link, $info);
                    unset($record['info']); // Remove nested array to avoid duplication

                    // Add computed fields for the pipeline
                    $email = strtolower(trim($info['email']));
                    $record['external_campaign_id'] = $campaignId;
                    $record['subscriber_email_hash'] = hash('sha256', $email);

                    $clickUrl = $link['link'] ?? '';
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

            $page++;
        } while (count($links) >= 20);
    }

    public function getMatchableFields(Integration $integration): array
    {
        return [
            'campaign_emails' => [
                ['value' => 'subject', 'label' => 'Subject'],
                ['value' => 'fromname', 'label' => 'From Name'],
                ['value' => 'fromemail', 'label' => 'From Email'],
                ['value' => 'external_id', 'label' => 'Campaign ID'],
                ['value' => 'name', 'label' => 'Campaign Name'],
                ['value' => 'cdate', 'label' => 'Created Date'],
                ['value' => 'sdate', 'label' => 'Send Date'],
            ],
            'campaign_email_clicks' => [
                ['value' => 'subject', 'label' => 'Subject'],
                ['value' => 'fromname', 'label' => 'From Name'],
                ['value' => 'fromemail', 'label' => 'From Email'],
                ['value' => 'external_id', 'label' => 'Campaign ID'],
                ['value' => 'name', 'label' => 'Campaign Name'],
                ['value' => '__url_param__', 'label' => 'URL Query Parameter'],
            ],
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────

    protected function resolveCampaignType(array $campaign): string
    {
        return match ($campaign['type'] ?? '') {
            'single' => 'broadcast',
            'automation' => 'automation',
            'split_test' => 'split_test',
            default => 'broadcast',
        };
    }

    protected function resolveStatus(array $campaign): ?string
    {
        return match ((string) ($campaign['status'] ?? '')) {
            '0' => 'draft',
            '1' => 'active',
            '2' => 'sent',
            '3' => 'paused',
            '5' => 'stopped',
            default => null,
        };
    }
}
