# StockScreener Release Notes

All notable changes, architectural improvements, and new capabilities across releases of the **StockScreener and Capital Flywheel Compounding Hub** are documented in this file.

The project follows [Semantic Versioning](https://semver.org/).

---

## Release Index

- [Version 3.0.0 (Latest Release)](#version-300) - Executive Advisor Intelligence, Dynamic Signals, Advanced Options Analytics, and Modular Architecture
- [Version 2.0.0](#version-200) - Historical Performance Analytics, Tax Engine, Multi-LLM Router, and CSV Importer
- [Version 1.0.0](#version-100) - Initial Production Release: Core Capital Flywheel & Multi-Broker Foundation

---

## [Version 3.0.0] - October 2026

### Highlights
Version 3.0.0 introduces an institutional-grade **Executive Advisor Insights Engine**, a **Two-Tier Dynamic Signal Underwriting Engine**, advanced **Options Valuation and Collateral Ringfencing**, an end-to-end **Modular Frontend Controller Architecture**, and an interactive **Legal Disclaimer Compliance Modal**.

### New Features & Enhancements

#### 1. Executive Advisor Insights Engine ([AdvisorService.php](file:///Users/vishalkhapre/Documents/Code/StockScreener/src/Service/AdvisorService.php))
- **Tax-Loss Harvesting (TLH) Scanner:** Automatically scans unrealized tax lots to detect loss clusters ($>\$100$ per lot, $>\$250$ aggregated) and alerts the user to harvest losses against capital gains or up to $3,000 in ordinary income, complete with 30-day wash-sale guardrails.
- **Tax Drag / Asset Location Analyzer:** Detects high-yield/fixed-income holdings (e.g., `SGOV`, `BIL`, `TLT`, `AGG`, `BND`, `JEPI`, `JEPQ`, `XYLD`) held in taxable accounts exceeding $\$1,000$, recommending relocation to tax-deferred IRAs.
- **Idle Cash Yield Optimizer:** Evaluates liquid unencumbered cash ($>\$5,000$) not pledged to Cash-Secured Puts, calculating potential annual yields ($\sim 4.5\%-5.0\%$) via Treasury sweep.
- **Unencumbered Covered Call Scanner:** Identifies equity holdings with 100+ unencumbered shares available for immediate covered call writing.
- **Single-Stock Concentration Risk:** Warns investors when a single equity position exceeds 25% of total portfolio liquidation value.
- **Executive API Endpoint:** Surfaces prioritized insights via `GET /api/advisor/insights`.

#### 2. Dynamic Signal & Progressive Disclosure Engine ([DynamicSignalService.php](file:///Users/vishalkhapre/Documents/Code/StockScreener/src/Service/DynamicSignalService.php))
- **Two-Tier Progressive Disclosure:**
  - **Tier 1 ("The Executive Output"):** Concrete buy date, target exit date, target DTE, suggested strike, recommended allocation (capped at 8% of available liquid cash, max $\$2,500$), one-line thesis, profit-exit target, and trailing stop-loss.
  - **Tier 2 ("The Deep Audit"):** Wall Street consensus range (Target Mean, High, Low), Analyst Buy/Hold/Sell distributions, and catalyst timelines.
- **Binary Earnings Collision Avoidance:** Automatically calibrates option expiration dates to close positions 4 days prior to corporate earnings announcements ($\text{Exit DTE} = \max(14, \text{Days To Earnings} - 4)$), eliminating binary gap risk and post-earnings IV crush.

#### 3. Advanced Options Valuation & Collateral Ringfencing ([BrokerManagerService.php](file:///Users/vishalkhapre/Documents/Code/StockScreener/src/Service/BrokerManagerService.php))
- **OCC Option Symbol Parsing:** Decodes OCC standard strings (`ROOT YYMMDD[C|P]00000000`) into underlying ticker, expiration date, strike price, and contract type.
- **Moneyness & Buffer Metrics:** Real-time In-The-Money (ITM), Out-Of-The-Money (OTM), and At-The-Money (ATM) status with dollar distances and percentage buffer cushions.
- **Break-Even Price Modeling:** Computes exact net break-even points per option contract for both Calls ($\text{Strike} + \text{Premium}$) and Puts ($\text{Strike} - \text{Premium}$).
- **100% Cash Collateral Deduction:** Strictly ringfences $100\% \text{ Cash Collateral} = |\text{Quantity}| \times \text{Strike} \times 100$ for Cash-Secured Puts, subtracting collateral directly from liquid available cash to prevent margin traps.
- **Share Pledging Engine:** Automatically links and deducts pledged share blocks ($|\text{Quantity}| \times 100$) for Covered Calls against active equity positions.

#### 4. Frontend Modularization & Design System
- **Modular JavaScript Controllers (`public/js/`):** Decoupled page controllers ([portfolio.js](file:///Users/vishalkhapre/Documents/Code/StockScreener/public/js/portfolio.js), [screener.js](file:///Users/vishalkhapre/Documents/Code/StockScreener/public/js/screener.js), [history.js](file:///Users/vishalkhapre/Documents/Code/StockScreener/public/js/history.js), [discover.js](file:///Users/vishalkhapre/Documents/Code/StockScreener/public/js/discover.js), [setup.js](file:///Users/vishalkhapre/Documents/Code/StockScreener/public/js/setup.js)) managing asynchronous fetches, modal states, tab routing, and Chart.js instances.
- **Reusable Twig Partials (`templates/screener/partials/`):** Clean separation of UI views into modular partials (`_dashboard_options_card.html.twig`, `_portfolio_options.html.twig`, `_portfolio_holdings.html.twig`, `_portfolio_calendar.html.twig`, `_portfolio_history.html.twig`).
- **Iconography Standard:** Strictly standardizes on Google Material Symbols Outlined; removes all emojis from UI tables, headers, badges, and modals.
- **External Stylesheet Architecture:** Externalized styling to dedicated CSS files ([screener.css](file:///Users/vishalkhapre/Documents/Code/StockScreener/public/css/screener.css), [settings.css](file:///Users/vishalkhapre/Documents/Code/StockScreener/public/css/settings.css), [tax_center.css](file:///Users/vishalkhapre/Documents/Code/StockScreener/public/css/tax_center.css)), eliminating embedded `<style>` blocks in templates.

#### 5. Security, Legal Disclaimers & Market-Hours Gating
- **Legal Disclaimer Protocol:** Added compliance modal gating and verification endpoints (`GET /api/disclaimer/status`, `POST /api/disclaimer/acknowledge`) requiring explicit acknowledgment before generating live trade signals.
- **Finnhub Market Hours Enforcement:** Restricts live quote API requests strictly to US market hours (Mon–Fri 9:30 AM – 4:00 PM ET), serving cached/stale data off-hours to preserve API quotas.
- **Extended 30-Day Cache TTLs:** Extended persistent cache duration to 30 days (`2,592,000s`) for corporate dividends, company profiles, CUSIP resolutions, and historical stock splits.

---

## [Version 2.0.0] - September 2026

### Highlights
Version 2.0.0 added full **Tax Engine Accounting**, **Portfolio Performance History Tracking**, **Schwab CSV Batch Ingestion**, and **Multi-Provider LLM Integration** (Anthropic Claude, OpenAI, Google Gemini).

### New Features & Enhancements

#### 1. Tax Accounting Engine ([TaxEngine.php](file:///Users/vishalkhapre/Documents/Code/StockScreener/src/Service/TaxEngine.php))
- **FIFO Lot Matching:** Chronologically matches stock and ETF sales against historical buy lots to calculate net capital gain/loss.
- **Holding Period Classification:** Automatically classifies gains into Short-Term ($< 365$ days, 20% default rate) and Long-Term ($\ge 365$ days, 15% default rate).
- **Tax Status Classification:** Differentiates between Taxable accounts and Tax-Advantaged Retirement accounts (`IRA`, `ROTH`, `PCRA`, `401K`, `ROLLOVER`) with 0% tax liability.
- **Option Premium Tax Accounting:** Queues written option premiums upon Sell-To-Open (STO), realizes gains/losses upon Buy-To-Close (BTC), and recognizes full premium realization upon expiration or assignment.

#### 2. Historical Portfolio Analytics ([PerformanceHistoryService.php](file:///Users/vishalkhapre/Documents/Code/StockScreener/src/Service/PerformanceHistoryService.php))
- **Growth Curves & SPY Benchmark Indexing:** Captures daily portfolio value snapshots and normalizes portfolio returns against starting equity and the S&P 500 (SPY) across 1M, 3M, 6M, YTD, 1Y, and 2Y horizons.
- **Schwab CSV Batch Importer ([SchwabCsvImporterService.php](file:///Users/vishalkhapre/Documents/Code/StockScreener/src/Service/SchwabCsvImporterService.php)):** Imports up to 2+ years of historical transaction CSV exports with date normalization, CUSIP resolution, and fee categorization.

#### 3. Multi-Provider LLM Router ([LlmServiceRouter.php](file:///Users/vishalkhapre/Documents/Code/StockScreener/src/Service/LlmServiceRouter.php))
- Pluggable AI strategist supporting Anthropic Claude (Claude 3.5 Sonnet), OpenAI (GPT-4o), and Google Gemini (Gemini 1.5 Pro / Flash).
- Serializes condensed option chain tables and user cost basis into structured prompts and returns normalized JSON strategy recommendations.

#### 4. Background Maintenance & CLI Commands
- `app:flywheel:run` (`FlywheelEngineCommand`): Headless background worker for option chain evaluation.
- `app:finnhub:cleanup` (`FinnhubCleanupCommand`): Prunes expired cache entries.
- `app:backfill:history` (`BackfillHistoryCommand`): Reconciles historical broker transactions.

---

## [Version 1.0.0] - August 2026

### Highlights
Initial production release of the StockScreener and Capital Flywheel Compounding Hub.

### Features
- **Charles Schwab OAuth 2.0 Integration:** Secure OAuth authorization code grant flow with token refresh management and `/accounts` fetching.
- **Account Custom Nicknames:** Resolves custom account nicknames from Schwab `/userPreference` API.
- **Capital Flywheel Planner ([FlywheelService.php](file:///Users/vishalkhapre/Documents/Code/StockScreener/src/Service/FlywheelService.php)):** Unencumbered share discovery (100+ shares), 30-45 DTE horizon targeting, cost basis buffer floor ($\ge 1.02\times$), and early exit (BTC) profit threshold detection.
- **Chronological Calendar:** Interactive cash flow timeline projecting option expirations, estimated dividend receipts, and settlement dates.
- **Local-First SQLite Storage:** SQLite database (`var/data.db`) managed via Doctrine ORM with key-value configuration (`AppConfig`) and persistent caching (`PersistentCache`).
- **Strict Read-Only Guardrails:** Enforces architectural restrictions preventing order execution or fund transfers.
