# Security Architecture and Guardrails

This document outlines the security architecture, data protection mechanisms, token isolation boundaries, and read-only execution guardrails implemented in StockScreener.

---

## 1. Threat Model and Security Principles

StockScreener interfaces directly with live brokerage accounts and personal financial assets. To protect against unauthorized trade execution, credential leakage, and data exposure, the system adheres to four core security principles:

1. **Strict Read-Only by Default**: The application cannot route orders, move funds, or execute trades.
2. **Token Isolation**: Upstream OAuth credentials never enter the client-side execution context.
3. **Local-First Data Residency**: Financial balances, history, and tokens are stored locally on the user's filesystem (`var/data.db`).
4. **PII Masking**: Account identifiers are redacted prior to entering the presentation tier.

---

## 2. Hardcoded Read-Only Guardrails

### Architectural Block on Order Routing

The codebase deliberately excludes trade-execution API endpoints (e.g., `POST /trader/v1/orders` or `POST /v1/trading/orders`). All broker adapter implementations are strictly constrained to read operations:
- Querying account balances and purchasing power.
- Reading active portfolio positions and equity cost basis.
- Fetching historical transaction logs and settlement notices.
- Downloading market quotes and options pricing chains.

### Runtime Kill Switch

As an additional layer of defense, `AppConfigService` and environment settings enforce a global execution kill switch. Any hypothetical execution pathway checking this parameter terminates immediately when `trading_enabled` is `false`.

---

## 3. Credential Storage and Token Isolation

### Secure Database Storage

Sensitive API keys (Schwab App Key, App Secret, Finnhub API Key, LLM API Keys) and OAuth tokens are stored in the local SQLite database (`AppConfig` entity) rather than plaintext configuration files checked into version control.

### Token Lifecycle Encapsulation

```
[ Frontend Client ]
        |
        | (Sanitized JSON: Account nicknames, masked IDs, balances)
        v
[ Symfony Backend Controller Layer ]
        |
        | (BrokerManagerService handles token refresh internally)
        v
[ SQLite AppConfig Storage (var/data.db) ]
        |
        | (Bearer Access Token sent strictly via backend cURL)
        v
[ Schwab / Finnhub API Endpoints ]
```

- Web clients interact only with sanitized internal controller endpoints (`/api/broker/*`, `/api/flywheel/*`).
- Access tokens and refresh tokens are retrieved and renewed entirely within `BrokerManagerService`.
- No OAuth access token or refresh token is ever serialized into Twig templates or JSON API responses.

---

## 4. Personally Identifiable Information (PII) Masking

Real brokerage account numbers are never exposed in plaintext in browser logs or UI views:
- Schwab account numbers are transformed on-the-fly using masked string utilities (e.g., `***3261`).
- Custom user-assigned nicknames (e.g., `V-Brokerage`, `V-HSA`) resolved from Schwab's `/userPreference` endpoint are used as the primary display label.

---

## 5. Related Design Documents

- [System Architecture and High-Level Design](architecture.md)
- [Capital Flywheel Compounding Engine](flywheel-engine.md)
- [Broker Integrations and Data Ingestion Flow](broker-integrations.md)
- [LLM Strategy Router and Signal Analysis](llm-analysis.md)
- [Database Schema and Persistent Caching](database-caching.md)
