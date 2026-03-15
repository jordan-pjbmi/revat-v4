<?php

namespace App\Services\Transformation\FieldMaps;

class CampaignEmailFieldMap
{
    /**
     * Per-platform mappings from raw JSON field names to normalized fact columns.
     *
     * @var array<string, array<string, string>>
     */
    protected static array $maps = [
        'activecampaign' => [
            'external_id' => 'external_id',
            'name' => 'name',
            'subject' => 'subject',
            'from_name' => 'from_name',
            'from_email' => 'from_email',
            'type' => 'type',
            'sent' => 'sent',
            'delivered' => 'delivered',
            'bounces' => 'bounced',
            'complaints' => 'complaints',
            'unsubscribes' => 'unsubscribes',
            'opens' => 'opens',
            'unique_opens' => 'unique_opens',
            'clicks' => 'clicks',
            'unique_clicks' => 'unique_clicks',
            'platform_revenue' => 'platform_revenue',
            'sent_at' => 'sent_at',
        ],
        'expertsender' => [
            'Id' => 'external_id',
            'Name' => 'name',
            'Subject' => 'subject',
            'FromName' => 'from_name',
            'FromEmail' => 'from_email',
            'Type' => 'type',
            'TotalSent' => 'sent',
            'TotalDelivered' => 'delivered',
            'Bounced' => 'bounced',
            'Complaints' => 'complaints',
            'Unsubscribes' => 'unsubscribes',
            'Opens' => 'opens',
            'UniqueOpens' => 'unique_opens',
            'Clicks' => 'clicks',
            'UniqueClicks' => 'unique_clicks',
            'Revenue' => 'platform_revenue',
            'SentDate' => 'sent_at',
        ],
        'maropost' => [
            'id' => 'external_id',
            'name' => 'name',
            'subject' => 'subject',
            'from_name' => 'from_name',
            'from_email' => 'from_email',
            'type' => 'type',
            'delivered' => 'sent',
            'total_delivered' => 'delivered',
            'bounced' => 'bounced',
            'complaints' => 'complaints',
            'unsubscribes' => 'unsubscribes',
            'opens' => 'opens',
            'unique_opens' => 'unique_opens',
            'clicks' => 'clicks',
            'unique_clicks' => 'unique_clicks',
            'revenue' => 'platform_revenue',
            'send_date' => 'sent_at',
        ],
    ];

    /**
     * Default/fallback mapping for unknown platforms.
     *
     * @var array<string, string>
     */
    protected static array $default = [
        'id' => 'external_id',
        'name' => 'name',
        'subject' => 'subject',
        'from_name' => 'from_name',
        'from_email' => 'from_email',
        'type' => 'type',
        'sent' => 'sent',
        'delivered' => 'delivered',
        'bounced' => 'bounced',
        'complaints' => 'complaints',
        'unsubscribes' => 'unsubscribes',
        'opens' => 'opens',
        'unique_opens' => 'unique_opens',
        'clicks' => 'clicks',
        'unique_clicks' => 'unique_clicks',
        'platform_revenue' => 'platform_revenue',
        'sent_at' => 'sent_at',
    ];

    /**
     * Get the field map for a given platform.
     *
     * @return array<string, string>
     */
    public static function for(string $platform): array
    {
        return static::$maps[strtolower($platform)] ?? static::$default;
    }

    /**
     * Map a raw data payload to normalized fact columns using the platform's field map.
     *
     * @return array<string, mixed>
     */
    public static function map(array $rawData, string $platform): array
    {
        $fieldMap = static::for($platform);
        $mapped = [];

        foreach ($fieldMap as $rawField => $factColumn) {
            if (array_key_exists($rawField, $rawData)) {
                $mapped[$factColumn] = $rawData[$rawField];
            }
        }

        return $mapped;
    }
}
