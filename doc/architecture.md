# System Architecture and High-Level Design

This document details the architectural topology, design patterns, component interactions, and execution lifecycles of the StockScreener application.

---

## 1. System Overview

StockScreener is a high-performance options screening, capital compounding, and portfolio analytics platform built using Symfony 8.1 and PHP 8.4+. It orchestrates live brokerage balances, options chain evaluation, corporate event calendars, and LLM-assisted trading signals to run the options Capital Flywheel strategy.

### Core Architectural Goals

- **Polymorphic Broker Abstraction**: Decouple market data and account ingestion from individual brokerage APIs via unified interface contracts.
- **Provider-Agnostic LLM Routing**: Support pluggable artificial intelligence providers (Google Gemini, OpenAI, Anthropic Claude) with unified response schemas.
- **Deterministic Options Math**: Enforce rigorous quantitative constraints (cost basis buffers, delta bands, days to expiration targets) before surface signals reach the user.
- **Local-First Resilient Persistence**: Maintain a resilient local SQLite database with multi-tier caching to insulate users from brokerage rate limits and upstream network instability.
- **Strict Read-Only Guardrails**: Prohibit unauthorized trade placement and cash transfers through architectural and runtime boundaries.

---

## 2. Layered Architecture

The application adheres to a clean layered architecture with clear separation of concerns:

```
+-------------------------------------------------------------------------------+
|                              Presentation Layer                               |
| Twig Templates (templates/*.html.twig), Chart.js Visualizations, CSS Modules  |
+-------------------------------------------------------------------------------+
                                       |
                                       v
+-------------------------------------------------------------------------------+
|                               Controller Layer                                |
| StockController, ScreenerController, FlywheelController, BrokerController,   |
| AiController, ConfigController, SetupController                               |
+-------------------------------------------------------------------------------+
                                       |
                                       v
+-------------------------------------------------------------------------------+
|                                Service Layer                                  |
| FlywheelService, BrokerManagerService, TaxEngine, FinnhubService,             |
| AppConfigService, PersistentCacheService, PerformanceHistoryService,          |
| LlmServiceRouter, SchwabCsvImporterService, DatabaseBootstrapService          |
+-------------------------------------------------------------------------------+
                  |                                     |
                  v                                     v
+------------------------------------+   +------------------------------------+
|       Broker Adapter Layer         |   |         LLM Adapter Layer          |
| SchwabBroker, TastytradeBroker,    |   | GeminiService, OpenAiLlmService,   |
| AlpacaBroker, IbkrBroker, etc.     |   | ClaudeService                      |
+------------------------------------+   +------------------------------------+
                  |                                     |
                  +------------------+------------------+
                                     |
                                     v
+-------------------------------------------------------------------------------+
|                           Data & Persistence Layer                            |
| Doctrine ORM, Repositories, SQLite Database (var/data.db), PersistentCache    |
+-------------------------------------------------------------------------------+
```

### Layer Responsibilities

1. **Presentation Layer (`templates/`, `public/css/`)**:
   Renders server-side HTML views via Twig, enhanced with client-side interactive charting (Chart.js) and custom responsive styles. Communicates asynchronously with controllers using JSON endpoints.

2. **Controller Layer (`src/Controller/`)**:
   Translates HTTP requests into service calls, validates parameters, handles error boundaries, and formats responses as HTML views or JSON payloads.

3. **Service Layer (`src/Service/`)**:
   Contains core domain logic, options math algorithms, cache orchestration, tax simulations, and configuration management.

4. **Broker Adapter Layer (`src/Broker/`)**:
   Implements `BrokerInterface` to provide normalized portfolio holdings, cash balances, and option chains regardless of the underlying broker API.

5. **LLM Adapter Layer (`src/Llm/`)**:
   Implements `LlmServiceInterface` to construct structured prompts, query LLM provider APIs, and normalize strategy recommendations.

6. **Persistence Layer (`src/Entity/`, `src/Repository/`)**:
   Manages relational data mappings and SQLite queries for stocks, watchlists, key-value settings, and JSON-serialized cache payloads.

---

## 3. Asynchronous and Daemon Execution

The application incorporates background execution capabilities for long-running synchronization and strategy evaluations:

- **CLI Console Commands (`src/Command/`)**:
  - `FlywheelEngineCommand` (`app:flywheel:run`): Headless worker that executes option chain evaluation, identifies covered call and cash-secured put opportunities, and updates cached recommendations.
  - `FinnhubCleanupCommand` (`app:finnhub:cleanup`): Prunes expired cache records and manages API rate limits.
  - `BackfillHistoryCommand` (`app:backfill:history`): Reconciles historical broker transactions and updates cost-basis ledgers.

- **Event Subscribers (`src/EventSubscriber/`)**:
  - `DatabaseAutoProvisionSubscriber`: Intercepts early HTTP requests and console commands to verify that the SQLite database file and schema exist, auto-executing bootstrap routines when necessary.
  - `FlywheelDaemonSubscriber`: Hooks into kernel termination events to trigger non-blocking background flywheel updates when configured.

---

## 4. Related Design Documents

- [Capital Flywheel Compounding Engine](flywheel-engine.md)
- [Broker Integrations and Data Ingestion Flow](broker-integrations.md)
- [LLM Strategy Router and Signal Analysis](llm-analysis.md)
- [Database Schema and Persistent Caching](database-caching.md)
- [Security Architecture and Guardrails](security-guardrails.md)
