# StockScreener Architecture and Design Documentation

This directory contains technical design documents detailing the architecture, execution model, broker integrations, artificial intelligence analysis pipelines, database structures, and security protocols of the StockScreener application.

---

## Documentation Index

1. [System Architecture and High-Level Design](architecture.md)
   Comprehensive overview of the Symfony application architecture, layered service architecture, presentation layer, and background asynchronous jobs.

2. [Capital Flywheel Compounding Engine](flywheel-engine.md)
   Algorithmic specification of the options compounding engine, including Cash-Secured Put (CSP) cycling, Covered Call (CC) target generation, unencumbered share discovery, DTE targeting, delta filtering, early profit exits (BTC), and cash reinvestment scheduling.

3. [Tax Engine and Portfolio Performance Accounting](tax-engine.md)
   First-In-First-Out (FIFO) lot matching, holding period determination (short-term vs. long-term), taxable vs. retirement IRA classification, option premium tax realization, and pre-tax/after-tax growth curves relative to the SPY benchmark.

4. [Broker Integrations and Data Ingestion Flow](broker-integrations.md)
   Design of the multi-broker adapter pattern (`BrokerInterface`, `BrokerManagerService`), Schwab OAuth 2.0 authentication flow and token refresh lifecycle, account nickname resolution, transaction history normalization, CSV batch import, and Finnhub market data endpoints.

5. [LLM Strategy Router and Signal Analysis](llm-analysis.md)
   Multi-provider artificial intelligence architecture (`LlmServiceInterface`, `LlmServiceRouter`), prompt construction for options chain evaluation, conviction scoring, support/resistance analysis, and fallback strategies across Google Gemini, OpenAI, and Anthropic Claude.

6. [Database Schema and Persistent Caching](database-caching.md)
   SQLite relational schema, Doctrine ORM entity model, key-value configuration subsystem (`AppConfig`), daily-gated external API caching rules (`PersistentCache`), and historical transaction ingestion.

7. [Security Architecture and Guardrails](security-guardrails.md)
   Read-only design principles, runtime trade-execution kill switches, OAuth token isolation, SQLite credential storage, and frontend PII masking.

---

## Component Relationship Diagram

```
+-----------------------------------------------------------------------------------+
|                                Presentation Layer                                 |
|      (Twig Templates, Chart.js Visualizations, Custom Vanilla CSS UI Framework)     |
+-----------------------------------------------------------------------------------+
                                         |
                                         v
+-----------------------------------------------------------------------------------+
|                                 Controller Layer                                  |
|   (ScreenerController, FlywheelController, BrokerController, AiController, etc.)   |
+-----------------------------------------------------------------------------------+
                                         |
         +-------------------------------+-------------------------------+
         |                               |                               |
         v                               v                               v
+--------------------+        +--------------------+          +--------------------+
|  Flywheel Engine   |        |   Broker Manager   |          |    LLM Router      |
| (FlywheelService,  |------->| (BrokerManager-    |<-------->| (LlmServiceRouter, |
|  TaxEngine)        |        |  Service, Adapters)|          |  Gemini, Claude)   |
+--------------------+        +--------------------+          +--------------------+
         |                               |                               |
         |                               v                               |
         |                    +--------------------+                     |
         +------------------->| Persistent Storage |<--------------------+
                              |  (Doctrine, SQLite,|
                              |  AppConfig, Cache) |
                              +--------------------+
```

---

## Key Source References

- Core Business Services: `src/Service/` (including `TaxEngine.php`, `PerformanceHistoryService.php`, `FlywheelService.php`)
- Broker Adapters: `src/Broker/`
- LLM Provider Services: `src/Llm/`
- Domain Entities: `src/Entity/`
- Doctrine Repositories: `src/Repository/`
- Web Controllers: `src/Controller/`
- Console Commands: `src/Command/`

