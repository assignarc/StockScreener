<?php

namespace App\Command;

use App\Llm\LlmServiceRouter;
use App\Repository\StockRepository;
use App\Service\BrokerManagerService;
use App\Service\FinnhubService;
use App\Service\FlywheelService;
use App\Service\PersistentCacheService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:flywheel:engine',
    description: 'Runs the stateful Flywheel AI Engine to evaluate opportunities and cache the landscape.'
)]
class FlywheelEngineCommand extends Command
{
    public function __construct(
        private BrokerManagerService $brokerManager,
        private FlywheelService $flywheelService,
        private LlmServiceRouter $llmRouter,
        private PersistentCacheService $cache,
        private LoggerInterface $logger,
        private StockRepository $stockRepository,
        private FinnhubService $finnhubService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Capital Flywheel AI Engine');
        $startTime = microtime(true);

        $engineLogs = [];
        $log = function(string $msg) use ($io, &$engineLogs) {
            $ts = date('H:i:s');
            $line = "[$ts] $msg";
            $io->writeln($line);
            $this->logger->info($line);
            $engineLogs[] = $line;
        };

        $log('Engine Started.');

        try {
            $log('Fetching live portfolio from BrokerManager...');
            $portfolio = $this->brokerManager->getAggregatedPortfolio();
            $accounts = $portfolio['accounts'] ?? [];
            
            $log(sprintf('Found %d accounts, Total Cash: $%.2f, Available Cash: $%.2f', count($accounts), $portfolio['cashBalance'] ?? 0, $portfolio['availableCash'] ?? 0));

            // Generate deterministic covered calls
            $log('Calculating Account-Level Covered Call Suggestions...');
            $coveredCalls = $this->flywheelService->generatePortfolioCoveredCallSuggestions($portfolio);
            
            // Analyze existing options and generate AI Hold/Close reasoning
            $log('Evaluating Existing Options for Early Exit/Hold...');
            $earlyExits = $this->evaluateExistingOptions($portfolio, $log);

            // Fetch and Analyze Market News
            $log('Fetching Live Market News via Finnhub...');
            $rawNews = $this->finnhubService->getMarketNews('general');
            dump('RAW NEWS FROM FINNHUB:');
            dump($rawNews);
            $log('Synthesizing Macro Market Intelligence...');
            $marketIntelligence = $this->llmRouter->analyzeMarketNews(array_slice($rawNews, 0, 15));


            // Generate New Flywheel Ideas using AI
            $log('Querying LLM for New Flywheel Ideas...');
            $trackedStocks = $this->stockRepository->findAll();
            $newIdeas = $this->llmRouter->generateFlywheelIdeas($portfolio, $trackedStocks, $marketIntelligence);

            // Build Opportunity Landscape
            $landscape = [
                'timestamp' => date('Y-m-d H:i:s T'),
                'durationMs' => round((microtime(true) - $startTime) * 1000),
                'portfolioSummary' => [
                    'netLiquidationValue' => $portfolio['netLiquidationValue'] ?? 0,
                    'cashBalance' => $portfolio['cashBalance'] ?? 0,
                    'availableCash' => $portfolio['availableCash'] ?? 0,
                    'accountCount' => count($accounts)
                ],
                'accounts' => $accounts, // Store raw account details for dashboard
                'coveredCalls' => $coveredCalls,
                'existingContracts' => $earlyExits,
                'marketIntelligence' => $marketIntelligence,
                'newIdeas' => $newIdeas,
                'engineLogs' => $engineLogs,
                'provider' => $this->llmRouter->getProviderName()
            ];

            // Save to Cache securely
            $log('Saving Opportunity Landscape to Secure Persistent Cache...');
            $this->cache->set('flywheel.engine.landscape', $landscape, 900, true);

            $log('Engine run completed successfully.');
            $io->success('Landscape cached.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $log('ERROR: ' . $e->getMessage());
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function evaluateExistingOptions(array $portfolio, callable $log): array
    {
        $evaluations = [];
        $openOptions = [];
        
        foreach ($portfolio['aggregatedEquities'] ?? [] as $eq) {
            foreach ($eq['linkedOptions'] ?? [] as $opt) {
                $opt['underlyingSymbol'] = $eq['symbol'];
                $openOptions[] = $opt;
            }
        }
        
        $log(sprintf('Found %d open option contracts to evaluate.', count($openOptions)));

        foreach ($openOptions as $opt) {
            $symbol = $opt['underlyingSymbol'];
            $log("Fetching option chain for $symbol to review contract...");
            
            $currentPrice = 0;
            foreach ($portfolio['aggregatedEquities'] as $eq) {
                if ($eq['symbol'] === $symbol) {
                    $currentPrice = $eq['marketValue'] > 0 && $eq['quantity'] > 0 ? $eq['marketValue'] / $eq['quantity'] : ($eq['averagePrice'] ?? 100);
                    break;
                }
            }

            $chain = $this->brokerManager->getOptionChain($symbol, $currentPrice);
            if (isset($chain['error'])) {
                $log("Warning: Could not fetch chain for $symbol. Skipping AI review.");
                $evaluations[] = array_merge($opt, [
                    'aiDecision' => 'HOLD',
                    'aiReasoning' => 'Could not fetch live option chain to verify.'
                ]);
                continue;
            }

            $log("Querying LLM for $symbol Option Review...");
            $aiReview = $this->llmRouter->reviewOptionPosition($symbol, $opt, $chain);
            
            $initialCredit = (float) ($opt['averagePrice'] ?? 0.0);
            $marketPrice  = (float) ($opt['marketPrice'] ?? 0.0);
            $contracts     = abs((int) ($opt['quantity'] ?? 1));
            
            $profitPct = 0.0;
            $realizedGain = 0.0;
            if ($initialCredit > 0 && $marketPrice >= 0) {
                $profitPct = round((($initialCredit - $marketPrice) / $initialCredit) * 100, 1);
                $realizedGain = round(($initialCredit - $marketPrice) * 100 * $contracts, 2);
            }

            $evaluations[] = array_merge($opt, [
                'profitPct' => $profitPct,
                'realizedGain' => $realizedGain,
                'aiDecision' => $aiReview['decision'] ?? 'HOLD',
                'aiStatus' => $aiReview['status'] ?? 'Unknown',
                'aiAction' => $aiReview['action'] ?? 'Hold',
                'aiReasoning' => $aiReview['reasoning'] ?? 'No reasoning provided.',
                'aiTargetPrice' => $aiReview['targetLimitPrice'] ?? 'N/A'
            ]);
        }

        return $evaluations;
    }
}
