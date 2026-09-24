# StockScreener and Capital Flywheel Compounding Hub

An options analysis, compounding flywheel, and live multi-account portfolio tracker built on Symfony 8.1 and integrated with Charles Schwab API, Finnhub, and multi-provider Large Language Models (Google Gemini, OpenAI, Anthropic Claude).

---

## Table of Contents

1. [Overview and Industry Context](#overview-and-industry-context)
2. [Design and Architecture Documentation](#design-and-architecture-documentation)
3. [Getting Started (Local Setup)](#getting-started-local-setup)
4. [System Architecture and Multi-Broker Interface](#system-architecture-and-multi-broker-interface)
5. [Data Provenance and Integration Flow](#data-provenance-and-integration-flow)
6. [Capital Flywheel Compounding Engine](#capital-flywheel-compounding-engine)
7. [Tax Engine and Portfolio Performance Accounting](#tax-engine-and-portfolio-performance-accounting)
8. [LLM AI Analysis and Option Signals](#llm-ai-analysis-and-option-signals)
9. [Security and Strict Read-Only Guardrails](#security-and-strict-read-only-guardrails)
10. [Signal Calculation Logic and Covered Call Rules](#signal-calculation-logic-and-covered-call-rules)
11. [Configuration Parameters and System Impact](#configuration-parameters-and-system-impact)
12. [Implementation Status (Completed vs. Mocked)](#implementation-status-completed-vs-mocked)

---

## Overview and Industry Context

The **Capital Flywheel** is an options-based capital generation engine that compounds premium yields by systematically cycling between two primary cash-flow strategies:

1. **Cash-Secured Puts (CSP):** Selling puts below the market price on high-conviction equities to collect upfront cash premium. If assigned, shares are acquired at a net discount.
2. **Covered Calls (CC):** Selling call options against accumulated stock blocks. This generates ongoing cash premium. If called away, capital gains are locked in and cash is redeployed back into CSPs.

This application connects to **live brokerage account balances and positions**, aggregates forward-looking calendar cash releases, tracks historical transactions, calculates pre-tax and after-tax growth curves, and leverages **Large Language Models** to analyze options chains and offer risk-adjusted trade suggestions.

For a detailed technical and algorithmic exploration of the strategy, refer to the [Capital Flywheel Engine Specification](doc/flywheel-engine.md).

---

## Design and Architecture Documentation

Detailed technical design documents covering all aspects of the system are available in the `doc/` directory:

- [Design Documentation Hub](doc/README.md): Index and architectural component overview.
- [System Architecture and High-Level Design](doc/architecture.md): Symfony 8.1 framework design, layered service architecture, and daemon execution.
- [Capital Flywheel Compounding Engine](doc/flywheel-engine.md): Mathematical formulations, unencumbered share discovery, DTE targeting, and BTC early profit exit thresholds.
- [Tax Engine and Portfolio Performance Accounting](doc/tax-engine.md): FIFO lot matching, holding period determination, taxable vs. retirement IRA classification, option premium tax realization, and pre-tax/after-tax growth curves relative to SPY.
- [Broker Integrations and Data Ingestion Flow](doc/broker-integrations.md): Multi-broker adapter pattern, Schwab OAuth 2.0 flow, nickname resolution, and transaction deduplication.
- [LLM Strategy Router and Signal Analysis](doc/llm-analysis.md): Multi-provider AI router, prompt engineering, and options chain evaluation.
- [Database Schema and Persistent Caching](doc/database-caching.md): SQLite relational schema, Doctrine entity mappings, and multi-tier persistent caching.
- [Security Architecture and Guardrails](doc/security-guardrails.md): Read-only design principles, token isolation, credential management, and PII masking.

---

## Getting Started (Local Setup)

Follow these steps to run the application locally on macOS or Linux:

### 1. Prerequisites

- **PHP 8.4+** with the following extensions: `sqlite3`, PDO SQLite, `curl`, `mbstring`, `openssl`, `xml`.
- **Composer** (PHP dependency manager).
- **Symfony CLI** (recommended for local web server).

### Technology Stack

- **Backend Framework:** Symfony 8.1
- **Language Runtime:** PHP 8.4+
- **Database Engine:** SQLite (Local storage file `var/data.db`)
- **ORM / Database Migrations:** Doctrine ORM (v3.6) and Doctrine Migrations
- **Template System:** Twig Templating Engine
- **Frontend CSS / Layout:** Custom Vanilla CSS (Dark theme, responsive grid layouts in `public/css/screener.css`)
- **Frontend Charting:** Chart.js (v4.4.1) via CDN
- **Typography and Assets:** Google Fonts (Outfit, Inter) and Google Material Symbols Outlined icons

### 2. Installation Steps

Clone the repository and navigate to the project root directory:

```bash
# Install PHP dependencies
composer install

# Set up local environment variables
cp .env .env.local
```

Open `.env.local` and review operational runtime configurations (such as the trading-enabled kill switch).

> [!NOTE]
> API credentials (Schwab Developer App Key/Secret, Finnhub API Key, LLM API Keys) are not stored in `.env.local`. They are configured securely via the Web Setup Wizard page (`/setup`) or the Settings page (`/settings`) during first-run and stored directly in the local SQLite database (`var/data.db`).

### 3. Initialize Local Database

The application uses a lightweight local SQLite database to store user configurations, tokens, and persistent caches:

```bash
# Run migrations to initialize the schema
php bin/console doctrine:migrations:migrate --no-interaction

# Bootstrap core configuration defaults
php bin/console app:bootstrap-db
```

### 4. Start Local Development Server

Start the local web server using the Symfony CLI:

```bash
symfony server:start -d
```

Your local application will be available at `https://127.0.0.1:8000` with local TLS certificates.

---

## System Architecture and Multi-Broker Interface

The application utilizes a polymorphic, decoupled **Multi-Broker Interface** structured around [BrokerInterface](src/Broker/BrokerInterface.php). This design supports registering multiple distinct brokerage accounts side-by-side:

```
                  +----------------------+
                  | BrokerManagerService |
                  +----------+-----------+
                             |
            +----------------+----------------+
            v                v                v
    +-----------------+ +--------------+ +--------------+
    |  SchwabBroker   | | AlpacaBroker | |  IbkrBroker  | ...
    +-----------------+ +--------------+ +--------------+
```

All broker adapters implement the same interface signature, ensuring the application core remains broker-agnostic:

- `getAccountPortfolio()`: Fetches account balances, buying power, and active positions.
- `getAccountHistory(int $days, bool $forceRefresh)`: Fetches settlement records and cash flow impacts.
- `getOptionChain(string $symbol, float $currentPrice)`: Queries option strikes, bid/ask spreads, and open interest.

For full implementation details, see [Broker Integrations Documentation](doc/broker-integrations.md).

---

## Data Provenance and Integration Flow

The application pulls structural, market, and intelligence data from three distinct integration layers:

| Data Layer | Integration Endpoint | Provider / Origin | Details |
| :--- | :--- | :--- | :--- |
| **Brokerage Balance and Positions** | `GET /trader/v1/accounts` | **Schwab API** | Returns live balances, margins, positions, and average cost basis. |
| **Account Custom Nicknames** | `GET /trader/v1/userPreference` | **Schwab API** | Queries nickname preferences to replace raw account numbers with labels (e.g., `V-Brokerage`, `V-HSA`). |
| **Brokerage Account History** | `GET /accounts/{hash}/transactions` | **Schwab API** | Gathers trade execution logs, cash inflows, and fees. |
| **Equity Price Quotes** | `GET /api/v1/quote` | **Finnhub API** | Fetches active real-time ticker prices. |
| **Company Payout Calendars** | `GET /api/v1/stock/dividend2` | **Finnhub API** | Retrieves historical and upcoming cash dividend pay dates. |
| **Corporate Earnings** | `GET /api/v1/calendar/earnings` | **Finnhub API** | Resolves corporate earnings announcement calendars. |
| **AI Trade Reasoning** | `POST /v1beta/models/...` | **LLM Providers** | Analyzes option chains to generate probability calculations and strike suggestions. |

---

## Capital Flywheel Compounding Engine

The **Flywheel Engine** matches live portfolio holdings against upcoming calendar event projections to generate covered call recommendations:

1. **Unencumbered Share Identification:** The engine scans active equity positions to find blocks of **100+ shares** that are not pledged to active option contracts.
2. **Horizon Selection (DTE):** Selects option contract horizons (typically **30-45 Days to Expiration**) to optimize theta decay curves.
3. **Running Cash Projections:** Integrates upcoming option contract expirations and estimated dividend payments to project cash releases.
4. **Reinvestment Alerts:** Highlights date boundaries when cash collateral is released (e.g., call contract expiration) and prompts you to reinvest that cash immediately into high-yield CSPs.

For full mathematical formulas and edge cases, see [Capital Flywheel Engine Specification](doc/flywheel-engine.md).

---

## Tax Engine and Portfolio Performance Accounting

The application includes an automated accounting subsystem implemented in [TaxEngine.php](src/Service/TaxEngine.php) and [PerformanceHistoryService.php](src/Service/PerformanceHistoryService.php) that computes capital gains, holding periods, and after-tax growth curves:

1. **FIFO Lot Matching:** Chronologically pairs stock and ETF sell executions against purchase lots to calculate cost basis and net capital gain/loss.
2. **Holding Term Classification:**
   - **Short-Term (< 365 Days):** Taxed at the standard ordinary income / short-term capital gains rate (20% default).
   - **Long-Term (≥ 365 Days):** Taxed at the preferential long-term capital gains rate (15% default).
3. **Account Tax Status Separation:** Distinguishes between **Taxable Accounts** (where estimated tax liabilities are deducted) and **Tax-Advantaged Retirement Accounts** (IRA, Roth, PCRA, 401k, Rollover) where capital gains tax is 0%.
4. **Option Premium Accounting:** Tracks written option premiums upon Sell-to-Open (STO), realizes gains/losses upon Buy-to-Close (BTC), and recognizes full premium realization upon expiration or assignment.
5. **Growth Curves and SPY Benchmark Indexing:** Normalizes portfolio performance against starting equity and computes relative S&P 500 (SPY) benchmark growth across 1M, 3M, 6M, YTD, 1Y, and 2Y horizons.

For full accounting rules, IRS option realization details, and data hygiene guardrails, see [Tax Engine Documentation](doc/tax-engine.md).

---

## LLM AI Analysis and Option Signals

The application leverages Large Language Models (Google Gemini, Anthropic Claude, OpenAI) to act as an automated option strategist:

- **Strike Price Optimizations:** Evaluates the delta, implied volatility (IV), and bid-ask spreads of the option chain.
- **Support and Resistance Probability:** Analyzes raw stock trends, historical support thresholds, and estimated earnings impact.
- **Prompt Structure:** The option chain is serialized into a condensed text representation alongside your cost basis. The model processes this data to compute a recommended strike price, expected yield, and safety cushion score.

For prompt formats and provider routing, see [LLM Strategy Router Documentation](doc/llm-analysis.md).

---

## Security and Strict Read-Only Guardrails

To protect capital and comply with self-directed account safety rules, this project enforces **strict read-only guardrails**:

1. **Write-Action Block:** By default, all code paths capable of placing trades or executing assignments are hard-blocked at the system layer unless trading is explicitly enabled in the environment variables.
2. **Access Token Encapsulation:** Access tokens and refresh tokens are stored locally inside the SQLite database (`var/data.db`). The frontend client never has direct access to the raw OAuth tokens; it communicates strictly via sanitized JSON APIs (`/api/broker/history/aggregated`, `/api/flywheel/calendar`).
3. **Non-PII Masking:** Account numbers are masked on-the-fly (`***3261`) before being returned by the controller.

For full security specifications, see [Security Architecture and Guardrails](doc/security-guardrails.md).

---

## Signal Calculation Logic and Covered Call Rules

The **Capital Flywheel Engine** evaluates signals and calculates covered call targets using quantitative rules defined in [FlywheelService.php](src/Service/FlywheelService.php):

1. **Covered Call Strike Target:** Matches stock positions against option chains. The recommended strike is selected using the target out-of-the-money percentage:
   $$
   \text{Target Strike} \ge \text{Current Price} \times (1 + \text{Target OTM \%})
   $$
2. **Cost Basis Buffer:** To prevent selling calls below your cost basis (which locks in a capital loss), strikes must satisfy:
   $$
   \text{Target Strike} \ge \text{Average Cost Basis} \times \text{Cost Basis Buffer}
   $$
3. **Signal Classification:**
   - **CALL:** Triggered when the AI conviction score is high ($\ge 70$) and projected target price upside is high ($>15.0\%$). Indicates long-term bullish holding.
   - **PUT:** Triggered when stock score drops ($<45$) or upside is negative. Advises buying protective puts for hedging.
   - **WHEEL:** Stable middle range. Recommends generating income by writing Cash-Secured Puts (CSP) or Covered Calls.

---

## Configuration Parameters and System Impact

System configurations are managed in [AppConfigService.php](src/Service/AppConfigService.php) and stored in SQLite. Here is what each setting controls:

### Flywheel and Trade Parameters

- **Covered Call Out-Of-The-Money Percentage** (default `0.06`): Selects option strikes that are 6% out-of-the-money, balancing yield vs. upside assignment risk.
- **Covered Call Cost Basis Buffer** (default `1.02`): Demands a 2% buffer above stock purchase price, protecting your principal capital from being called away at a loss.
- **Covered Call DTE Target** (default `35`): Targets contracts expiring in 35 days, capturing optimal theta decay acceleration.
- **Covered Call Minimum Shares** (default `100`): Enforces a strict minimum of 100 shares for Covered Call writes (Option Level 1 compliance).
- **Early Exit BTC Profit Threshold** (default `50.0`): Recommends a **Buy-To-Close (BTC)** order once 50% of the sold premium has decayed, locking in profits and freeing up collateral early.

### Caching Layers and API Gating

- **`cache.ttl.broker.portfolio` (default `60`):** Caches live portfolio balances for 1 minute to keep numbers active without excessive broker API requests.
- **`cache.ttl.broker.history` (default `604800`):** Caches transaction aggregates for 7 days.
- **`cache.ttl.finnhub.quote` (default `900`):** Caches real-time and batch stock quotes for 15 minutes. Live API calls are strictly restricted to US market hours (Mon–Fri 9:30 AM – 4:00 PM ET).
- **`cache.ttl.finnhub.earnings` (default `604800`):** Caches corporate earnings calendars for 7 days.
- **`cache.ttl.finnhub.search` (default `1209600`):** Caches symbol search queries for 14 days (new search terms query API immediately).
- **`cache.ttl.finnhub.dividends`, `cache.ttl.finnhub.profile`, and `cache.ttl.finnhub.splits` (default `2592000`):** Caches dividend calendars, company profiles, CUSIP resolutions, and historical splits for 30 days (new tickers/CUSIPs query API immediately).
- **Once-a-Day Gated History:** History fetching is restricted to query external endpoints only once a day. Subsequent requests on the same day read directly from the persistent SQLite cache (`var/data.db`). Users can manually bypass this gate using the **Force Pull Latest** button in the UI.

For cache schema details, see [Database Schema and Persistent Caching](doc/database-caching.md).

---

## Implementation Status (Completed vs. Mocked)

### Completed and Live Features

- **Schwab OAuth 2.0 Integration:** Full authentication flow, secure token refreshes, and `/accounts` fetching.
- **Schwab Nicknames Resolution:** Dynamic lookup of nicknames from Schwab's `/userPreference` API.
- **Persistent Transactions Cache:** Incremental merging of transactions into a local SQLite cache with an indefinite **1-year TTL** to bypass Schwab rate-limits.
- **Gated Daily Refreshes:** Gated historical API query to run at most once a day, with a manual override `Force Pull Latest` UI button.
- **Dynamic Chronological Calendar:** Filters option expirations, projected dividend cash flows, and transaction logs.
- **Multi-Provider LLM Integration:** Pluggable AI engine supporting Google Gemini, OpenAI, and Anthropic Claude.

### Simulated and Mocked Components

- **Non-Schwab Broker APIs:** Alpaca, Robinhood, IBKR, E*TRADE, and Tastytrade adapters provide interface stubs (they implement [BrokerInterface](src/Broker/BrokerInterface.php) and return mock structures when active broker is switched).
- **Order Execution:** Order routing and trade placement are entirely non-existent. The application is strictly a read-only advisor and screener and does not contain code pathways to place live orders.
