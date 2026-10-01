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
7. [Executive Advisor Insights & Portfolio Intelligence](#executive-advisor-insights--portfolio-intelligence)
8. [Dynamic Signals & Progressive Disclosure](#dynamic-signals--progressive-disclosure)
9. [Advanced Options Tracking & Collateral Engine](#advanced-options-tracking--collateral-engine)
10. [Tax Engine and Portfolio Performance Accounting](#tax-engine-and-portfolio-performance-accounting)
11. [LLM AI Analysis and Option Signals](#llm-ai-analysis-and-option-signals)
12. [Security, Legal Disclaimers, and Read-Only Guardrails](#security-legal-disclaimers-and-read-only-guardrails)
13. [Frontend Architecture and UI Design Standards](#frontend-architecture-and-ui-design-standards)
14. [Configuration Parameters and System Impact](#configuration-parameters-and-system-impact)
15. [Implementation Status (Completed vs. Mocked)](#implementation-status-completed-vs-mocked)

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
- [System Architecture and High-Level Design](doc/architecture.md): Symfony 8.1 framework design, layered service architecture, modular JS controllers, and daemon execution.
- [Capital Flywheel Compounding Engine](doc/flywheel-engine.md): Mathematical formulations, unencumbered share discovery, DTE targeting, BTC early profit exit thresholds, Advisor insights, and dynamic progressive signals.
- [Tax Engine and Portfolio Performance Accounting](doc/tax-engine.md): FIFO lot matching, holding period determination, taxable vs. retirement IRA classification, option premium tax realization, and pre-tax/after-tax growth curves relative to SPY.
- [Broker Integrations, Options Analytics, and Data Ingestion](doc/broker-integrations.md): Multi-broker adapter pattern, OCC options parsing, moneyness valuation, Schwab OAuth 2.0 flow, nickname resolution, and transaction deduplication.
- [LLM Strategy Router and Signal Analysis](doc/llm-analysis.md): Multi-provider AI router, prompt engineering, and options chain evaluation.
- [Database Schema and Persistent Caching](doc/database-caching.md): SQLite relational schema, Doctrine entity mappings, 30-day extended corporate cache TTLs, and multi-tier persistent caching.
- [Security Architecture, Guardrails, and Legal Disclaimers](doc/security-guardrails.md): Read-only design principles, legal disclaimer workflows, token isolation, credential management, and PII masking.

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
- **Template System:** Twig Templating Engine with modular component partials
- **Frontend Architecture:** Modular JavaScript controllers (`public/js/`) and custom Vanilla CSS (`public/css/`)
- **Frontend Charting:** Chart.js (v4.4.1) via CDN
- **Typography and Assets:** Google Fonts (Outfit, Inter) and Google Material Symbols Outlined icons (strictly no emojis)

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
| **Equity Price Quotes** | `GET /api/v1/quote` | **Finnhub API** | Fetches active real-time ticker prices (gated to US market hours). |
| **Company Payout Calendars** | `GET /api/v1/stock/dividend2` | **Finnhub API** | Retrieves historical and upcoming cash dividend pay dates (30-day cache). |
| **Corporate Earnings** | `GET /api/v1/calendar/earnings` | **Finnhub API** | Resolves corporate earnings announcement calendars. |
| **Company Profiles & CUSIP** | `GET /api/v1/stock/profile2` | **Finnhub API** | Resolves company metadata and CUSIP mappings (30-day cache). |
| **Stock Splits** | `GET /api/v1/stock/split` | **Finnhub API** | Ingests historical share split events (30-day cache). |
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

## Executive Advisor Insights & Portfolio Intelligence

Implemented in [AdvisorService.php](src/Service/AdvisorService.php), the Executive Advisor engine surfaces prioritized, real-time nuggets across three pillars:

1. **Tax Savings & Asset Location:**
   - **Tax-Loss Harvesting (TLH) Scanner:** Identifies unrealized tax loss clusters (> $100 per lot, > $250 aggregate) and alerts the user to harvest capital losses against capital gains or $3,000 ordinary income.
   - **Tax Drag / Asset Location Analyzer:** Detects bond or high-dividend funds (`SGOV`, `BIL`, `TLT`, `AGG`, `BND`, `JEPI`, `JEPQ`, `XYLD`) held in taxable accounts exceeding $1,000 and recommends relocation to IRAs.
2. **Income Generation & Cash Yield:**
   - **Idle Cash Yield Scanner:** Evaluates unencumbered liquid cash (> $5,000) not pledged to puts and projects annual yields (~4.5%-5.0%) achievable via ultra-short Treasury sweep.
   - **Covered Call Potential:** Identifies positions with 100+ unencumbered shares available for immediate covered call writes.
3. **Growth & Concentration Risk:**
   - **Single-Stock Exposure:** Warns when any single ticker exceeds **25%** of total liquidation value.

---

## Dynamic Signals & Progressive Disclosure

Implemented in [DynamicSignalService.php](src/Service/DynamicSignalService.php), the dynamic signal subsystem synthesizes multi-API underwriting data into a **Two-Tier Progressive Disclosure** architecture:

- **Tier 1 (Executive Summary):** Clear, actionable signal (Buy Date, Target Exit Date, Target DTE, Suggested Strike, Recommended Dollar Allocation, 1-Line Thesis, Profit-Exit Rule, Trailing Stop-Loss).
- **Tier 2 (Deep Underwriting Audit):** Wall Street consensus range (Target Mean, High, Low), Analyst Buy/Hold/Sell percentage distributions, and Earnings Collision timelines.
- **Binary Earnings Collision Avoidance:** Automatically shortens option horizons to exit contracts **4 days prior to earnings announcements** ($\text{Exit DTE} = \max(14, \text{Days to Earnings} - 4)$), eliminating IV crush risk.

---

## Advanced Options Tracking & Collateral Engine

The [BrokerManagerService.php](src/Service/BrokerManagerService.php) features an advanced options valuation engine:

1. **OCC String Parsing:** Standardizes options symbols (`NVDA 260807C00215000`) into underlying root, expiration date, strike price, and contract type.
2. **Moneyness & Distance Calculations:**
   - Computes real-time **In-The-Money (ITM)**, **Out-Of-The-Money (OTM)**, or **At-The-Money (ATM)** status with dollar distance and percentage buffer.
3. **Break-Even Price Modeling:**
   - Covered Calls: $\text{Strike} + \text{Premium Collected}$
   - Cash-Secured Puts: $\text{Strike} - \text{Premium Collected}$
4. **Collateral Ringfencing:**
   - **100% Cash-Secured Put Collateral:** Strictly ringfences $|\text{Quantity}| \times \text{Strike} \times 100$ and deducts it from liquid cash balances to prevent margin calls.
   - **Share Pledging:** Pledges 100 shares per short call contract against active stock holdings.

---

## Tax Engine and Portfolio Performance Accounting

The application includes an automated accounting subsystem implemented in [TaxEngine.php](src/Service/TaxEngine.php) and [PerformanceHistoryService.php](src/Service/PerformanceHistoryService.php):

1. **FIFO Lot Matching:** Chronologically pairs stock and ETF sell executions against purchase lots to calculate cost basis and net capital gain/loss.
2. **Holding Term Classification:**
   - **Short-Term (< 365 Days):** Taxed at standard ordinary income rate (20% default).
   - **Long-Term (≥ 365 Days):** Taxed at preferential long-term capital gains rate (15% default).
3. **Account Tax Status Separation:** Distinguishes between **Taxable Accounts** and **Tax-Advantaged Retirement Accounts** (IRA, Roth, PCRA, 401k, Rollover) where capital gains tax is 0%.
4. **Option Premium Accounting:** Queues written option premiums upon Sell-to-Open (STO), realizes gains/losses upon Buy-to-Close (BTC), and recognizes full premium realization upon expiration or assignment.
5. **Growth Curves and SPY Benchmark Indexing:** Normalizes portfolio performance against starting equity and computes relative S&P 500 (SPY) benchmark growth across 1M, 3M, 6M, YTD, 1Y, and 2Y horizons.

For full accounting rules, see [Tax Engine Documentation](doc/tax-engine.md).

---

## LLM AI Analysis and Option Signals

The application leverages Large Language Models (Google Gemini, Anthropic Claude, OpenAI) to act as an automated option strategist:

- **Strike Price Optimizations:** Evaluates delta, implied volatility (IV), and bid-ask spreads.
- **Support and Resistance Probability:** Analyzes stock trends, support thresholds, and estimated earnings impact.
- **Prompt Structure:** The option chain is serialized into a condensed text representation alongside your cost basis. The model processes this data to compute a recommended strike price, expected yield, and safety cushion score.

For prompt formats and provider routing, see [LLM Strategy Router Documentation](doc/llm-analysis.md).

---

## Security, Legal Disclaimers, and Read-Only Guardrails

To protect capital and comply with self-directed account safety rules, this project enforces **strict read-only guardrails**:

1. **Write-Action Block:** All code paths capable of placing trades or executing assignments are hard-blocked at the system layer unless trading is explicitly enabled.
2. **Legal Disclaimer Protocol:** Features mandatory disclaimer verification (`GET /api/disclaimer/status`, `POST /api/disclaimer/acknowledge`) and modal gating before generating live signals.
3. **Access Token Encapsulation:** Access tokens and refresh tokens are stored locally inside the SQLite database (`var/data.db`). Raw tokens are never sent to the browser.
4. **Non-PII Masking:** Account numbers are masked on-the-fly (`***3261`) before being returned by the controller.

For full security specifications, see [Security Architecture and Guardrails](doc/security-guardrails.md).

---

## Frontend Architecture and UI Design Standards

The presentation layer is built with high aesthetic standards and modular code organization:

- **Modular JavaScript Controllers (`public/js/`):** Dedicated page controllers (`portfolio.js`, `screener.js`, `history.js`, `discover.js`, `setup.js`) handling asynchronous data fetching, state management, and modal interactions.
- **Component Partial Templates (`templates/screener/partials/`):** Clean separation of views into partials (`_dashboard_options_card.html.twig`, `_portfolio_options.html.twig`, `_portfolio_holdings.html.twig`, `_portfolio_calendar.html.twig`, `_portfolio_history.html.twig`).
- **Iconography Standards:** Strictly utilizes Google Material Symbols Outlined (`<span class="material-symbols-outlined">...</span>`) across all UI elements; **zero emojis** are used.
- **Stylesheet Standards:** External CSS stylesheets (`public/css/screener.css`, `public/css/settings.css`, `public/css/tax_center.css`) without embedded `<style>` blocks in Twig templates.

---

## Configuration Parameters and System Impact

System configurations are managed in [AppConfigService.php](src/Service/AppConfigService.php) and stored in SQLite:

### Flywheel and Trade Parameters

- **Covered Call Out-Of-The-Money Percentage** (default `0.06`): Selects option strikes that are 6% out-of-the-money.
- **Covered Call Cost Basis Buffer** (default `1.02`): Demands a 2% buffer above stock purchase price, protecting principal capital.
- **Covered Call DTE Target** (default `35`): Targets contracts expiring in 35 days, capturing optimal theta decay.
- **Covered Call Minimum Shares** (default `100`): Enforces a strict minimum of 100 shares for Covered Call writes.
- **Early Exit BTC Profit Threshold** (default `50.0`): Recommends a **Buy-To-Close (BTC)** order once 50% of sold premium has decayed.

### Caching Layers and API Gating

- **`cache.ttl.broker.portfolio` (default `60`):** Caches live portfolio balances for 1 minute.
- **`cache.ttl.broker.history` (default `604800`):** Caches transaction aggregates for 7 days.
- **`cache.ttl.finnhub.quote` (default `900`):** Caches real-time stock quotes for 15 minutes. Restricted strictly to US market hours (Mon–Fri 9:30 AM – 4:00 PM ET).
- **`cache.ttl.finnhub.earnings` (default `604800`):** Caches corporate earnings calendars for 7 days.
- **`cache.ttl.finnhub.search` (default `1209600`):** Caches symbol search queries for 14 days.
- **`cache.ttl.finnhub.dividends`, `cache.ttl.finnhub.profile`, and `cache.ttl.finnhub.splits` (default `2592000`):** Extended 30-day cache for corporate dividend schedules, company profiles/CUSIPs, and historical stock splits.
- **Once-a-Day Gated History:** History fetching is restricted to query external endpoints only once a day. Users can manually bypass this gate using the **Force Pull Latest** button in the UI.

For cache schema details, see [Database Schema and Persistent Caching](doc/database-caching.md).

---

## Implementation Status (Completed vs. Mocked)

### Completed and Live Features

- **Schwab OAuth 2.0 Integration:** Full authentication flow, secure token refreshes, and `/accounts` fetching.
- **Schwab Nicknames Resolution:** Dynamic lookup of nicknames from Schwab's `/userPreference` API.
- **Advanced Options Valuation Engine:** Moneyness (ITM/OTM/ATM), break-even pricing, and 100% Cash-Secured Put collateral ringfencing.
- **Executive Advisor Insights:** Tax-loss harvesting scanning, high-yield asset location checks, and idle cash yield maximization.
- **Dynamic Progressive Signal Engine:** Wall Street consensus analysis, two-tier progressive disclosure, and earnings collision avoidance.
- **Legal Disclaimer Gating:** Session and persistent disclaimer verification modal.
- **Persistent Transactions Cache:** Incremental merging of transactions into a local SQLite cache with an indefinite **1-year TTL**.
- **Gated Daily Refreshes:** Gated historical API query to run at most once a day, with a manual override `Force Pull Latest` UI button.
- **Dynamic Chronological Calendar:** Filters option expirations, projected dividend cash flows, and transaction logs.
- **Multi-Provider LLM Integration:** Pluggable AI engine supporting Google Gemini, OpenAI, and Anthropic Claude.
- **Modular Frontend Architecture:** Decoupled JS controllers and reusable Twig partials adhering to strict Material Symbols design standards.

### Simulated and Mocked Components

- **Non-Schwab Broker APIs:** Alpaca, Robinhood, IBKR, E*TRADE, and Tastytrade adapters provide interface stubs (they implement [BrokerInterface](src/Broker/BrokerInterface.php) and return mock structures when active broker is switched).
- **Order Execution:** Order routing and trade placement are entirely non-existent. The application is strictly a read-only advisor and screener and does not contain code pathways to place live orders.

