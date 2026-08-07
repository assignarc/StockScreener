<?php

namespace App\DataFixtures;

use App\Entity\Stock;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class StockFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $stocksData = [
            [
                'symbol' => 'NVDA', 'name' => 'NVIDIA Corporation', 'sector' => 'AI Chips',
                'price' => 125.40, 'targetPrice' => 160.00, 'marketCap' => '$3.1T',
                'revGrowth' => '+122%', 'grossMargin' => '75.3%', 'cashRunway' => 'Profitable',
                'shortInterest' => '1.2%', 'risk' => 'LOW', 'score' => 92, 'analystRating' => 'Strong Buy',
                'thesis' => 'Dominant market share (>80%) in GPU AI acceleration. CUDA ecosystem creates immense moat.',
                'catalysts' => 'Blackwell B200 rollout, custom AI silicon expansion, auto/robotics integration.',
                'keyRisks' => 'High valuation, potential AI capex digestion period, supply chain concentration (TSMC).'
            ],
            [
                'symbol' => 'TSM', 'name' => 'Taiwan Semiconductor', 'sector' => 'AI Chips',
                'price' => 172.50, 'targetPrice' => 210.00, 'marketCap' => '$895B',
                'revGrowth' => '+38%', 'grossMargin' => '53.2%', 'cashRunway' => 'Profitable',
                'shortInterest' => '0.8%', 'risk' => 'LOW', 'score' => 88, 'analystRating' => 'Strong Buy',
                'thesis' => 'Sole manufacturer of leading-edge 3nm and 2nm nodes powering NVDA, AAPL, AMD.',
                'catalysts' => 'N2 (2nm) mass production 2025, CoWoS advanced packaging capacity doubling.',
                'keyRisks' => 'Geopolitical tension in Taiwan Strait, high capital intensity.'
            ],
            [
                'symbol' => 'ASTS', 'name' => 'AST SpaceMobile', 'sector' => 'Space',
                'price' => 19.80, 'targetPrice' => 35.00, 'marketCap' => '$5.2B',
                'revGrowth' => 'Pre-Rev', 'grossMargin' => 'N/A', 'cashRunway' => '18 Mo',
                'shortInterest' => '18.4%', 'risk' => 'HIGH', 'score' => 78, 'analystRating' => 'Buy',
                'thesis' => 'Building first space-based cellular broadband network compatible with standard phones.',
                'catalysts' => 'Block 1 BlueBird launch, commercial launch with AT&T/Verizon, FirstNet contract.',
                'keyRisks' => 'Execution delay on satellite deployment, capital dilution risk.'
            ],
            [
                'symbol' => 'RKLB', 'name' => 'Rocket Lab USA', 'sector' => 'Space',
                'price' => 6.45, 'targetPrice' => 11.00, 'marketCap' => '$3.1B',
                'revGrowth' => '+71%', 'grossMargin' => '26.1%', 'cashRunway' => '24 Mo',
                'shortInterest' => '8.2%', 'risk' => 'MED', 'score' => 82, 'analystRating' => 'Buy',
                'thesis' => 'Proven #2 launch provider globally behind SpaceX with Electron & space systems revenue.',
                'catalysts' => 'Neutron rocket hot fire tests, Archimedes engine qualification, SDA constellation delivery.',
                'keyRisks' => 'Neutron development delays, SpaceX Falcon 9 price competition.'
            ],
            [
                'symbol' => 'PLTR', 'name' => 'Palantir Technologies', 'sector' => 'AI Chips',
                'price' => 28.30, 'targetPrice' => 38.00, 'marketCap' => '$63B',
                'revGrowth' => '+27%', 'grossMargin' => '81.4%', 'cashRunway' => 'Profitable',
                'shortInterest' => '4.1%', 'risk' => 'MED', 'score' => 85, 'analystRating' => 'Moderate Buy',
                'thesis' => 'Artificial Intelligence Platform (AIP) accelerating US commercial & defense adoption.',
                'catalysts' => 'S&P 500 inclusion, AIP bootcamps converting enterprise clients, US DOD Maven expanding.',
                'keyRisks' => 'Rich valuation multiples (>25x P/S), potential government spending slowdowns.'
            ],
            [
                'symbol' => 'CRWD', 'name' => 'CrowdStrike Holdings', 'sector' => 'Cyber',
                'price' => 260.00, 'targetPrice' => 330.00, 'marketCap' => '$64B',
                'revGrowth' => '+33%', 'grossMargin' => '75.2%', 'cashRunway' => 'Profitable',
                'shortInterest' => '2.5%', 'risk' => 'LOW', 'score' => 84, 'analystRating' => 'Strong Buy',
                'thesis' => 'Category-defining Falcon endpoint and cloud security platform consolidating legacy vendors.',
                'catalysts' => 'Falcon Flex commitment deals, identity and cloud module adoption expanding.',
                'keyRisks' => 'Outage incident aftermath, customer discount commitments impacting ARR growth rate.'
            ],
            [
                'symbol' => 'IONQ', 'name' => 'IonQ Inc', 'sector' => 'Quantum',
                'price' => 8.15, 'targetPrice' => 16.00, 'marketCap' => '$1.7B',
                'revGrowth' => '+77%', 'grossMargin' => '58.0%', 'cashRunway' => '30 Mo',
                'shortInterest' => '14.2%', 'risk' => 'HIGH', 'score' => 74, 'analystRating' => 'Buy',
                'thesis' => 'Trapped-ion quantum computing hardware pioneer hitting #AQ 35 milestone early.',
                'catalysts' => 'Enterprise quantum algorithm deployments, quantum networking hardware advancements.',
                'keyRisks' => 'High cash burn, long commercialization timeline.'
            ],
            [
                'symbol' => 'PATH', 'name' => 'UiPath Inc', 'sector' => 'Robotics',
                'price' => 12.80, 'targetPrice' => 18.50, 'marketCap' => '$7.3B',
                'revGrowth' => '+16%', 'grossMargin' => '83.1%', 'cashRunway' => 'Profitable',
                'shortInterest' => '3.8%', 'risk' => 'MED', 'score' => 76, 'analystRating' => 'Hold',
                'thesis' => 'Enterprise Robotic Process Automation (RPA) leader infusing GenAI for agentic workflows.',
                'catalysts' => 'CEO transition completion, AI Agent Builder product launch, margin expansion.',
                'keyRisks' => 'Slower enterprise IT spending, competition from Microsoft Copilot Studio.'
            ],
            [
                'symbol' => 'CRSP', 'name' => 'CRISPR Therapeutics', 'sector' => 'Biotech',
                'price' => 52.40, 'targetPrice' => 84.00, 'marketCap' => '$4.4B',
                'revGrowth' => 'Early Commercial', 'grossMargin' => 'N/A', 'cashRunway' => '36 Mo',
                'shortInterest' => '12.1%', 'risk' => 'MED', 'score' => 81, 'analystRating' => 'Buy',
                'thesis' => 'First FDA-approved CRISPR gene editing drug (CASGEVY) commercializing for sickle cell.',
                'catalysts' => 'CASGEVY treatment center activations, in-vivo CAR-T pipeline clinical readouts.',
                'keyRisks' => 'Complex treatment logistics, payer reimbursement timelines.'
            ],
            [
                'symbol' => 'SOFI', 'name' => 'SoFi Technologies', 'sector' => 'Fintech',
                'price' => 7.25, 'targetPrice' => 11.50, 'marketCap' => '$7.8B',
                'revGrowth' => '+26%', 'grossMargin' => '82.0%', 'cashRunway' => 'Profitable',
                'shortInterest' => '16.5%', 'risk' => 'MED', 'score' => 79, 'analystRating' => 'Moderate Buy',
                'thesis' => 'Digital banking powerhouse expanding non-lending fee revenue (Financial Services & Tech Platform).',
                'catalysts' => 'GAAP profitability acceleration, Galileo tech platform client wins, rate cuts.',
                'keyRisks' => 'Loan book credit quality concerns, high short interest volatility.'
            ]
        ];

        foreach ($stocksData as $data) {
            $stock = new Stock();
            $stock->setSymbol($data['symbol']);
            $stock->setName($data['name']);
            $stock->setSector($data['sector']);
            $stock->setPrice($data['price']);
            $stock->setTargetPrice($data['targetPrice']);
            $stock->setMarketCap($data['marketCap']);
            $stock->setRevGrowth($data['revGrowth']);
            $stock->setGrossMargin($data['grossMargin']);
            $stock->setCashRunway($data['cashRunway']);
            $stock->setShortInterest($data['shortInterest']);
            $stock->setRisk($data['risk']);
            $stock->setScore($data['score']);
            $stock->setAnalystRating($data['analystRating']);
            $stock->setThesis($data['thesis']);
            $stock->setCatalysts($data['catalysts']);
            $stock->setKeyRisks($data['keyRisks']);

            $manager->persist($stock);
        }

        $manager->flush();
    }
}
