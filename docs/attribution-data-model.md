# Attribution Data Model

This document describes the attribution data model, its design rationale, and how to query it for reports. Read this before building any report that involves attribution, campaign performance, or revenue allocation.

## Core Concepts

**Attribution** answers: "which marketing campaigns deserve credit for conversions?" A customer may receive multiple email campaigns before purchasing. Attribution models determine how revenue credit is distributed across those campaign touchpoints.

### Entity Hierarchy

```
Organization
 └── Workspace (tenant boundary — all queries scoped here)
      ├── Program → Initiative → Effort     (PIE: marketing hierarchy)
      ├── Integration                        (data source connections)
      ├── CampaignEmail → CampaignEmailClick (campaign touchpoints)
      ├── ConversionSale                     (revenue events)
      ├── AttributionConnector               (links campaign + conversion sources)
      ├── AttributionKey                     (composite key bridging records to efforts)
      ├── AttributionRecordKey               (links individual records to keys)
      └── AttributionResult                  (final attribution output)
```

### Key Resolution Chain

The attribution pipeline connects campaigns to conversions through composite keys:

```
CampaignEmail ──┐                          ┌── ConversionSale
CampaignEmailClick ──┤                     │
                     ▼                     ▼
              AttributionRecordKey   AttributionRecordKey
                     │                     │
                     ▼                     ▼
                 AttributionKey ◄──────────┘
                     │
                     ▼
                   Effort
```

A **composite key** is built from mapped field values (e.g., `from_email|campaign_name`), normalized and SHA-256 hashed. When a campaign record and a conversion record produce the same key hash, they're linked. Each key maps to exactly one effort.

## Schema Reference

### `attribution_results` — The Core Attribution Table

Each row represents: "this conversion was attributed to this effort, via this campaign touchpoint, under this model, with this weight."

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint PK | Auto-increment |
| `workspace_id` | bigint FK | Tenant scope |
| `connector_id` | bigint FK | Which connector produced this result |
| `conversion_type` | varchar(30) | Polymorphic type (currently always `conversion_sale`) |
| `conversion_id` | bigint | FK to the conversion record |
| `effort_id` | bigint FK | Which effort (marketing activity) |
| `campaign_type` | varchar(30) | Polymorphic type (`campaign_email`) |
| `campaign_id` | bigint | FK to the campaign record |
| `model` | varchar(30) | Attribution model (`first_touch`, `last_touch`, `linear`) |
| `weight` | decimal(5,4) | Credit fraction (0.0001–1.0000) |
| `matched_at` | timestamp | When the campaign touchpoint occurred (click/send date) |

**Unique constraint**: `(conversion_type, conversion_id, effort_id, campaign_type, campaign_id, model)`

**Indexes**: `(workspace_id, model)`, `(effort_id, model)`, `(campaign_type, campaign_id)`

### `attribution_keys` — Composite Key Bridge

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint PK | Auto-increment |
| `workspace_id` | bigint FK | Tenant scope |
| `connector_id` | bigint FK | Which connector owns this key |
| `key_hash` | binary(32) | SHA-256 of normalized composite value |
| `key_value` | varchar(500) | Human-readable composite (e.g., `alice@example.com\|welcome-series`) |
| `effort_id` | bigint FK nullable | Resolved effort (set by EffortResolver) |

**Unique constraint**: `(workspace_id, connector_id, key_hash)`

### `attribution_record_keys` — Record-to-Key Links

| Column | Type | Description |
|--------|------|-------------|
| `connector_id` | bigint | Part of composite PK |
| `record_type` | varchar | Part of composite PK (`campaign_email`, `campaign_email_click`, `conversion_sale`) |
| `record_id` | bigint | Part of composite PK |
| `attribution_key_id` | bigint FK | Which key this record maps to |
| `workspace_id` | bigint FK | Tenant scope |

**Composite PK**: `(connector_id, record_type, record_id)` — no auto-increment.

### `attribution_connectors` — Source Configuration

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint PK | Auto-increment |
| `workspace_id` | bigint FK | Tenant scope |
| `name` | varchar | Display name |
| `type` | varchar | `mapped` (auto-create efforts) or `simple` (lookup by code) |
| `campaign_integration_id` | bigint FK | Campaign data source |
| `campaign_data_type` | varchar | e.g., `campaign_emails`, `campaign_email_clicks` |
| `conversion_integration_id` | bigint FK | Conversion data source |
| `conversion_data_type` | varchar | e.g., `conversion_sales` |
| `field_mappings` | JSON | Array of `{campaign: field, conversion: field}` pairs |
| `is_active` | boolean | Whether this connector runs in the pipeline |

### Summary Tables (Pre-Aggregated)

These tables are populated by the summarization pipeline after attribution runs. They power dashboard widgets and trend reports.

**`summary_attribution_daily`** — PK: `(workspace_id, summary_date, model)`

| Column | Type | Description |
|--------|------|-------------|
| `workspace_id` | bigint | Tenant scope |
| `summary_date` | date | Conversion date (`DATE(conversion_sales.converted_at)`) |
| `model` | varchar | Attribution model |
| `attributed_conversions` | int | Count of unique conversions (`COUNT(DISTINCT conversion_id)`) |
| `attributed_revenue` | decimal | `SUM(weight * conversion.revenue)` |
| `total_weight` | decimal | `SUM(weight)` |

**`summary_attribution_by_effort`** — PK: `(workspace_id, effort_id, summary_date, model)`

Same columns as daily, plus `effort_id` in the primary key and grouping.

Note: Under the `linear` model, a single conversion may produce multiple attribution result rows (one per campaign-effort pair). `attributed_conversions` counts **unique conversions**, not result rows. `attributed_revenue` is correct because weights per conversion always sum to 1.0. `total_weight` reflects the sum of all individual weights and may exceed the conversion count for linear.

## Attribution Models

### `first_touch`

The **earliest** campaign touchpoint per conversion gets 100% credit. Answers: "which campaign first engaged this customer?"

- 1 result row per conversion (per connector)
- `weight = 1.0`
- Ordered by `clicked_at` (clicks) or `sent_at` (campaigns)
- Best for measuring awareness and top-of-funnel effectiveness

### `last_touch`

The **latest** campaign touchpoint per conversion gets 100% credit. Answers: "which campaign closed this customer?"

- 1 result row per conversion (per connector)
- `weight = 1.0`
- Best for short sales cycles and direct response

### `linear`

All unique (effort, campaign) touchpoint pairs per conversion share credit equally. Answers: "which campaigns contributed to this customer's journey?"

- N result rows per conversion (one per unique effort-campaign pair)
- `weight = 1/N`
- Best for understanding full journey when no single touchpoint dominates

### Future Models (Data Model Supports, Not Yet Implemented)

The schema preserves per-conversion, per-touchpoint granularity, which enables:

- **Time-decay**: Increasing credit toward conversion (configurable half-life)
- **U-shaped (position-based)**: 40% first / 40% last / 20% distributed to middle touchpoints
- **W-shaped**: 30% first / 30% lead creation / 30% opportunity / 10% remaining
- **Algorithmic/data-driven**: ML-assigned weights from historical patterns

These models require timestamped touchpoint sequences per conversion — which `attribution_results` provides via `(conversion_id, campaign_id, matched_at)`.

## Pipeline Flow

```
ProcessAttribution job (per workspace):
  1. linkClicksToCampaigns()     — resolve orphaned clicks to campaigns
  2. foreach connector:
     a. ConnectorKeyProcessor    — extract composite keys from raw_data
     b. EffortResolver           — auto-create/lookup effort per key
     c. foreach model:
        AttributionEngine        — match conversions to campaigns, apply model
  3. RunSummarization            — populate summary tables
```

## Query Patterns for Reports

### Campaign Revenue (Direct Join)

The most common report. Attribution results link directly to both the campaign and the conversion:

```sql
SELECT
    ce.id,
    ce.name,
    ce.sent,
    ce.opens,
    ce.clicks,
    SUM(cs.revenue * ar.weight) AS attributed_revenue
FROM attribution_results ar
JOIN conversion_sales cs
    ON cs.id = ar.conversion_id
    AND ar.conversion_type = 'conversion_sale'
    AND cs.deleted_at IS NULL
JOIN campaign_emails ce
    ON ce.id = ar.campaign_id
    AND ar.campaign_type = 'campaign_email'
WHERE ar.workspace_id = ?
    AND ar.model = 'last_touch'
GROUP BY ce.id, ce.name, ce.sent, ce.opens, ce.clicks
ORDER BY attributed_revenue DESC;
```

### Conversion Attribution Detail

Show which campaigns and efforts are credited for each conversion:

```sql
SELECT
    cs.external_id,
    cs.revenue,
    e.name AS effort_name,
    ce.name AS campaign_name,
    ar.model,
    ar.weight,
    ar.matched_at
FROM attribution_results ar
JOIN conversion_sales cs
    ON cs.id = ar.conversion_id
    AND ar.conversion_type = 'conversion_sale'
JOIN efforts e ON e.id = ar.effort_id
LEFT JOIN campaign_emails ce
    ON ce.id = ar.campaign_id
    AND ar.campaign_type = 'campaign_email'
WHERE ar.workspace_id = ?
ORDER BY cs.converted_at DESC, ar.model;
```

### Effort Performance

Revenue by effort, using the summary table for fast aggregation:

```sql
SELECT
    e.name AS effort_name,
    SUM(sae.attributed_conversions) AS attributed_conversions,
    SUM(sae.attributed_revenue) AS attributed_revenue,
    SUM(sae.total_weight) AS total_weight
FROM summary_attribution_by_effort sae
JOIN efforts e ON e.id = sae.effort_id
WHERE sae.workspace_id = ?
    AND sae.model = 'last_touch'
    AND sae.summary_date BETWEEN ? AND ?
GROUP BY e.id, e.name
ORDER BY attributed_revenue DESC;
```

Note: Under `linear`, effort-level revenue is proportional to the number of campaign touchpoints under that effort. An effort with 3 campaigns gets 3x the weight of an effort with 1 campaign (for the same conversion). For `first_touch`/`last_touch`, each conversion maps to exactly one effort-campaign pair, so effort-level rollups are straightforward.

### Model Comparison

Compare how different models allocate revenue to the same campaign:

```sql
SELECT
    ce.name AS campaign_name,
    ar.model,
    SUM(cs.revenue * ar.weight) AS attributed_revenue,
    COUNT(*) AS conversions
FROM attribution_results ar
JOIN conversion_sales cs
    ON cs.id = ar.conversion_id
    AND ar.conversion_type = 'conversion_sale'
JOIN campaign_emails ce
    ON ce.id = ar.campaign_id
    AND ar.campaign_type = 'campaign_email'
WHERE ar.workspace_id = ?
GROUP BY ce.name, ar.model
ORDER BY ce.name, ar.model;
```

### Daily Attribution Trend (Fast, From Summary)

```sql
SELECT
    summary_date,
    attributed_conversions,
    attributed_revenue
FROM summary_attribution_daily
WHERE workspace_id = ?
    AND model = 'last_touch'
    AND summary_date BETWEEN ? AND ?
ORDER BY summary_date;
```

## Revenue Calculation

Revenue is always calculated as:

```
attributed_revenue = conversion_sales.revenue * attribution_results.weight
```

- For `first_touch` and `last_touch`: `weight = 1.0`, so attributed revenue equals the full conversion revenue
- For `linear`: `weight = 1/N`, so the conversion's revenue is split equally across N touchpoints
- Aggregate with `SUM()` to roll up by campaign, effort, date, or any other dimension

There is no double aggregation or indirect calculation. The join path is always:

```
attribution_results → conversion_sales  (via conversion_id)
attribution_results → campaign_emails   (via campaign_id)
attribution_results → efforts           (via effort_id)
```

## Date Semantics

Two date concepts exist in the attribution system:

| Date | Source | Used For |
|------|--------|----------|
| `matched_at` | Campaign touchpoint date (`clicked_at` or `sent_at`) | When the campaign interaction happened. Useful for "which campaigns were active when?" |
| `converted_at` | `conversion_sales.converted_at` | When the revenue event occurred. Used for `summary_date` in summary tables. Useful for financial reporting ("attributed revenue in March"). |

Summary tables use **conversion date** for `summary_date`, ensuring financial reports reflect when money came in, not when campaigns ran.

## Multi-Tenant Isolation

Every query must be scoped by `workspace_id`. All tables carry this column and all indexes include it. The pipeline enforces this — connectors, keys, and results inherit workspace scope from their parent records. No cross-workspace data leakage is possible.

## Extensibility

### New Campaign Types

The `campaign_type` polymorphic column supports future campaign entity types without schema changes:

- `campaign_email` — email campaigns (current)
- `campaign_ad` — paid ad campaigns (future)
- `campaign_sms` — SMS campaigns (future)

The `AttributionEngine` would add new matching paths for each type, but the result schema stays the same.

### New Conversion Types

Similarly, `conversion_type` supports future conversion entities:

- `conversion_sale` — revenue events (current)
- `conversion_lead` — lead generation events (future)

### New Attribution Models

Add new model strings (e.g., `time_decay`, `u_shaped`) with custom weight calculation logic in `AttributionEngine`. The schema, summarization, and all report queries work unchanged — they're model-agnostic by design.
