# StockScreener & Capital Flywheel Compounding Hub

An options analysis, compounding flywheel, and live multi-account portfolio tracker built on Symfony 7 and integrated with the Schwab API, Finnhub, and Google Gemini AI.

---

## 📖 Table of Contents

1. [Overview &amp; Industry Context](#-overview--industry-context)
2. [Getting Started (Local Setup)](#-getting-started-local-setup)
3. [System Architecture &amp; Multi-Broker Interface](#-system-architecture--multi-broker-interface)
4. [Data Provenance &amp; Integration Flow](#-data-provenance--integration-flow)
5. [Capital Flywheel Compounding Engine](#-capital-flywheel-compounding-engine)
6. [Gemini AI Analysis &amp; Option Signals](#-gemini-ai-analysis--option-signals)
7. [Security &amp; Strict Read-Only Guardrails](#-security--strict-read-only-guardrails)
8. [Implementation Status (Completed vs. Mocked)](#-implementation-status-completed-vs-mocked)

---

## 🌟 Overview & Industry Context

The **Capital Flywheel** is an options-based capital generation engine that compounds premium yields by systematically cycling between two primary cash-flow strategies:

1. **Cash-Secured Puts (CSP):** Selling puts below the market price on high-conviction equities to collect upfront cash premium. If assigned, you acquire shares at a discount.
2. **Covered Calls (CC):** Selling call options against your accumulated stock blocks. This generates ongoing cash premium. If called away, you lock in capital gains and redeploy the cash back into CSPs.

This application connects to **live brokerage account balances and positions**, aggregates forward-looking calendar cash releases, tracks historical transactions, and leverages **Google Gemini LLM** to analyze options chains and offer risk-adjusted trade suggestions.

---

## 🚀 Getting Started (Local Setup)

Follow these steps to run the application locally on macOS or Linux:

### 1. Prerequisites

- **PHP 8.4+** with the following extensions: `sqlite3`, $\text{pdo}_{\text{sqlite}}$, `curl`, `mbstring`, `openssl`, `xml`.
- **Composer** (PHP dependency manager).
- **Symfony CLI** (recommended for local web server).

### 🛠 Technology Stack

- **Backend Framework:** Symfony 8.1
- **Language Runtime:** PHP 8.4+
- **Database Engine:** SQLite (Local storage file `var/data.db`)
- **ORM / Database Migrations:** Doctrine ORM (v3.6) & Doctrine Migrations
- **Template System:** Twig Templating Engine
- **Frontend CSS/Layout:** Custom Vanilla CSS (Sleek dark theme, custom responsive grid layouts in `public/css/screener.css`)
- **Frontend Charting:** Chart.js (v4.4.1) via CDN
- **Typography & Assets:** Google Fonts (Outfit, Inter) and Google Material Symbols Outlined icons

### 2. Installation Steps

Clone the repository and navigate to the project root directory:

```bash
# Install PHP dependencies
composer install

# Set up local environment variables
cp .env.local .env.local
```

Open `.env.local` and review operational runtime configurations (e.g. $\text{TRADING}_{\text{ENABLED}}=\text{false}$ kill switch).

> [!NOTE]
> All API credentials (Schwab Developer App Key/Secret, Finnhub API Key, Gemini API Key) are **not** stored in `.env.local`. They are configured securely via the Web Setup Wizard page (`/setup`) or the Settings page (`/settings`) during first-run and stored directly in the local SQLite database (`var/data.db`).

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

Your local application will be available at **`https://127.0.0.1:8000`** with local TLS certificates.

---

## ⚙️ System Architecture & Multi-Broker Interface

The application utilizes a polymorphic, decoupled **Multi-Broker Interface** structured around [BrokerInterface](src/Broker/BrokerInterface.php). This design supports registering multiple distinct brokerage accounts side-by-side:

```
                  ┌──────────────────────┐
                  │ BrokerManagerService │
                  └──────────┬───────────┘
                             │
            ┌────────────────┼────────────────┐
            ▼                ▼                ▼
   ┌─────────────────┐ ┌──────────────┐ ┌──────────────┐
   │  SchwabBroker   │ │ AlpacaBroker │ │  IbkrBroker  │ ...
   └─────────────────┘ └──────────────┘ └──────────────┘
```

All broker adapters implement the same interface signature, ensuring the application core remains broker-agnostic:

- **`getAccountPortfolio()`**: Fetches account balances, buying power, and active positions.
- **`getAccountHistory(int $days, bool $forceRefresh)`**: Fetches settlement records and cash flow impacts.
- **`getOptionChain(string $symbol, float $currentPrice)`**: Queries option strikes, bid/ask spreads, and open interest.

---

## 📊 Data Provenance & Integration Flow

The application pulls structural, market, and intelligence data from three distinct integration layers:

| Data Layer                              | Integration Endpoint                  | Provider / Origin       | Details                                                                                                   |
| :-------------------------------------- | :------------------------------------ | :---------------------- | :-------------------------------------------------------------------------------------------------------- |
| **Brokerage Balance & Positions** | `GET /trader/v1/accounts`           | **Schwab API**    | Returns live balances, margins, positions, and average cost basis.                                        |
| **Account Custom Nicknames**      | `GET /trader/v1/userPreference`     | **Schwab API**    | Queries nickname preferences to replace raw account numbers with labels (e.g.`V-Brokerage`, `V-HSA`). |
| **Brokerage Account History**     | `GET /accounts/{hash}/transactions` | **Schwab API**    | Gathers trade execution logs, cash inflows, and fees.                                                     |
| **Equity Price Quotes**           | `GET /api/v1/quote`                 | **Finnhub API**   | Fetches active real-time ticker prices.                                                                   |
| **Company Payout Calendars**      | `GET /api/v1/stock/dividend2`       | **Finnhub API**   | Retrieves historical and upcoming cash dividend pay dates.                                                |
| **Corporate Earnings**            | `GET /api/v1/calendar/earnings`     | **Finnhub API**   | Resolves corporate earnings announcement calendars.                                                       |
| **AI Trade Reasoning**            | `POST /v1beta/models/gemini...`     | **Google Gemini** | Analyzes option chains to generate probability calculations and strike suggestions.                       |

---

## 🔄 Capital Flywheel Compounding Engine

The **Flywheel Engine** matches live portfolio holdings against upcoming calendar event projections to generate covered call recommendations:

1. **Unencumbered Share Identification:** The engine scans active equity positions to find blocks of **100+ shares** that are not pledged to active option contracts.
2. **Horizon Selection (DTE):** Selects option contract horizons (typically **30-45 Days to Expiration**) to optimize theta decay curves.
3. **Running Cash Projections:** Integrates upcoming option contract expirations and estimated dividend payments to project cash releases.
4. **Reinvestment Alerts:** Highlights date boundaries when cash collateral is released (e.g., call contract expiration) and prompts you to reinvest that cash immediately into high-yield CSPs.

---

## 🤖 Gemini AI Analysis & Option Signals

The application leverages Google's Gemini models to act as an automated option strategist:

- **Strike Price Optimizations:** Evaluates the delta, implied volatility (IV), and bid-ask spreads of the option chain.
- **Support & Resistance Probability:** Analyzes raw stock trends, historical support thresholds, and estimated earnings impact.
- **Prompt Structure:** The option chain is serialized into a condensed text representation alongside your cost basis. The model processes this data to compute a recommended strike price, expected yield, and safety cushion score.

---

## 🔒 Security & Strict Read-Only Guardrails

To protect capital and comply with self-directed account safety rules, this project enforces **strict read-only guardrails**:

1. **Write-Action Block:** By default, all code paths capable of placing trades or executing assignments are hard-blocked at the system layer unless $\text{TRADING}_{\text{ENABLED}}=\text{true}$ is set in the environment variables.
2. **Access Token Encapsulation:** Access tokens and refresh tokens are stored locally inside the sqlite cache. The frontend client never has direct access to the raw OAuth tokens; it communicates strictly via sanitized JSON APIs (`/api/broker/history/aggregated`, `/api/flywheel/calendar`).
3. **Non-PII Masking:** Account numbers are masked on-the-fly (`***3261`) before being returned by the controller.

---

## 🧮 Signal Calculation Logic & Covered Call Rules

The **Capital Flywheel Engine** evaluates signals and calculates covered call targets using quantitative rules defined in [FlywheelService.php](src/Service/FlywheelService.php):

1. **Covered Call Strike Target:** Matches stock positions against option chains. The recommended strike is selected using the target out-of-the-money percentage:
   $$
   \text{Target Strike} \ge \text{Current Price} \times (1 + \text{flywheel.covered}_{\text{call.otm}_{\text{pct}}})
   $$
2. **Cost Basis Buffer:** To prevent selling calls below your cost basis (which locks in a capital loss), strikes must satisfy:
   $$
   \text{Target Strike} \ge \text{Average Cost Basis} \times \text{flywheel.covered}_{\text{call.cost}_{\text{basis}_{\text{buffer}}}}
   $$
3. **Signal Classification:**
   - **🟢 CALL:** Triggered when the AI conviction score is high ($\ge 70$) and projected target price upside is high ($>15.0\%$). Indicates long-term bullish holding.
   - **🔴 PUT:** Triggered when stock score drops ($<45$) or upside is negative. Advises buying protective puts for hedging.
   - **🟡 WHEEL:** Stable middle range. Recommends generating income by writing Cash-Secured Puts (CSP) or Covered Calls.

---

## ⚙️ Configuration Parameters & System Impact

System configurations are managed in [AppConfigService.php](src/Service/AppConfigService.php) and stored in SQLite. Here is what each setting controls:

### Flywheel & Trade Parameters

- **$\text{flywheel.covered}_{\text{call.otm}_{\text{pct}}}$ (default `0.06`):** Selects option strikes that are 6% out-of-the-money, balancing yield vs. upside assignment risk.
- **$\text{flywheel.covered}_{\text{call.cost}_{\text{basis}_{\text{buffer}}}}$ (default `1.02`):** Demands a 2% buffer above stock purchase price, protecting your principal capital from being called away at a loss.
- **$\text{flywheel.covered}_{\text{call.dte}_{\text{target}}}$ (default `35`):** Targets contracts expiring in 35 days, capturing optimal theta decay acceleration.
- **$\text{flywheel.covered}_{\text{call.min}_{\text{shares}}}$ (default `100`):** Enforces a strict minimum of 100 shares for Covered Call writes (Option Level 1 compliance).
- **$\text{flywheel.early}_{\text{exit.btc}_{\text{profit}_{\text{threshold}}}}$ (default `50.0`):** Recommends a **Buy-To-Close (BTC)** order once 50% of the sold premium has decayed, locking in profits and freeing up collateral early.

### Caching Layers & API Gating

- **`cache.ttl.broker.portfolio` (default `60`):** Caches live portfolio balances for 1 minute to keep numbers active without hammering Schwab APIs.
- **`cache.ttl.broker.history` (default `604800`):** Caches transaction aggregates for 7 days.
- **`cache.ttl.finnhub.dividends` & `cache.ttl.finnhub.earnings` (default `604800`):** Caches corporate payout events and calendar releases for 7 days to avoid Finnhub rate-limiting errors.
- **Once-a-Day Gated History:** History fetching is restricted to query external endpoints only once a day. Subsequent requests on the same day read directly from the persistent sqlite cache (`var/data.db`). Users can manually bypass this gate using the **Force Pull Latest** button in the UI.

---

## 🔒 Security, Token Isolation & Credential Encryption

- **Database Cryptography:** Sensitive keys and OAuth tokens are stored in the SQLite database (`var/data.db`) rather than plain-text `.env` configuration files. Access tokens, refresh tokens, and API keys are encrypted at-rest using **AES-256-GCM** (authenticated symmetric encryption) with a unique system key.
- **Token Isolation:** The frontend browser has zero access to OAuth tokens. Authentication refreshes are handled exclusively by backend services. The UI interacts with sanitized API payloads (`/api/broker/history/aggregated`) where account numbers are masked (`***3261`).
- **Read-Only Kill Switch ($\text{TRADING}_{\text{ENABLED}}=\text{false}$):** Hardcoded safeguard that prevents any trade placement logic or cash movement from executing, locking the hub into a read-only portfolio analysis platform.

---

## 📋 Implementation Status (Completed vs. Mocked)

### ✅ Completed & Live Features

- **Schwab OAuth 2.0 Integration:** Full authentication flow, secure token refreshes, and `/accounts` fetching.
- **Schwab Nicknames Resolution:** Dynamic lookup of nicknames from Schwab's `/userPreference` API.
- **Persistent Transactions Cache:** Incremental merging of transactions into a local sqlite cache with an indefinite **1-year TTL** to bypass Schwab rate-limits.
- **Gated Daily Refreshes:** Gated historical API query to run at most once a day, with a manual override `Force Pull Latest` UI button.
- **Dynamic Chronological Calendar:** Filters option expirations, projected dividend cash flows, and transaction logs.

### ⚠️ Simulated & Mocked Components

- **Non-Schwab Broker APIs:** Alpaca, Robinhood, IBKR, and Tastytrade adapters return placeholder states (they implement [BrokerInterface](src/Broker/BrokerInterface.php) but do not execute live API requests).
- **Order Execution:** Order routing is mocked; the application advises on covered call parameters but does not route orders back to Schwab.
