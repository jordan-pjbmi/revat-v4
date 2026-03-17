<?php

use App\Http\Integrations\ActiveCampaign\ActiveCampaignConnector;
use App\Http\Integrations\ExpertSender\ExpertSenderConnector;
use App\Http\Integrations\Maropost\MaropostConnector;
use App\Http\Integrations\Voluum\VoluumConnector;

return [
    /*
    |--------------------------------------------------------------------------
    | Platform Definitions
    |--------------------------------------------------------------------------
    |
    | Each platform defines its connector class, credential fields,
    | supported data types, and any platform-specific configuration.
    |
    */

    'platforms' => [
        'activecampaign' => [
            'connector' => ActiveCampaignConnector::class,
            'label' => 'ActiveCampaign',
            'short' => 'AC',
            'description' => 'Email marketing & CRM automation',
            'data_types' => ['campaign_emails', 'campaign_email_clicks'],
            'credential_fields' => [
                ['key' => 'api_url', 'label' => 'Account Name', 'type' => 'text', 'placeholder' => 'e.g. mycompany (from mycompany.activehosted.com)'],
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'Enter your API key'],
            ],
        ],
        'expertsender' => [
            'connector' => ExpertSenderConnector::class,
            'label' => 'ExpertSender',
            'short' => 'ES',
            'description' => 'Email marketing & automation platform',
            'data_types' => ['campaign_emails', 'campaign_email_clicks'],
            'credential_fields' => [
                ['key' => 'api_url', 'label' => 'API URL', 'type' => 'text', 'placeholder' => 'e.g. https://api.expertsender.com'],
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'Enter your API key'],
            ],
        ],
        'maropost' => [
            'connector' => MaropostConnector::class,
            'label' => 'Maropost',
            'short' => 'MP',
            'description' => 'Email marketing & automation platform',
            'data_types' => ['campaign_emails', 'campaign_email_clicks'],
            'credential_fields' => [
                ['key' => 'account_id', 'label' => 'Account ID', 'type' => 'text', 'placeholder' => 'Enter your account ID'],
                ['key' => 'auth_token', 'label' => 'Auth Token', 'type' => 'password', 'placeholder' => 'Enter your auth token'],
            ],
        ],
        'voluum' => [
            'connector' => VoluumConnector::class,
            'label' => 'Voluum',
            'short' => 'VL',
            'description' => 'Performance marketing tracker',
            'data_types' => ['conversion_sales'],
            'credential_fields' => [
                ['key' => 'access_key_id', 'label' => 'Access Key ID', 'type' => 'text', 'placeholder' => 'Enter your access key ID'],
                ['key' => 'access_key_secret', 'label' => 'Access Key Secret', 'type' => 'password', 'placeholder' => 'Enter your access key secret'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Frequency Options
    |--------------------------------------------------------------------------
    */

    'sync_frequency_options' => [
        15 => 'Every 15 minutes',
        30 => 'Every 30 minutes',
        60 => 'Every hour',
        360 => 'Every 6 hours',
        720 => 'Every 12 hours',
        1440 => 'Daily',
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Type Labels
    |--------------------------------------------------------------------------
    */

    'data_type_labels' => [
        'campaign_emails' => 'Email Campaigns',
        'campaign_email_clicks' => 'Click Tracking',
        'conversion_sales' => 'Conversions / Sales',
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Platform Rate Limits
    |--------------------------------------------------------------------------
    |
    | Proactive request pacing per platform. Limits are scoped per integration
    | (per API key) via Redis. When the limit is hit, the connector sleeps
    | until the window resets rather than firing and getting 429'd.
    |
    */

    'rate_limits' => [
        'activecampaign' => ['requests' => 5, 'per_seconds' => 1],
        'expertsender' => ['requests' => 10, 'per_seconds' => 1],
        'maropost' => ['requests' => 1, 'per_seconds' => 1],
        'voluum' => ['requests' => 3, 'per_seconds' => 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Defaults
    |--------------------------------------------------------------------------
    */

    'http' => [
        'connect_timeout' => 10,
        'timeout' => 30,
        'max_response_size' => 50 * 1024 * 1024, // 50MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Orchestration
    |--------------------------------------------------------------------------
    */

    'max_concurrent_syncs' => (int) env('INTEGRATION_MAX_CONCURRENT_SYNCS', 3),
];
