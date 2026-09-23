# Tax Engine and Portfolio Performance Accounting

## Overview

The **Tax Engine** and **Performance History Subsystem** provide automated capital gains tax accounting, First-In-First-Out (FIFO) lot matching, holding period determination (short-term vs. long-term), option premium tax realization, and pre-tax versus after-tax portfolio growth curve indexing.

The subsystem operates across multi-account brokerage structures, distinguishing between **Taxable Accounts** and **Tax-Advantaged Retirement Accounts** (Traditional IRA, Roth IRA, PCRA, 401k, Rollover IRA).

---

## 1. Core Accounting Architecture

The tax calculation and performance tracking subsystem is built across three primary service components:

```
+-------------------------------------------------------------------------------+
|                            BrokerManagerService                               |
|        (Aggregated Portfolio, Positions, 730-Day Chronological History)       |
+-------------------------------------------------------------------------------+
                                        |
         +------------------------------+------------------------------+
         |                                                             |
         v                                                             v
+-------------------------------+             +---------------------------------+
|          TaxEngine            |             |    PerformanceHistoryService    |
| (FIFO Lots, ST/LT Terms,      |             | (Daily Snapshots, Growth Series,|
|  Option Premium Realizations) |             |  SPY Benchmark Indexing)        |
+-------------------------------+             +---------------------------------+
         |                                                             |
         +------------------------------+------------------------------+
                                        |
                                        v
+-------------------------------------------------------------------------------+
|                               SQLite Storage                                  |
|            (portfolio_snapshots, portfolio_events, app_config)               |
+-------------------------------------------------------------------------------+
```

### Component Roles

1. **[TaxEngine](file:///Users/vishalkhapre/Documents/Code/StockScreener/src/Service/TaxEngine.php):**
   - Ingests raw transaction feeds and portfolio holdings.
   - Maintains account-specific and global FIFO buy queues.
   - Computes realized gains/losses on equity sales and option transactions.
   - Classifies holding terms (Short-Term: `< 365 days`, Long-Term: `≥ 365 days`).
   - Applies tax rate rules based on account tax status.

2. **[PerformanceHistoryService](file:///Users/vishalkhapre/Documents/Code/StockScreener/src/Service/PerformanceHistoryService.php):**
   - Captures daily portfolio snapshots (`total_value`, `cash_balance`, `equity_value`, `option_value`, `unrealized_pl`, `est_tax_owed`, `benchmark_spy_price`).
   - Synchronizes trade and dividend events into `portfolio_events`.
   - Generates pre-tax and after-tax growth curves normalized against starting equity and the SPY benchmark.

3. **[FinnhubCleanupService](file:///Users/vishalkhapre/Documents/Code/StockScreener/src/Service/FinnhubCleanupService.php) & [SchwabCsvImporterService](file:///Users/vishalkhapre/Documents/Code/StockScreener/src/Service/SchwabCsvImporterService.php):**
   - Enriches transactions, normalizes CUSIPs, canonicalizes OCC option symbols, and imports historical transaction CSVs.

---

## 2. Tax Realization Rules and Methodologies

### 2.1 FIFO Lot Matching for Equities

When shares of a stock or ETF are sold, the engine matches the sale quantity against historical purchases using First-In, First-Out (FIFO) ordering:

1. **Queue Allocation:** Buy transactions (`BUY`, `REINVEST SHARES`, `CD DEPOSIT`) are added to an account-specific FIFO queue and a global fallback queue.
2. **Sale Matching:** When a `SELL` transaction occurs, the engine consumes lots starting from the oldest available purchase date.
3. **Partial Lot Consumption:** If a buy lot contains more shares than needed, the lot is partially decremented in cost and quantity:
   $$
   \text{Allocated Cost} = \text{Lot Cost} \times \left(\frac{\text{Match Quantity}}{\text{Lot Quantity}}\right)
   $$
4. **Fallback Cost Basis Resolution:**
   - If historical buys are not present in the ingested window (e.g., positions acquired prior to the historical window), the engine references the current portfolio's average cost basis.
   - If current cost basis is unavailable, the sale price is utilized (neutral 0 gain/loss) rather than assuming a zero cost basis, preventing runaway artificial gain spikes.

### 2.2 Short-Term vs. Long-Term Classification

Holding duration is calculated from the oldest matched purchase date to the settlement/sale date:

| Holding Period | Classification | Default Tax Rate | Details |
| :--- | :--- | :--- | :--- |
| **< 365 Days** | `SHORT_TERM` | 20% | Taxed as ordinary income / short-term capital gains. |
| **≥ 365 Days** | `LONG_TERM` | 15% | Taxed at preferential long-term capital gains rates. |
| **Pre-existing (Pre-2022)** | `LONG_TERM` | 15% | Positions held prior to the historical window default to long-term. |

### 2.3 Account Tax Status Classification

The engine dynamically classifies accounts into taxable vs. retirement categories based on account nicknames, masked account numbers, and broker account type metadata:

- **Tax-Advantaged Retirement Accounts:** `RETIREMENT_IRA`
  - Identified by tokens in nickname or type: `IRA`, `ROTH`, `PCRA`, `401K`, `SIP`, `ROLLOVER`.
  - **Effective Tax Rate:** `0.0%` (Gains and income accumulate tax-deferred or tax-free).
  - **Estimated Tax Liability:** `$0.00`.
- **Standard Brokerage Accounts:** `TAXABLE`
  - Short-term gains: `20.0%` estimated rate.
  - Long-term gains: `15.0%` estimated rate.
  - Losses reduce taxable liability against gains within the period.

---

## 3. Option Premium and Contract Accounting

The tax engine models option transactions according to Internal Revenue Service (IRS) Section 1256 and standard equity options rules:

### 3.1 Sell-To-Open (STO) - Written Options

- When an option contract is written (Sell-to-Open), the net cash premium received is queued:
  $$
  \text{Queue Record} = \{\text{Date}, \text{Quantity}, \text{Premium Received}, \text{Price}, \text{Fees}\}
  $$
- The premium is **not recognized as a taxable gain immediately**; it remains an open liability until closed, exercised, or expired.

### 3.2 Buy-To-Close (BTC) - Early Profit Exits

- When a short option is bought back before expiration (Buy-to-Close):
  $$
  \text{Realized Gain} = \text{Initial Premium Received} - \text{Buyout Outlay Paid} - \text{Transaction Fees}
  $$
- Realized gains/losses are classified as `SHORT_TERM` capital transactions.

### 3.3 Expiration and Assignment

- **Expiration Worthless:** If a short option expires without exercise, the entire initial premium is recognized as a short-term capital gain on the expiration date with a cost basis of `$0.00`.
- **Assignment:** If assigned on a Cash-Secured Put, the premium received reduces the cost basis of the acquired stock. If assigned on a Covered Call, the premium received is added to the sale proceeds of the called shares.
- **Auto-Evaluation:** The engine automatically evaluates open written options whose expiration dates have elapsed as of today, realizing the remaining premium as short-term gains.

---

## 4. Subsystem Data Hygiene and Guardrails

To ensure financial accuracy when ingesting real-world broker exports, the tax engine applies strict sanitation filters:

1. **Non-Taxable Transfer Exclusion:**
   - Filters out internal journals (`JOURNAL`), wire transfers (`WIRED FUNDS`), MoneyLink transfers, and cash transfers (`FRM`, `BANK INT`, `SCHWAB1 INT`).
2. **Bank CD Maturity Handling:**
   - Certificates of Deposit (CD) redemptions with `$0` proceeds or maturity actions (`**MATURED**`, `FDIC INS DUE`) represent return of principal and are excluded from capital gains calculations.
3. **Corporate Actions & Mergers:**
   - Filters non-sale corporate adjustments (`CASH MERGER ADJ`, `STOCK MERGER`, `REVERSE SPLIT`, `NAME CHANGE`, `MANDATORY REORG`).
4. **Basis Corruption Detection:**
   - If an imported CSV record reports a cumulative basis where the implied per-share cost exceeds `3x` the sale price, the engine automatically falls back to current portfolio average price or sale price to prevent distorted six-figure paper losses.

---

## 5. Performance Growth Curves and Benchmark Indexing

The [PerformanceHistoryService](file:///Users/vishalkhapre/Documents/Code/StockScreener/src/Service/PerformanceHistoryService.php) generates time-series metrics consumed by frontend charting components:

```
               Portfolio Growth vs Benchmark (SPY)
 Value ($)
    ^
    |                                              /--- Pre-Tax Portfolio
    |                                             /---- After-Tax Portfolio
    |                             /--------------/
    |               /------------/ . . . . . . . . ---- SPY Relative Index
    |   /----------/
    +----------------------------------------------------> Time (Days)
```

### 5.1 Series Calculations

1. **Pre-Tax Portfolio Value:** Net liquidation value of all cash, equity, and option holdings on each snapshot date.
2. **After-Tax Portfolio Value:** Net liquidation value minus cumulative estimated tax liability for the tax year:
   $$
   \text{After-Tax Value} = \max(0, \text{Total Value} - \text{Estimated Tax Owed})
   $$
3. **SPY Benchmark Relative Indexing:**
   $$
   \text{SPY Index}_{t} = \text{Portfolio Starting Value} \times \left(\frac{\text{SPY Price}_{t}}{\text{SPY Starting Price}}\right)
   $$
   This scales the S&P 500 benchmark directly to the portfolio's starting capital, enabling apples-to-apples alpha and beta comparison over 1M, 3M, 6M, YTD, 1Y, and 2Y horizons.

---

## 6. Related Documentation

- [System Architecture Specification](architecture.md)
- [Capital Flywheel Compounding Engine](flywheel-engine.md)
- [Broker Integrations & CSV Ingestion](broker-integrations.md)
- [Database Schema & Caching Rules](database-caching.md)
- [Security Architecture & Guardrails](security-guardrails.md)
