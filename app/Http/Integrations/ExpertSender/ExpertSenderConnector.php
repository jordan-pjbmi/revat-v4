<?php

namespace App\Http\Integrations\ExpertSender;

use App\DTOs\Integrations\ConnectionTest;
use App\Http\Integrations\BasePlatformConnector;
use App\Http\Integrations\ExpertSender\Requests\GetActivitiesRequest;
use App\Http\Integrations\ExpertSender\Requests\GetMessageStatisticsRequest;
use App\Http\Integrations\ExpertSender\Requests\GetMessagesRequest;
use App\Http\Integrations\ExpertSender\Requests\GetSummaryStatisticsRequest;
use App\Http\Integrations\ExpertSender\Requests\GetTimeRequest;
use App\Models\Integration;
use Carbon\Carbon;
use Carbon\CarbonTimeZone;
use Illuminate\Support\Collection;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;

class ExpertSenderConnector extends BasePlatformConnector
{
    public function platform(): string
    {
        return 'expertsender';
    }

    public function resolveBaseUrl(): string
    {
        return $this->validateUrl($this->credentials['api_url'] ?? '');
    }

    protected function defaultQuery(): array
    {
        return [
            'apiKey' => $this->credentials['api_key'] ?? '',
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
            $offset = $this->detectAccountTimezone();
            $response = $this->send(new GetTimeRequest);
            $data = $response->json();

            $serverTime = $data['Data'] ?? $response->body();

            return ConnectionTest::ok(
                message: "Successfully connected to ExpertSender. Server time: {$serverTime} (UTC{$offset}).",
                details: ['server_time' => $serverTime, 'utc_offset' => $offset],
            );
        } catch (FatalRequestException|RequestException $e) {
            return ConnectionTest::fail("ExpertSender connection failed: {$e->getMessage()}");
        }
    }

    // ── Timezone Detection ───────────────────────────────────────────

    /**
     * Call the Time endpoint and derive the account's UTC offset by comparing
     * the server's local time to the current UTC time. Stores the offset in
     * integration settings for observability.
     *
     * Returns a timezone string like "+02:00" or "-05:00".
     */
    public function detectAccountTimezone(): string
    {
        $utcNow = Carbon::now('UTC');
        $response = $this->send(new GetTimeRequest);
        $data = $response->json();

        $serverTime = Carbon::parse($data['Data'] ?? $data['Time'] ?? $data['time'] ?? now(), 'UTC');

        // Round to nearest 15 minutes to handle network latency and get a clean offset.
        $diffMinutes = (int) round($utcNow->diffInMinutes($serverTime, absolute: false) / 15) * 15;

        $offset = CarbonTimeZone::createFromMinuteOffset($diffMinutes)->toOffsetName();

        // Persist for observability
        if ($this->integration->exists) {
            $settings = $this->integration->settings ?? [];
            $settings['account_timezone_offset'] = $offset;
            $this->integration->update(['settings' => $settings]);
        }

        return $offset;
    }

    // ── Campaign Emails ──────────────────────────────────────────────

    public function fetchCampaignEmails(Integration $integration, ?Carbon $since = null): Collection
    {
        $accountTz = $this->detectAccountTimezone();

        // Step 1: Fetch all message metadata
        $response = $this->send(new GetMessagesRequest);
        $messages = $response->json('Data.Messages') ?? $response->json('Messages') ?? [];

        $metadata = [];
        $parsedSentDates = []; // Parsed Carbon instances for filtering

        foreach ($messages as $msg) {
            $id = (string) ($msg['Id'] ?? '');
            if ($id === '') {
                continue;
            }

            // Preserve the full message object from the API
            $metadata[$id] = $msg;

            $parsedSentDates[$id] = ! empty($msg['SentDate'])
                ? Carbon::parse($msg['SentDate'], $accountTz)->utc()
                : null;
        }

        // Step 2: Determine which messages need per-message stats fetched
        if ($since === null) {
            $messageIds = array_keys($metadata);
        } else {
            $activeIds = $this->getActiveMessageIds($since);

            if ($activeIds === null) {
                $messageIds = array_keys($metadata);
            } else {
                // Include messages with activity OR newly sent since last sync
                $messageIds = collect($metadata)
                    ->filter(fn ($meta, $id) => in_array((string) $id, $activeIds, true)
                        || ($parsedSentDates[$id] && $parsedSentDates[$id] >= $since))
                    ->keys()
                    ->all();
            }
        }

        // Step 3: Fetch statistics only for active/new messages
        $campaigns = collect();

        foreach ($messageIds as $msgId) {
            $meta = $metadata[$msgId];

            try {
                $statsResponse = $this->send(new GetMessageStatisticsRequest($msgId));
                $stats = $statsResponse->json('Data') ?? [];
            } catch (\Throwable) {
                $stats = [];
            }

            // Merge message metadata + stats, preserving all fields
            $record = array_merge($meta, $stats);

            // Add computed fields for the pipeline
            $record['external_id'] = (string) $msgId;

            // Hash emails except sender
            $record = $this->hashEmails($record, ['FromEmail', 'fromEmail']);

            $campaigns->push($record);
        }

        return $campaigns;
    }

    /**
     * Call SummaryStatistics to find which MessageIds had activity since the
     * given date. Returns null on failure so the caller can fall back to a
     * full sync.
     */
    protected function getActiveMessageIds(Carbon $since): ?array
    {
        try {
            $response = $this->send(new GetSummaryStatisticsRequest(
                startDate: $since->format('Y-m-d'),
                endDate: Carbon::now()->format('Y-m-d'),
            ));

            $rows = $response->json('Data') ?? [];
            $ids = [];

            foreach ($rows as $row) {
                if (! empty($row['IsSummaryRow'])) {
                    continue;
                }

                $msgId = (string) ($row['MessageId'] ?? '');
                if ($msgId !== '') {
                    $ids[] = $msgId;
                }
            }

            return array_values(array_unique($ids));
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Campaign Email Clicks ────────────────────────────────────────

    public function fetchCampaignEmailClicks(Integration $integration, ?Carbon $since = null): Collection
    {
        $accountTz = $this->detectAccountTimezone();

        $today = Carbon::now($accountTz)->startOfDay();
        $startDate = $since
            ? Carbon::parse($since)->setTimezone($accountTz)->startOfDay()
            : $today->copy()->subYear();

        $clicks = collect();

        for ($date = $startDate->copy(); $date->lte($today); $date->addDay()) {
            $dateStr = $date->format('Y-m-d');

            $response = $this->send(new GetActivitiesRequest('Clicks', $dateStr));
            $rows = $this->parseCsv($response->body());

            foreach ($rows as $row) {
                if (empty($row['Email'])) {
                    continue;
                }

                $email = strtolower(trim($row['Email']));

                // Add computed fields for the pipeline
                $row['external_campaign_id'] = (string) ($row['MessageId'] ?? '');
                $row['subscriber_email_hash'] = hash('sha256', $email);

                $url = $this->resolveMergeTags($row['Url'] ?? '', $row);
                $row['click_url'] = $url ?: '';

                $urlParams = [];
                $parsedUrl = parse_url($row['click_url']);
                if (isset($parsedUrl['query'])) {
                    parse_str($parsedUrl['query'], $urlParams);
                }
                $row['url_params'] = $urlParams;

                $row['clicked_at'] = ! empty($row['Date'])
                    ? Carbon::parse($row['Date'], $accountTz)->utc()->toDateTimeString()
                    : null;

                // Hash emails in the record
                $row = $this->hashEmails($row);

                $clicks->push($row);
            }
        }

        return $clicks;
    }

    public function getMatchableFields(Integration $integration): array
    {
        return [
            'campaign_emails' => [
                ['value' => 'Subject', 'label' => 'Subject'],
                ['value' => 'FromName', 'label' => 'From Name'],
                ['value' => 'FromEmail', 'label' => 'From Email'],
                ['value' => 'external_id', 'label' => 'Message ID'],
                ['value' => 'Type', 'label' => 'Message Type'],
            ],
            'campaign_email_clicks' => [
                ['value' => 'Subject', 'label' => 'Subject'],
                ['value' => 'FromName', 'label' => 'From Name'],
                ['value' => 'FromEmail', 'label' => 'From Email'],
                ['value' => 'external_id', 'label' => 'Message ID'],
                ['value' => '__url_param__', 'label' => 'URL Query Parameter'],
            ],
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────

    protected function resolveCampaignType(string $apiType): string
    {
        return match ($apiType) {
            'Newsletter' => 'broadcast',
            'WorkflowMessage', 'Autoresponder', 'Trigger', 'Transactional', 'Recurring' => 'automation',
            'Test' => 'test',
            default => 'broadcast',
        };
    }

    /**
     * Replace known ExpertSender merge tags with values from the CSV row.
     */
    protected function resolveMergeTags(string $url, array $row): string
    {
        return str_replace(
            ['*[message_id]*', '*[subscriber_email]*'],
            [$row['MessageId'] ?? '', $row['Email'] ?? ''],
            $url,
        );
    }

    /**
     * Strip the UTF-8 BOM if present.
     */
    protected function stripBom(string $body): string
    {
        return str_starts_with($body, "\xEF\xBB\xBF") ? substr($body, 3) : $body;
    }

    /**
     * Parse a CSV string (stripping the UTF-8 BOM if present) into an array
     * of associative arrays keyed by the header row.
     */
    protected function parseCsv(string $body): array
    {
        $body = $this->stripBom($body);

        $lines = explode("\n", trim($body));
        if (count($lines) <= 1) {
            return [];
        }

        $headers = str_getcsv(array_shift($lines));
        $rows = [];

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }

            $values = str_getcsv($line);
            if (count($values) === count($headers)) {
                $rows[] = array_combine($headers, $values);
            }
        }

        return $rows;
    }
}
