<?php

namespace App\Service;

use App\Entity\AppConfig;
use App\Repository\AppConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Centralised runtime-config service backed by data.db (app_config table).
 *
 * Values are stored as JSON so any scalar (int, float, bool, string) or array
 * can be persisted in a single TEXT column.  A static in-process cache avoids
 * redundant DB hits during a single request; the cache is flushed automatically
 * after every save() / set() call so the next read always reflects persisted state.
 */
class AppConfigService
{
    /**
     * Hard-coded defaults used when a key has never been saved to the DB.
     * Edit these to change the factory defaults shipped with the application.
     */
    public const DEFAULTS = [
        'app.setup_completed'                    => false,
        'finnhub.api_key'                        => null,
        'gemini.api_key'                         => null,
        'openai.api_key'                         => null,
        'claude.api_key'                         => null,
        'claude.model'                           => 'claude-3-5-sonnet-latest',

        // ── Flywheel signal thresholds ────────────────────────────────────────
        'flywheel.signal.call_score_threshold'   => 70,
        'flywheel.signal.call_upside_threshold'  => 15.0,
        'flywheel.signal.put_score_threshold'    => 45,

        // ── Capital allocation weights (must sum to 1.0) ──────────────────────
        'flywheel.allocation.call_weight'        => 0.60,
        'flywheel.allocation.wheel_weight'       => 0.25,
        'flywheel.allocation.put_weight'         => 0.15,

        // ── Covered Call suggestion parameters ───────────────────────────────
        'flywheel.covered_call.otm_pct'          => 0.06,   // 6% OTM target strike
        'flywheel.covered_call.cost_basis_buffer'=> 1.02,   // strike must be ≥ cost_basis * 1.02
        'flywheel.covered_call.dte_target'       => 35,     // days-to-expiry target
        'flywheel.covered_call.est_premium_pct'  => 0.028,  // ~2.8% of price for 35 DTE
        'flywheel.covered_call.min_shares'       => 100,    // minimum unencumbered shares for eligibility

        // ── Early Exit / Buy-To-Close parameters ─────────────────────────────
        'flywheel.early_exit.btc_profit_threshold' => 50.0, // % premium decay to trigger BTC suggestion

        // ── Default risk cap for flywheel allocator ───────────────────────────
        'flywheel.default_risk_cap'              => 10000.0,

        // ── Calendar navigation bounds ────────────────────────────────────────
        'calendar.months_back'                   => 1,
        'calendar.months_forward'                => 6,

        // ── Screener / Discover parameters ───────────────────────────────────
        'screener.suggest.target_price_factor'   => 1.22,   // auto target = price * 1.22

        // ── Put hedge parameters (signal evaluator) ───────────────────────────
        'flywheel.signal.put_hedge_otm_pct'      => 0.05,   // 5% OTM put hedge
        'flywheel.signal.csp_discount_pct'       => 0.08,   // 8% discount Cash-Secured Put entry
        'flywheel.signal.call_otm_pct'           => 0.05,   // 5% OTM long call strike

        // ── LLM behaviour defaults (no API keys – those have no safe default) ─
        'llm.provider'      => 'gemini',               // overridden by user in Setup Wizard
        'gemini.model'      => 'gemini-3.5-flash',
        'openai.model'      => 'gpt-4o-mini',
        'local_llm.url'     => 'http://localhost:11434/v1',
        'local_llm.api_key' => null,
        'local_llm.model'   => 'local-model',

        // ── Cache TTL configurations (seconds) ───────────────────────────────
        'cache.ttl.finnhub.quote'                => 300,    // 5 minutes
        'cache.ttl.finnhub.earnings'             => 604800, // 7 days (long configurable TTL for earnings)
        'cache.ttl.finnhub.dividends'            => 604800, // 7 days (long configurable TTL for dividends)
        'cache.ttl.broker.portfolio'             => 60,     // 1 minute
        'cache.ttl.broker.history'               => 604800, // 7 days (long configurable TTL for transaction history)
        'cache.ttl.broker.chain'                 => 120,    // 2 minutes

        // ── API settings (timeouts, limits) ───────────────────────────────
        'api.timeout.broker.default'             => 8.0,
        'api.timeout.broker.transactions'        => 10.0,
        'api.timeout.finnhub.default'            => 3.0,
        'broker.option_chain.strike_count'       => 12,
    ];

    /** Request-lifetime in-process cache; flushed on every mutation. */
    private static array $cache = [];

    public function __construct(
        private AppConfigRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Read a single config value.  Resolution order: cache → DB → DEFAULTS → $default argument.
     *
     * Transparently handles stale AES-GCM encrypted blobs left by a previous
     * version of the application: when detected the row is nulled in the DB and
     * $default is returned, so the Setup Wizard can re-collect the credential.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $entity = $this->repository->findByKey($key);
        $value = $entity !== null ? $entity->getValue() : (self::DEFAULTS[$key] ?? $default);

        // Guard: stale encrypted blob – clear it so the wizard can re-collect the value.
        if ($this->isEncryptedBlob($value)) {
            $this->set($key, null);
            return $default;
        }

        self::$cache[$key] = $value;
        return $value;
    }

    /**
     * Persist a single key-value pair.  Creates or updates the DB row and flushes the cache.
     */
    public function set(string $key, mixed $value): void
    {
        $entity = $this->repository->findByKey($key);
        if ($entity === null) {
            $entity = new AppConfig($key, $value);
            $this->entityManager->persist($entity);
        } else {
            $entity->setValue($value);
        }

        $this->entityManager->flush();
        self::$cache = [];
    }

    /**
     * Return all config values as a flat associative array.
     * Merges DEFAULTS → DB rows so every key is always present.
     */
    public function getAll(): array
    {
        if (!empty(self::$cache) && count(self::$cache) >= count(self::DEFAULTS)) {
            return self::$cache;
        }

        // Start from hardcoded defaults
        $result = self::DEFAULTS;

        // Overlay with whatever is persisted in the DB.
        // Apply the same encrypted-blob guard as get() so stale AES-GCM blobs
        // (from a previous app version) are never forwarded to templates as arrays.
        $rows = $this->repository->findAll();
        foreach ($rows as $row) {
            $value = $row->getValue();
            $result[$row->getConfigKey()] = $this->isEncryptedBlob($value) ? null : $value;
        }

        self::$cache = $result;
        return $result;
    }

    /**
     * Bulk-save an entire config map.  Only keys present in the payload are written;
     * unknown keys are silently ignored for safety.  Cache is flushed after the batch.
     */
    public function save(array $data): void
    {
        foreach ($data as $key => $value) {
            // Only allow known config keys to be persisted
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            // Cast to the same type as the default to avoid type drift
            $value = $this->castToDefault($key, $value);

            $entity = $this->repository->findByKey($key);
            if ($entity === null) {
                $entity = new AppConfig($key, $value);
                $this->entityManager->persist($entity);
            } else {
                $entity->setValue($value);
            }
        }

        $this->entityManager->flush();
        self::$cache = [];
    }

    /**
     * Cast an incoming value to match the type of the known default.
     */
    private function castToDefault(string $key, mixed $value): mixed
    {
        $default = self::DEFAULTS[$key] ?? null;
        if ($default === null) {
            return $value;
        }

        return match (gettype($default)) {
            'integer' => (int) $value,
            'double'  => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default   => $value,
        };
    }

    /**
     * Returns the Finnhub API key stored in config.
     *
     * Finnhub is the application's fixed financial-data source (non-pluggable).
     * This thin wrapper exists because callers need a named, intentional accessor
     * rather than a magic string literal scattered across the codebase.
     */
    public function getFinnhubApiKey(): ?string
    {
        $dbVal = $this->get('finnhub.api_key');
        return !empty($dbVal) ? (string) $dbVal : null;
    }

    /**
     * Returns true when a stored value is a stale AES-GCM encrypted blob produced
     * by a previous version of the application.  Such blobs are a JSON-decoded
     * associative array ['__enc' => true, 'c' => '...', 'i' => '...', 't' => '...'].
     *
     * Called internally by get() which already auto-clears detected blobs;
     * this method is kept private to enforce that all reads go through get().
     */
    private function isEncryptedBlob(mixed $value): bool
    {
        return is_array($value)
            && isset($value['__enc'])
            && $value['__enc'] === true;
    }

    public function getBrokerInstances(): array
    {
        $instances = $this->get('broker.instances');

        // Guard: detect an encrypted blob that can no longer be decrypted.
        // Such blobs are stored as ['__enc' => true, 'c' => '...', ...] which
        // is an associative array, NOT a list of broker-instance arrays.
        // Iterating over it in Twig causes "Impossible to access attribute 'type'
        // on a bool" because the first value (__enc => true) is a boolean.
        if (
            is_array($instances) &&
            isset($instances['__enc']) &&
            $instances['__enc'] === true
        ) {
            // Stale encrypted row – wipe it so the user can re-enter credentials
            // via the Setup Wizard without seeing a 500 error.
            $this->set('broker.instances', null);
            $instances = null;
        }

        if (empty($instances) || !is_array($instances)) {
            $instances = [
                [
                    'id'         => 'b1',
                    'type'       => 'schwab',
                    'nickname'   => 'Schwab Main',
                    'app_key'    => '',
                    'app_secret' => '',
                ],
            ];
        }
        return array_slice($instances, 0, 5); // Hard limit 5 brokers
    }

    public function saveBrokerInstances(array $instances): void
    {
        $clean = [];
        $count = 0;
        foreach ($instances as $inst) {
            if ($count >= 5) break;
            $clean[] = [
                'id'         => $inst['id'] ?? ('broker_' . ($count + 1)),
                'type'       => $inst['type'] ?? 'schwab',
                'nickname'   => trim($inst['nickname'] ?? ('Broker ' . ($count + 1))),
                'app_key'    => trim($inst['app_key'] ?? ''),
                'app_secret' => trim($inst['app_secret'] ?? ''),
                'url'        => trim($inst['url'] ?? ''),
            ];
            $count++;
        }
        $this->set('broker.instances', $clean);
    }

    public function isSetupCompleted(): bool
    {
        return (bool) $this->get('app.setup_completed', false);
    }

    public function markSetupCompleted(bool $completed = true): void
    {
        $this->set('app.setup_completed', $completed);
    }
}
