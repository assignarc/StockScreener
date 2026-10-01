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

---

## 4. Advanced Options Analytics and Collateral Accounting

`BrokerManagerService` includes a specialized options parsing and valuation subsystem that transforms raw broker option positions into structured analytical models:

### 4.1 OCC Symbol Parsing & Underlying Resolution
Option symbols conform to the Options Clearing Corporation (OCC) standard:
$$\text{Pattern: } \underbrace{\text{TICKER}}_{\text{Root}} \quad \underbrace{\text{YYMMDD}}_{\text{Expiration}} \quad \underbrace{\text{[C|P]}}_{\text{Type}} \quad \underbrace{\text{00000000}}_{\text{Strike } \times 1000}$$
- Example: `NVDA 260807C00215000` $\rightarrow$ NVDA, Aug 7, 2026, Call, Strike: $215.00.
- `resolveUnderlyingPrice()` matches the option root ticker back to active equity holdings or cached market quotes to enable real-time valuation.

### 4.2 Moneyness and Buffer Valuation
The system determines the real-time moneyness status and cushion distance:

- **For Call Contracts:**
  $$\text{Distance} = \text{Underlying Price} - \text{Strike}$$
  - $\text{Distance} > \$0.01 \rightarrow \mathbf{ITM}$ (In-the-Money, $\text{Gain \%} = \frac{\text{Distance}}{\text{Strike}} \times 100$)
  - $\text{Distance} < -\$0.01 \rightarrow \mathbf{OTM}$ (Out-of-the-Money, $\text{Buffer \%} = \frac{|\text{Distance}|}{\text{Underlying Price}} \times 100$)
  - $|\text{Distance}| \le \$0.01 \rightarrow \mathbf{ATM}$ (At-the-Money)

- **For Put Contracts:**
  $$\text{Distance} = \text{Strike} - \text{Underlying Price}$$
  - $\text{Distance} > \$0.01 \rightarrow \mathbf{ITM}$
  - $\text{Distance} < -\$0.01 \rightarrow \mathbf{OTM}$
  - $|\text{Distance}| \le \$0.01 \rightarrow \mathbf{ATM}$

### 4.3 Break-Even Price and Collateral Ringfencing
- **Break-Even Price:**
  - **Covered Call / Long Call:** $\text{Break-Even} = \text{Strike} + \text{Premium Collected/Paid}$
  - **Cash-Secured Put / Long Put:** $\text{Break-Even} = \text{Strike} - \text{Premium Collected/Paid}$
- **Collateral Ringfencing:**
  - **Cash-Secured Puts (Short Puts):** Strictly earmarks $100\% \text{ Cash Collateral} = |\text{Quantity}| \times \text{Strike} \times 100$. This amount is deducted directly from liquid available cash to prevent margin traps.
  - **Covered Calls (Short Calls):** Pledges $|\text{Quantity}| \times 100$ shares of underlying stock, reducing the account's unencumbered share count.

---

## 5. Transaction Ingestion and Daily-Gated Caching

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

## 6. Schwab CSV Batch Importer (`SchwabCsvImporterService`)

For historical records predating API availability or offline accounts, the application provides a CSV file parsing service:
- Parses Schwab account export files (`.csv`).
- Handles date normalization, symbol extraction, split adjustments, and cash flow categorization (Buy, Sell, Div, Assignment).
- Stores normalized records in SQLite for portfolio performance tracking and tax calculations.

---

## 7. Market Data Ingestion (`FinnhubService`)

Market data for equities and corporate event calendars is ingested from Finnhub with persistent SQLite caching:
- **Quote Data (`/api/v1/quote`)**: Real-time and batch stock prices. Cached for **15 minutes** (`900s`). Requests are strictly restricted to **US market hours** (Mon–Fri 9:30 AM – 4:00 PM ET); outside market hours, cached/stale quotes are served to protect API quotas.
- **Dividend Calendar (`/api/v1/stock/dividend2`)**: Ex-dividend dates, pay dates, and per-share amounts. Cached for **30 days** (`2,592,000s`).
- **Earnings Calendar (`/api/v1/calendar/earnings`)**: Historical and upcoming corporate earnings release dates. Cached for **7 days** (`604,800s`).
- **Symbol Search (`/api/v1/search`)**: Ticker lookups and directory search. Cached for **14 days** (`1,209,600s`); new search queries fetch immediately from the API.
- **Company Profiles & CUSIP (`/api/v1/stock/profile2`)**: Corporate metadata and CUSIP mappings. Cached for **30 days** (`2,592,000s`); new tickers/CUSIPs trigger an instant live query.
- **Stock Splits (`/api/v1/stock/split`)**: Historical stock split events and adjustments. Cached for **30 days** (`2,592,000s`).

---

## 8. Related Design Documents

- [System Architecture and High-Level Design](architecture.md)
- [Capital Flywheel Compounding Engine](flywheel-engine.md)
- [LLM Strategy Router and Signal Analysis](llm-analysis.md)
- [Database Schema and Persistent Caching](database-caching.md)
- [Security Architecture, Guardrails, and Legal Disclaimers](security-guardrails.md)
- [Tax Engine and Portfolio Performance Accounting](tax-engine.md)

