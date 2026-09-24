<?php

namespace App\Service;

use App\Entity\AppConfig;
use App\Repository\AppConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Class AppConfigService
 *
 * Centralized runtime configuration service backed by SQLite storage (app_config table in var/data.db).
 * Supports scalar and complex JSON values with in-process memory caching. Flushes memory cache automatically
 * on mutations to guarantee consistent state across requests.
 *
 * Design Reference: doc/database-caching.md
 */
class AppConfigService
{
    /**
     * Default system configurations and options thresholds.
     */
    public const DEFAULTS = [
        'app.setup_completed'                    => false,
        'finnhub.api_key'                        => null,
        'gemini.api_key'                         => null,
        'openai.api_key'                         => null,
        'claude.api_key'                         => null,
        'claude.model'                           => 'claude-3-5-sonnet-latest',

        // Flywheel signal thresholds
        'flywheel.signal.call_score_threshold'   => 70,
        'flywheel.signal.call_upside_threshold'  => 15.0,
        'flywheel.signal.put_score_threshold'    => 45,

        // Capital allocation weights (must sum to 1.0)
        'flywheel.allocation.call_weight'        => 0.60,
        'flywheel.allocation.wheel_weight'       => 0.25,
        'flywheel.allocation.put_weight'         => 0.15,

        // Covered Call suggestion parameters
        'flywheel.covered_call.otm_pct'          => 0.06,   // 6% OTM target strike
        'flywheel.covered_call.cost_basis_buffer'=> 1.02,   // strike must be >= cost_basis * 1.02
        'flywheel.covered_call.dte_target'       => 35,     // days-to-expiry target
        'flywheel.covered_call.est_premium_pct'  => 0.028,  // ~2.8% of price for 35 DTE
        'flywheel.covered_call.min_shares'       => 100,    // minimum unencumbered shares for eligibility

        // Early Exit / Buy-To-Close parameters
        'flywheel.early_exit.btc_profit_threshold' => 50.0, // % premium decay to trigger BTC suggestion

        // Risk parameters
        'flywheel.default_risk_cap'              => 10000.0,
        'flywheel.engine.snooze_seconds'         => 300,    // 5 minutes sleep between iterations

        // Calendar navigation bounds
        'calendar.months_back'                   => 1,
        'calendar.months_forward'                => 6,

        // Screener parameters
        'screener.suggest.target_price_factor'   => 1.22,

        // Signal hedging parameters
        'flywheel.signal.put_hedge_otm_pct'      => 0.05,   // 5% OTM put hedge
        'flywheel.signal.csp_discount_pct'       => 0.08,   // 8% discount Cash-Secured Put entry
        'flywheel.signal.call_otm_pct'           => 0.05,   // 5% OTM long call strike

        // LLM configuration defaults
        'llm.provider'      => 'gemini',
        'gemini.model'      => 'gemini-3.5-flash',
        'openai.model'      => 'gpt-4o-mini',
        'local_llm.url'     => 'http://localhost:11434/v1',
        'local_llm.api_key' => null,
        'local_llm.model'   => 'local-model',

        // Cache TTL configurations (seconds)
        'cache.ttl.finnhub.quote'                => 900,     // 15 minutes
        'cache.ttl.finnhub.earnings'             => 604800,  // 7 days
        'cache.ttl.finnhub.dividends'            => 604800,  // 7 days
        'cache.ttl.finnhub.search'               => 1209600, // 14 days
        'cache.ttl.finnhub.profile'              => 2592000, // 30 days
        'cache.ttl.finnhub.splits'               => 2592000, // 30 days
        'cache.ttl.broker.portfolio'             => 60,      // 1 minute
        'cache.ttl.broker.history'               => 604800,  // 7 days
        'cache.ttl.broker.chain'                 => 120,     // 2 minutes

        // API timeouts and limits
        'api.timeout.broker.default'             => 8.0,
        'api.timeout.broker.transactions'        => 10.0,
        'api.timeout.finnhub.default'            => 3.0,
        'broker.option_chain.strike_count'       => 12,
    ];

    /** @var array Request-lifetime in-process cache flushed on every mutation */
    private static array $cache = [];

    /**
     * @param AppConfigRepository $repository Doctrine repository for AppConfig entity.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     */
    public function __construct(
        private AppConfigRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Read a configuration setting by key. Resolution order: Memory Cache -> SQLite Database -> DEFAULTS -> Fallback.
     *
     * @param string $key Configuration key string.
     * @param mixed $default Fallback value if setting is not found.
     * @return mixed Stored or default configuration value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $entity = $this->repository->findByKey($key);
        $value = $entity !== null ? $entity->getValue() : (self::DEFAULTS[$key] ?? $default);

        // Clear stale legacy encrypted payloads so user can re-enter credentials cleanly
        if ($this->isEncryptedBlob($value)) {
            $this->set($key, null);
            return $default;
        }

        self::$cache[$key] = $value;
        return $value;
    }

    /**
     * Persist or update a single configuration setting.
     *
     * @param string $key Configuration key string.
     * @param mixed $value Value to persist.
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
     * Retrieve all configurations as a merged associative array.
     *
     * @return array Key-value map of all settings.
     */
    public function getAll(): array
    {
        if (!empty(self::$cache) && count(self::$cache) >= count(self::DEFAULTS)) {
            return self::$cache;
        }

        $result = self::DEFAULTS;
        $rows = $this->repository->findAll();
        foreach ($rows as $row) {
            $value = $row->getValue();
            $result[$row->getConfigKey()] = $this->isEncryptedBlob($value) ? null : $value;
        }

        self::$cache = $result;
        return $result;
    }

    /**
     * Save multiple configuration keys in a single batch operation.
     *
     * @param array $data Associative array of configuration keys and values.
     */
    public function save(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

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
     * Cast value to the expected data type of the default setting.
     *
     * @param string $key Configuration key.
     * @param mixed $value Raw input value.
     * @return mixed Type-casted value.
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
     * Retrieve Finnhub API Key.
     *
     * @return string|null API key string or null.
     */
    public function getFinnhubApiKey(): ?string
    {
        $dbVal = $this->get('finnhub.api_key');
        return !empty($dbVal) ? (string) $dbVal : null;
    }

    /**
     * Check if a stored value is a stale legacy encrypted payload.
     *
     * @param mixed $value Stored configuration value.
     * @return bool True if value contains legacy encrypted dictionary markers.
     */
    private function isEncryptedBlob(mixed $value): bool
    {
        return is_array($value)
            && isset($value['__enc'])
            && $value['__enc'] === true;
    }

    /**
     * Retrieve configured broker instances array.
     *
     * @return array List of broker configuration arrays.
     */
    public function getBrokerInstances(): array
    {
        $instances = $this->get('broker.instances');

        if (
            is_array($instances) &&
            isset($instances['__enc']) &&
            $instances['__enc'] === true
        ) {
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

    /**
     * Save configured broker instances list.
     *
     * @param array $instances List of broker configuration dictionaries.
     */
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

    /**
     * Determine if initial setup wizard has been completed.
     *
     * @return bool True if initial setup was completed.
     */
    public function isSetupCompleted(): bool
    {
        return (bool) $this->get('app.setup_completed', false);
    }

    /**
     * Mark setup wizard completion status.
     *
     * @param bool $completed Setup completion status flag.
     */
    public function markSetupCompleted(bool $completed = true): void
    {
        $this->set('app.setup_completed', $completed);
    }
}
