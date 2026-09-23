# Broker Integrations and Data Ingestion Flow

This document describes the multi-broker abstraction layer, authentication protocols, rate limiting, transaction ingestion pipeline, and market data interfaces in the StockScreener system.

---

## 1. Multi-Broker Adapter Pattern

The brokerage interface layer is designed around the Gang of Four Adapter and Strategy patterns. All brokerage clients implement the unified contract defined in `src/Broker/BrokerInterface.php`:

```
                           +----------------------+
                           |   BrokerInterface    |
                           +----------+-----------+
                                      |
       +---------------+--------------+--------------+---------------+
       |               |              |              |               |
       v               v              v              v               v
+--------------+ +------------+ +------------+ +------------+ +------------+
| SchwabBroker | |AlpacaBroker| | IbkrBroker | | Robinhood- | |Tastytrade- |
|              | |            | |            | |   Broker   | |   Broker   |
+--------------+ +------------+ +------------+ +------------+ +------------+
```

### Core Interface Contract

- `getAccountPortfolio(): array`: Retrieves live balances, settled cash, margin buying power, and active positions with cost-basis data.
- `getAccountHistory(int $days, bool $forceRefresh): array`: Ingests trade executions, dividend receipts, fees, and transfers.
- `getOptionChain(string $symbol, float $currentPrice): array`: Returns strikes, expiration dates, bid/ask spreads, implied volatilities, and Greeks.
- `supportsOptionTrading(): bool`: Returns whether the adapter supports options chain analysis.
- `getBrokerName(): string`: Identifies the adapter in logs and multi-account selectors.

---

## 2. Charles Schwab OAuth 2.0 Integration (`SchwabBroker`)

The primary live brokerage integration connects to Charles Schwab's Trader API.

### Authentication Flow

1. **Authorization Code Grant**: The user initiates authentication via the `/setup` wizard. Schwab redirects the user to their secure login portal.
2. **Callback Handling**: Schwab returns an authorization code to the application callback URL.
3. **Token Exchange**: The backend exchanges the authorization code for a short-lived Access Token (30 minutes) and a long-lived Refresh Token (7 days).
4. **Automated Token Lifecycle**: `BrokerManagerService` detects token expiration timestamps and transparently triggers refresh requests before invoking API endpoints. Tokens are stored in SQLite (`AppConfig`).

```
User Browser                  StockScreener                   Schwab API
     |                              |                              |
     |---- 1. Start OAuth --------->|                              |
     |<--- 2. Redirect to Schwab ---|                              |
     |                              |                              |
     |---- 3. User Logs in directly at Schwab -------------------->|
     |<--- 4. Schwab redirects with Auth Code ---------------------|
     |                              |                              |
     |---- 5. Send Auth Code ------>|                              |
     |                              |---- 6. POST /v1/oauth/token >|
     |                              |<--- 7. Return Access/Refresh |
     |                              |        Tokens & Store in DB  |
     |<--- 8. Auth Success ---------|                              |
```

### Schwab Endpoints Utilized

| Endpoint | Method | Purpose |
| :--- | :--- | :--- |
| `/trader/v1/accounts` | `GET` | Live account balances, positions, margin equity, and cost basis. |
| `/trader/v1/userPreference` | `GET` | Custom account nicknames (e.g., "Retirement IRA", "Primary Taxable"). |
| `/trader/v1/accounts/{accountNumberHash}/transactions` | `GET` | Historical trade settlements, dividend credits, and deposits. |
| `/marketdata/v1/chains` | `GET` | Full options chains for equity symbols. |

---

## 3. Account Nickname Resolution

Raw brokerage API responses expose hashed or truncated account numbers. To provide a clear user experience, `BrokerManagerService` queries Schwab's `/userPreference` endpoint and maps account hashes to user-assigned nicknames (e.g., `V-Brokerage`, `V-HSA`, `V-Roth`). These names are cached and displayed across all portfolio views.

---

## 4. Transaction Ingestion and Daily-Gated Caching

To avoid triggering upstream rate limits and API quotas, the system enforces a daily-gated history fetching pipeline:

```
Request History
      |
      v
Is Cache Valid & Fresh Today?
      |
      +---> [YES] ---> Return merged data from SQLite PersistentCache
      |
      +---> [NO / Force Refresh] ---> Call Broker API (/transactions)
                                            |
                                            v
                               Merge new transactions with SQLite ledger
                                            |
                                            v
                               Save updated ledger with 1-year TTL
```

- **Incremental Deduplication**: Historical transactions are keyed by unique transaction IDs and trade settlement dates to prevent double-counting.
- **Manual Override**: Users can bypass the daily gate via the "Force Pull Latest" button in the history interface.

---

## 5. Schwab CSV Batch Importer (`SchwabCsvImporterService`)

For historical records predating API availability or offline accounts, the application provides a CSV file parsing service:
- Parses Schwab account export files (`.csv`).
- Handles date normalization, symbol extraction, split adjustments, and cash flow categorization (Buy, Sell, Div, Assignment).
- Stores normalized records in SQLite for portfolio performance tracking and tax calculations.

---

## 6. Market Data Ingestion (`FinnhubService`)

Market data for equities and corporate event calendars is ingested from Finnhub:
- **Quote Data (`/api/v1/quote`)**: Real-time stock prices, day highs, lows, and percentage changes.
- **Dividend Calendar (`/api/v1/stock/dividend2`)**: Ex-dividend dates, pay dates, and per-share amounts. Cached for 7 days.
- **Earnings Calendar (`/api/v1/calendar/earnings`)**: Historical and upcoming corporate earnings release dates. Cached for 7 days.

---

## 7. Related Design Documents

- [System Architecture and High-Level Design](architecture.md)
- [Capital Flywheel Compounding Engine](flywheel-engine.md)
- [LLM Strategy Router and Signal Analysis](llm-analysis.md)
- [Database Schema and Persistent Caching](database-caching.md)
- [Security Architecture and Guardrails](security-guardrails.md)
