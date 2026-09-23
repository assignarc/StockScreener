# Database Schema and Persistent Caching

This document specifies the SQLite database schema, Doctrine ORM entity model, key-value configuration subsystem, and multi-tier persistent caching architecture used in StockScreener.

---

## 1. Relational Database Architecture

StockScreener uses an embedded local SQLite database (`var/data.db`) managed via Doctrine ORM (v3.6) and Doctrine Migrations. The local-first SQLite design eliminates external database dependencies, provides zero-latency queries, and ensures user financial data remains local.

### Entity Relationship Model

```
+-------------------+             +-------------------+
|      Stock        |             |     Watchlist     |
+-------------------+             +-------------------+
| id (INTEGER PK)   |<----------->| id (INTEGER PK)   |
| symbol (VARCHAR)  |  (Many-to-  | name (VARCHAR)    |
| company_name (STR)|   Many)     | description (TXT) |
| sector (VARCHAR)  |             | created_at (DT)   |
| price (NUMERIC)   |             +-------------------+
| updated_at (DT)   |
+-------------------+

+-------------------+             +-------------------+
|     AppConfig     |             |  PersistentCache  |
+-------------------+             +-------------------+
| id (INTEGER PK)   |             | id (INTEGER PK)   |
| config_key (STR UK|             | cache_key (STR UK)|
| config_val (TXT)  |             | cache_value (JSON)|
| is_encrypted (BOOL|             | expires_at (DT)   |
| updated_at (DT)   |             | updated_at (DT)   |
+-------------------+             +-------------------+
```

---

## 2. Doctrine Entity Specifications

### `Stock` (`src/Entity/Stock.php`)
Stores tracked equities, fundamental metrics, price quotes, and sector classifications.
- **Fields**: `id`, `symbol` (unique), `companyName`, `sector`, `currentPrice`, `peRatio`, `dividendYield`, `updatedAt`.
- **Relationships**: Many-to-Many association with `Watchlist`.

### `Watchlist` (`src/Entity/Watchlist.php`)
Allows grouping of tickers into custom screeners and strategic buckets (e.g., "Dividend Aristocrats", "Tech Growth", "Flywheel Candidates").
- **Fields**: `id`, `name`, `description`, `createdAt`, `stocks` (Collection).

### `AppConfig` (`src/Entity/AppConfig.php`)
Provides a key-value store for application settings, broker credentials, OAuth refresh tokens, and trade parameters.
- **Fields**: `id`, `configKey` (unique string index), `configValue` (text payload), `isEncrypted` (boolean flag), `updatedAt`.
- **Managed via**: `src/Service/AppConfigService.php`.

### `PersistentCache` (`src/Entity/PersistentCache.php`)
Serves as an SQLite-backed caching store with expiration tracking for external API payloads, aggregated ledgers, and options chains.
- **Fields**: `id`, `cacheKey` (unique string index), `cacheValue` (JSON text blob), `expiresAt` (nullable DateTime), `updatedAt`.
- **Managed via**: `src/Service/PersistentCacheService.php`.

---

## 3. Persistent Caching Strategy and TTL Rules

To safeguard external API quotas and ensure application responsiveness, external network requests are routed through `PersistentCacheService`:

| Cache Category | Cache Key Pattern | TTL Duration | Eviction / Refresh Policy |
| :--- | :--- | :--- | :--- |
| **Broker Portfolio** | `broker.portfolio.{accountHash}` | 60 seconds (1 minute) | Time-to-live expiration; quick refresh for active balance tracking. |
| **Broker Transactions** | `broker.history.{accountHash}` | 31,536,000s (1 year) | Incremental append; daily-gated refresh or manual force pull. |
| **Corporate Dividends** | `finnhub.dividends.{symbol}` | 604,800s (7 days) | Cached weekly; invalidated upon force refresh. |
| **Corporate Earnings** | `finnhub.earnings.{symbol}` | 604,800s (7 days) | Cached weekly; refreshed prior to earnings cycles. |
| **Option Chains** | `option_chain.{symbol}` | 300 seconds (5 min) | Fast invalidation during active market hours. |

### Daily-Gated History Synchronization

Broker history endpoints are restricted to query external networks only once per calendar day. Subsequent requests on the same day read directly from SQLite. If the user requests an immediate update, the `forceRefresh` flag bypasses the gate and updates the local cache.

---

## 4. Database Lifecycle and Auto-Provisioning

The SQLite database lifecycle is managed transparently without requiring manual CLI provisioning:
- **`DatabaseBootstrapService`**: Initializes default system configurations, default watchlists, and initial equity universe.
- **`DatabaseAutoProvisionSubscriber`**: Listens to kernel request events. If the database file (`var/data.db`) or tables are missing, it runs migrations and bootstrapping automatically.

---

## 5. Related Design Documents

- [System Architecture and High-Level Design](architecture.md)
- [Capital Flywheel Compounding Engine](flywheel-engine.md)
- [Broker Integrations and Data Ingestion Flow](broker-integrations.md)
- [LLM Strategy Router and Signal Analysis](llm-analysis.md)
- [Security Architecture and Guardrails](security-guardrails.md)
