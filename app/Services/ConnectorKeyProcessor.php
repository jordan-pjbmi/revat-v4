<?php

namespace App\Services;

use App\Models\AttributionConnector;
use App\Models\AttributionKey;
use App\Models\Integration;
use App\Services\Integrations\ConnectorRegistry;
use Illuminate\Support\Facades\DB;

class ConnectorKeyProcessor
{
    public function __construct(
        protected ConnectorRegistry $registry,
    ) {}

    /**
     * Process keys for a connector: extract field values from raw_data JSON,
     * build composite keys (all mapped fields concatenated with |), hash them,
     * and create attribution_keys + attribution_record_keys.
     */
    public function processKeys(AttributionConnector $connector): void
    {
        $mappings = $connector->field_mappings;

        $campaignIntegration = $connector->campaign_integration_id
            ? Integration::find($connector->campaign_integration_id)
            : null;

        $conversionIntegration = $connector->conversion_integration_id
            ? Integration::find($connector->conversion_integration_id)
            : null;

        // Extract all field names and validate upfront
        $campaignFields = [];
        $conversionFields = [];

        foreach ($mappings as $mapping) {
            $campaignFields[] = $mapping['campaign'];
            $conversionFields[] = $mapping['conversion'];
        }

        // Validate all fields against whitelist
        if ($campaignIntegration) {
            foreach ($campaignFields as $field) {
                $this->validateFieldName($field, $campaignIntegration, $connector->campaign_data_type);
            }
        }

        if ($conversionIntegration) {
            foreach ($conversionFields as $field) {
                $this->validateFieldName($field, $conversionIntegration, $connector->conversion_data_type);
            }
        }

        // Process records matching the connector's campaign data type
        if ($campaignIntegration) {
            if ($connector->campaign_data_type === 'campaign_emails') {
                $this->processCampaignRecords($connector, $campaignFields);
                $this->processClicksFromParentCampaigns($connector);
            } elseif ($connector->campaign_data_type === 'campaign_email_clicks') {
                $this->processCampaignClickRecords($connector, $campaignFields);
            }
        }

        if ($conversionIntegration) {
            $this->processConversionRecords($connector, $conversionFields, $conversionIntegration);
        }
    }

    /**
     * Validate a field name against the platform's matchable fields whitelist.
     * Prevents SQL injection via JSON path interpolation.
     */
    protected function validateFieldName(string $field, Integration $integration, ?string $dataType): void
    {
        // url_param:<name> fields are valid only for campaign_email_clicks
        if (str_starts_with($field, 'url_param:')) {
            $paramName = substr($field, strlen('url_param:'));

            if (! preg_match('/^[a-zA-Z0-9_\-\.]+$/', $paramName)) {
                throw new \InvalidArgumentException(
                    "URL parameter name '{$paramName}' contains invalid characters."
                );
            }

            if ($dataType !== 'campaign_email_clicks') {
                throw new \InvalidArgumentException(
                    "URL parameter fields are only supported for campaign_email_clicks data type."
                );
            }

            return;
        }

        $connector = $this->registry->resolve($integration);
        $matchableFields = $connector->getMatchableFields($integration);

        $allowedValues = [];
        if ($dataType && isset($matchableFields[$dataType])) {
            $allowedValues = array_column($matchableFields[$dataType], 'value');
        } else {
            // Collect all values across data types
            foreach ($matchableFields as $fields) {
                $allowedValues = array_merge($allowedValues, array_column($fields, 'value'));
            }
        }

        if (! in_array($field, $allowedValues, true)) {
            throw new \InvalidArgumentException(
                "Field '{$field}' is not a valid matchable field for platform '{$integration->platform}'."
            );
        }
    }

    /**
     * Extract composite keys from campaign_emails via raw_data JSON.
     */
    protected function processCampaignRecords(AttributionConnector $connector, array $fields): void
    {
        $selects = ['ce.id as record_id'];
        $query = DB::table('campaign_emails as ce')
            ->join('campaign_email_raw_data as cerd', 'cerd.id', '=', 'ce.raw_data_id')
            ->where('ce.workspace_id', $connector->workspace_id)
            ->whereNull('ce.deleted_at');

        foreach ($fields as $i => $field) {
            $jsonPath = '$.' . $field;
            $selects[] = DB::raw("JSON_UNQUOTE(JSON_EXTRACT(cerd.raw_data, '{$jsonPath}')) as field_value_{$i}");
        }

        $query->select($selects)
            ->orderBy('ce.id')
            ->chunk(500, function ($rows) use ($connector, $fields) {
                $this->processCompositeRawDataBatch($connector, $rows, 'campaign_email', count($fields));
            });
    }

    /**
     * Create ARKs for campaign_email_clicks by inheriting the key from their parent campaign_email.
     */
    protected function processClicksFromParentCampaigns(AttributionConnector $connector): void
    {
        DB::table('campaign_email_clicks as cec')
            ->join('attribution_record_keys as ark', function ($join) use ($connector) {
                $join->on('ark.record_id', '=', 'cec.campaign_email_id')
                    ->where('ark.record_type', '=', 'campaign_email')
                    ->where('ark.connector_id', '=', $connector->id);
            })
            ->where('cec.workspace_id', $connector->workspace_id)
            ->whereNull('cec.deleted_at')
            ->select(['cec.id as click_id', 'ark.attribution_key_id'])
            ->orderBy('cec.id')
            ->chunk(500, function ($rows) use ($connector) {
                foreach ($rows as $row) {
                    DB::table('attribution_record_keys')->updateOrInsert(
                        [
                            'connector_id' => $connector->id,
                            'record_type' => 'campaign_email_click',
                            'record_id' => $row->click_id,
                        ],
                        [
                            'attribution_key_id' => $row->attribution_key_id,
                            'workspace_id' => $connector->workspace_id,
                        ]
                    );
                }
            });
    }

    /**
     * Extract composite keys from campaign_email_clicks via the parent campaign_email's raw_data.
     */
    protected function processCampaignClickRecords(AttributionConnector $connector, array $fields): void
    {
        $hasUrlParamFields = collect($fields)->contains(fn ($f) => str_starts_with($f, 'url_param:'));

        $selects = ['cec.id as record_id'];
        $query = DB::table('campaign_email_clicks as cec')
            ->join('campaign_emails as ce', 'ce.id', '=', 'cec.campaign_email_id')
            ->join('campaign_email_raw_data as cerd', 'cerd.id', '=', 'ce.raw_data_id')
            ->where('cec.workspace_id', $connector->workspace_id)
            ->whereNull('cec.deleted_at');

        if ($hasUrlParamFields) {
            $query->leftJoin('campaign_email_click_raw_data as cecrd', 'cecrd.id', '=', 'cec.raw_data_id');
        }

        foreach ($fields as $i => $field) {
            if (str_starts_with($field, 'url_param:')) {
                $paramName = substr($field, strlen('url_param:'));
                $jsonPath = '$.' . $paramName;
                $selects[] = DB::raw("JSON_UNQUOTE(JSON_EXTRACT(cecrd.url_params, '{$jsonPath}')) as field_value_{$i}");
            } else {
                $jsonPath = '$.' . $field;
                $selects[] = DB::raw("JSON_UNQUOTE(JSON_EXTRACT(cerd.raw_data, '{$jsonPath}')) as field_value_{$i}");
            }
        }

        $query->select($selects)
            ->orderBy('cec.id')
            ->chunk(500, function ($rows) use ($connector, $fields) {
                $this->processCompositeRawDataBatch($connector, $rows, 'campaign_email_click', count($fields));
            });
    }

    /**
     * Extract composite keys from conversion_sales via raw_data JSON.
     * Voluum uses -TS resolution; other platforms use direct JSON_EXTRACT.
     */
    protected function processConversionRecords(
        AttributionConnector $connector,
        array $fields,
        Integration $integration,
    ): void {
        if ($integration->platform === 'voluum') {
            $this->processVoluumConversionRecords($connector, $fields);
        } else {
            $this->processDirectConversionRecords($connector, $fields);
        }
    }

    /**
     * Voluum: resolve friendly names to customVariableN values via -TS CASE expressions.
     */
    protected function processVoluumConversionRecords(AttributionConnector $connector, array $fields): void
    {
        $selects = ['cs.id as record_id'];

        foreach ($fields as $i => $friendlyName) {
            $caseExpression = $this->buildVoluumCaseExpression($friendlyName);
            $selects[] = DB::raw("{$caseExpression} as field_value_{$i}");
        }

        DB::table('conversion_sales as cs')
            ->join('conversion_sale_raw_data as csrd', 'csrd.id', '=', 'cs.raw_data_id')
            ->where('cs.workspace_id', $connector->workspace_id)
            ->whereNull('cs.deleted_at')
            ->select($selects)
            ->orderBy('cs.id')
            ->chunk(500, function ($rows) use ($connector, $fields) {
                $this->processCompositeRawDataBatch($connector, $rows, 'conversion_sale', count($fields));
            });
    }

    /**
     * Non-Voluum conversions: direct JSON_EXTRACT on raw_data.
     */
    protected function processDirectConversionRecords(AttributionConnector $connector, array $fields): void
    {
        $selects = ['cs.id as record_id'];

        foreach ($fields as $i => $field) {
            $jsonPath = '$.' . $field;
            $selects[] = DB::raw("JSON_UNQUOTE(JSON_EXTRACT(csrd.raw_data, '{$jsonPath}')) as field_value_{$i}");
        }

        DB::table('conversion_sales as cs')
            ->join('conversion_sale_raw_data as csrd', 'csrd.id', '=', 'cs.raw_data_id')
            ->where('cs.workspace_id', $connector->workspace_id)
            ->whereNull('cs.deleted_at')
            ->select($selects)
            ->orderBy('cs.id')
            ->chunk(500, function ($rows) use ($connector, $fields) {
                $this->processCompositeRawDataBatch($connector, $rows, 'conversion_sale', count($fields));
            });
    }

    /**
     * Build a CASE expression that resolves a Voluum friendly name to the
     * correct customVariableN value by matching against -TS fields.
     */
    protected function buildVoluumCaseExpression(string $friendlyName): string
    {
        // The friendly name is already validated against getMatchableFields()
        $escaped = addslashes($friendlyName);

        $branches = [];
        for ($n = 1; $n <= 10; $n++) {
            $branches[] = "WHEN JSON_UNQUOTE(JSON_EXTRACT(csrd.raw_data, '$.\"customVariable{$n}-TS\"')) = '{$escaped}'"
                . " THEN JSON_UNQUOTE(JSON_EXTRACT(csrd.raw_data, '$.customVariable{$n}'))";
        }

        return 'CASE ' . implode(' ', $branches) . ' ELSE NULL END';
    }

    /**
     * Process a batch of composite raw_data query results: normalize field values,
     * concatenate with |, hash, and upsert keys.
     */
    protected function processCompositeRawDataBatch($connector, $rows, string $recordType, int $fieldCount): void
    {
        foreach ($rows as $row) {
            $values = [];
            $skip = false;

            for ($i = 0; $i < $fieldCount; $i++) {
                $fieldName = "field_value_{$i}";
                $value = $row->$fieldName;

                if ($value === null || $value === '' || $value === 'null') {
                    $skip = true;
                    break;
                }

                $values[] = strtolower(trim($value));
            }

            if ($skip || empty($values)) {
                continue;
            }

            $compositeValue = implode('|', $values);
            $hexHash = hash('sha256', $compositeValue);
            $key = $this->findOrCreateKey($connector, $hexHash, $compositeValue);

            DB::table('attribution_record_keys')->updateOrInsert(
                [
                    'connector_id' => $connector->id,
                    'record_type' => $recordType,
                    'record_id' => $row->record_id,
                ],
                [
                    'attribution_key_id' => $key->id,
                    'workspace_id' => $connector->workspace_id,
                ]
            );
        }
    }

    /**
     * Find or create an attribution key.
     * Uses raw binary comparison for reliable cross-DB support.
     */
    protected function findOrCreateKey(AttributionConnector $connector, string $hexHash, string $value): AttributionKey
    {
        $binaryHash = hex2bin($hexHash);

        $key = AttributionKey::where('workspace_id', $connector->workspace_id)
            ->where('connector_id', $connector->id)
            ->whereRaw('key_hash = ?', [$binaryHash])
            ->first();

        if (! $key) {
            $key = new AttributionKey;
            $key->workspace_id = $connector->workspace_id;
            $key->connector_id = $connector->id;
            $key->key_hash = $hexHash; // BinaryHash cast converts hex → binary
            $key->key_value = $value;
            $key->save();
        }

        return $key;
    }
}
