# LLM Strategy Router and Signal Analysis

This document details the multi-provider artificial intelligence architecture, prompt engineering techniques, conviction scoring logic, and response normalization pipelines used to evaluate options trades in StockScreener.

---

## 1. Multi-Provider LLM Architecture

The artificial intelligence subsystem is structured to decouple prompt orchestration and signal parsing from specific LLM providers. All adapters conform to the `src/Llm/LlmServiceInterface.php` contract:

```
                            +------------------------+
                            |  LlmServiceInterface   |
                            +-----------+------------+
                                        |
       +--------------------------------+--------------------------------+
       |                                |                                |
       v                                v                                v
+---------------+               +---------------+                +---------------+
| GeminiService |               |OpenAiLlmService|               | ClaudeService |
| (Google)      |               |  (OpenAI)     |                |  (Anthropic)  |
+---------------+               +---------------+                +---------------+
       ^                                ^                                ^
       |                                |                                |
       +--------------------------------+--------------------------------+
                                        |
                            +-----------+------------+
                            |    LlmServiceRouter    |
                            +------------------------+
```

### Router Selection Logic (`LlmServiceRouter`)

`LlmServiceRouter` inspects system configuration (`AppConfigService`) to determine the active provider (e.g., `gemini`, `openai`, `claude`). It injects the corresponding API key and parameters, with graceful fallback handling if a provider fails or encounters rate limits.

---

## 2. Prompt Engineering and Option Chain Serialization

Raw options chains contain hundreds of contracts across dozens of expirations, which can exceed prompt token budgets and introduce noise. The system serializes chains using a condensed tabular format:

### Input Payload Serialization

Before querying the LLM, the system constructs a context bundle:
1. **Underlying Equity Profile**: Symbol, current price, 52-week high/low, PE ratio, beta, and upcoming earnings date.
2. **User Cost Basis & Holdings**: Unencumbered share blocks, purchase price, current unrealized gain/loss.
3. **Filtered Option Chain**: Condensed strike table filtered to the target DTE window ($\pm 15$ days around target DTE) containing strike, expiration, bid, ask, delta, volume, and open interest.

### Prompt Instruction Guardrails

The LLM is prompted with strict deterministic instructions:
- Output must conform to strict JSON without markdown wrappers.
- The recommended call strike must exceed the user's cost basis buffer.
- Strike selection must evaluate Delta ($0.20 - 0.35$ for covered calls, $-0.20$ to $-0.35$ for cash-secured puts) to balance assignment risk against annualized yield.
- Provide a qualitative risk assessment and key support/resistance levels.

---

## 3. Normalized Response Schema

Every LLM provider adapter normalizes the model's output into a uniform array structure:

```json
{
  "symbol": "AAPL",
  "action": "COVERED_CALL",
  "conviction_score": 82,
  "recommended_strike": 240.00,
  "recommended_expiration": "2026-10-17",
  "estimated_premium": 3.45,
  "annualized_yield_pct": 14.8,
  "delta": 0.28,
  "support_level": 218.50,
  "resistance_level": 242.00,
  "reasoning": "Strong support above 200-day moving average. The 240 strike offers 14.8% annualized yield with an 72% probability of expiring out of the money prior to earnings.",
  "risk_factors": [
    "Earnings announcement scheduled 12 days after option expiration.",
    "Macro interest rate volatility."
  ]
}
```

---

## 4. Signal Integration with Flywheel Engine

The normalized LLM output is fed directly into `FlywheelService`:
1. **Score Thresholding**: If the model conviction score is $\ge 70$, the recommendation is promoted to the primary UI dashboard.
2. **Safety Verification**: If the LLM recommendation violates the user's configured `Cost Basis Buffer` or `Target OTM %`, the deterministic rules in `FlywheelService` override the LLM strike suggestion to prevent capital loss.

---

## 5. Related Design Documents

- [System Architecture and High-Level Design](architecture.md)
- [Capital Flywheel Compounding Engine](flywheel-engine.md)
- [Broker Integrations and Data Ingestion Flow](broker-integrations.md)
- [Database Schema and Persistent Caching](database-caching.md)
- [Security Architecture and Guardrails](security-guardrails.md)
