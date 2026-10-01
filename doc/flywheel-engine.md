# Capital Flywheel Compounding Engine

This document provides the mathematical foundation, algorithmic workflow, execution rules, and configuration parameters of the options compounding Capital Flywheel engine.

---

## 1. Strategy Overview

The Capital Flywheel is a systematic, cash-flow generating options strategy designed to maximize compounding yield while managing downside risk on high-conviction equities. It cycles continuously between two core mechanics:

```
                  +-----------------------------------+
                  |        Stage 1: Cash Reserves      |
                  |  Cash-Secured Puts (CSP) Written  |
                  +-----------------+-----------------+
                                    |
                    Assignment      |  Option Expires OTM
                   (Strike Price)   | (Premium Retained)
                                    v
                  +-----------------+-----------------+
                  |      Stage 2: Stock Holdings      |
                  |       Covered Calls (CC) Written  |
                  +-----------------+-----------------+
                                    |
                    Called Away     |  Option Expires OTM
                   (Strike Price)   | (Premium Retained)
                                    +---------> Redeploy Cash
```

1. **Stage 1: Cash-Secured Puts (CSP)**
   Write put options below current market prices on high-quality stocks using cash collateral.
   - If the option expires out-of-the-money (OTM), the seller retains 100% of the premium and repeats the cycle.
   - If assigned, the seller acquires 100 shares per contract at a net discount (Strike Price minus Premium).

2. **Stage 2: Covered Calls (CC)**
   Write call options against accumulated 100-share blocks of stock.
   - If the option expires OTM, the seller retains the premium and continues holding the shares to sell another contract.
   - If called away, the stock is sold at the strike price, capturing capital appreciation plus premiums, and the total cash proceeds are rotated back into Stage 1.

---

## 2. Core Algorithmic Steps in `FlywheelService`

The Flywheel compounding engine (`src/Service/FlywheelService.php`) executes the following sequential evaluation pipeline:

### Step 1: Unencumbered Share Discovery
The engine scans active portfolio positions returned by the broker adapter. It determines the unencumbered share count for each equity:

$$\text{Unencumbered Shares} = \text{Total Shares Held} - (\text{Short Call Contracts} \times 100)$$

Only positions with $\text{Unencumbered Shares} \ge \text{Minimum Shares Threshold}$ (default 100) qualify for Covered Call generation.

### Step 2: Target Strike Calculation & Cost Basis Protection
To ensure that selling a call option will not lock in a net capital loss upon assignment, the target strike must satisfy two lower bounds:

1. **Out-of-the-Money Target Floor:**
   $$\text{Strike}_{\text{OTM}} = \text{Current Price} \times (1 + \text{Target OTM \%})$$

2. **Cost Basis Buffer Floor:**
   $$\text{Strike}_{\text{CostBasis}} = \text{Average Cost Basis} \times \text{Cost Basis Buffer}$$

The engine selects the minimum valid strike from the chain such that:
$$\text{Selected Strike} \ge \max(\text{Strike}_{\text{OTM}}, \text{Strike}_{\text{CostBasis}})$$

### Step 3: Horizon Selection (Days to Expiration - DTE)
Options pricing model theta decay accelerates substantially within 30 to 45 days of expiration. The engine filters available expiration dates to locate the target horizon (default 35 DTE):

$$\Delta_{\text{DTE}} = |\text{Expiration Date} - \text{Current Date}|$$

The expiration closest to the configured target DTE is selected.

### Step 4: Early Exit Detection (Buy-To-Close / BTC)
For existing short options positions, holding through the final weeks introduces tail risk for diminishing incremental premium. The engine computes the current profit capture percentage:

$$\text{Profit Capture \%} = \frac{\text{Initial Premium Collected} - \text{Current Option Price}}{\text{Initial Premium Collected}} \times 100$$

If $\text{Profit Capture \%} \ge \text{BTC Profit Threshold}$ (default 50.0%), the system raises a **Buy-To-Close (BTC)** signal to lock in gains and free capital early.

### Step 5: Chronological Cash Flow Projection
The engine aggregates all projected incoming and outgoing cash events:
- Dividend payout dates and amounts based on corporate calendar data from `FinnhubService`.
- Option contract expirations and cash collateral release dates.
- Pending trade settlements.

This calendar allows the user to anticipate capital releases and proactively queue new CSP orders.

---

## 3. Signal Classification Matrix

In addition to pure covered call generation, the engine combines technical momentum and LLM intelligence to assign a high-level trade signal:

| Signal | Criteria | Strategic Action |
| :--- | :--- | :--- |
| **CALL** | Conviction Score $\ge 70$ AND Upside $> 15.0\%$ | High-conviction bullish. Hold equity, or write distant OTM calls. |
| **PUT** | Conviction Score $< 45$ OR Upside $< 0.0\%$ | Bearish or overvalued. Consider protective puts or reducing position. |
| **WHEEL** | Conviction Score $45 - 69$ OR Moderate Upside ($0 - 15\%$) | Neutral to moderately bullish. Optimal environment for CSP and CC writes. |

---

## 4. Configuration Parameters

System parameters are managed dynamically via `AppConfigService` and the `/settings` user interface:

| Parameter Key | Default | Description |
| :--- | :--- | :--- |
| `flywheel.cc.target_otm_pct` | `0.06` (6%) | Target percentage above market price for covered call strike selection. |
| `flywheel.cc.cost_basis_buffer` | `1.02` (2%) | Minimum multiplier over average purchase price to protect against capital loss. |
| `flywheel.cc.target_dte` | `35` | Ideal contract expiration duration in days. |
| `flywheel.cc.min_shares` | `100` | Minimum unencumbered equity shares required to write 1 call contract. |
| `flywheel.btc.profit_threshold` | `50.0` (50%) | Threshold percentage of captured premium at which an early exit is triggered. |

---

## 5. Executive Advisor Insights Engine (`AdvisorService`)

The `AdvisorService` (`src/Service/AdvisorService.php`) processes portfolio holdings, tax lot ledgers, and unencumbered cash balances to surface real-time actionable advisory insights ("nuggets") across three strategic pillars:

### 5.1 Tax Savings & Asset Location
- **Tax Loss Harvesting (TLH) Scanner:** Identifies unrealized tax loss clusters where individual lot losses exceed **$100** and aggregated unrealized losses exceed **$250**. Prompts the investor to harvest capital losses to offset taxable gains or up to $3,000 in ordinary income, with strict reminders regarding the 30-day wash sale window.
- **Tax Drag / Asset Location Analyzer:** Detects income-generating assets (e.g., `SGOV`, `BIL`, `TLT`, `AGG`, `BND`, `JEPI`, `JEPQ`, `XYLD`) held in taxable accounts exceeding **$1,000** in value. Recommends relocating yield-heavy instruments to tax-deferred retirement accounts (Traditional/Roth IRA) to eliminate annual tax drag.

### 5.2 Income Generation & Cash Optimization
- **Unencumbered Cash Yield Scanner:** Evaluates liquid cash not pledged to Cash-Secured Puts. If unencumbered cash exceeds **$5,000**, the advisor calculates potential annual yield (~4.5%-5.0%) achievable by sweeping idle funds into ultra-short T-Bill ETFs or Treasury instruments.
- **Unencumbered Covered Call Scanner:** Identifies equity positions with **100+ unencumbered shares** not currently pledged to active call contracts, calculating available contract units ($N = \lfloor \text{Shares} / 100 \rfloor$) and prompting covered call monetization.

### 5.3 Growth & Risk Management
- **Single-Ticker Concentration Risk:** Flags equity positions that represent **> 25%** of total portfolio liquidation value, alerting the user to single-stock volatility exposure and suggesting rebalancing or collar hedging.

---

## 6. Dynamic Signal & Progressive Disclosure Engine (`DynamicSignalService`)

The `DynamicSignalService` (`src/Service/DynamicSignalService.php`) bridges live Finnhub market intelligence (analyst price targets, recommendation trends, and corporate earnings calendars) with portfolio context, outputting signals under a **Two-Tier Progressive Disclosure** architecture:

### 6.1 Two-Tier Progressive Disclosure Model
1. **Tier 1: "The Executive Output" (Crisp Executive Summary):**
   - Direct strategic action: Buy Date, Target Exit Date, Target DTE, Suggested Strike.
   - Recommended dollar allocation (capped at 8% of available liquid cash, max $2,500).
   - One-sentence plain thesis, specific profit-exit target rule, and defined stop-loss rule.
2. **Tier 2: "The Deep Audit" (Institutional Underwriting):**
   - Wall Street consensus target range (`targetMean`, `targetHigh`, `targetLow`).
   - Analyst recommendation breakdown (Buy, Hold, Sell vote distributions).
   - Catalyst timeline with days to next earnings event.
   - Zero-margin validation confirming 100% cash/share collateral backing.

### 6.2 Binary Earnings Collision Avoidance
Holding short options through corporate earnings announcements exposes capital to extreme binary gap risk and post-announcement implied volatility (IV) crush. If an upcoming earnings announcement falls between 14 and 75 days out:
$$\text{Target Exit DTE} = \max(14, \text{Days To Earnings} - 4)$$
The exit horizon is automatically calibrated to close contracts **4 days prior to the earnings event**, capturing theta decay while immunizing the strategy against earnings surprises.

---

## 7. Related Design Documents

- [System Architecture and High-Level Design](architecture.md)
- [Broker Integrations, Options Analytics, and Data Ingestion](broker-integrations.md)
- [LLM Strategy Router and Signal Analysis](llm-analysis.md)
- [Database Schema and Persistent Caching](database-caching.md)
- [Security Architecture, Guardrails, and Legal Disclaimers](security-guardrails.md)
- [Tax Engine and Portfolio Performance Accounting](tax-engine.md)

