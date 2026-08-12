<?php
require 'vendor/autoload.php';
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();
$brokerManager = $container->get('App\Service\BrokerManagerService');
$portfolio = $brokerManager->getAggregatedPortfolio();
foreach ($portfolio['accounts'] as $acc) {
    echo $acc['nickname'] . " - Cash: " . $acc['cashAvailable'] . "\n";
    foreach ($acc['positions'] as $pos) {
        if ($pos['assetType'] === 'OPTION') {
            echo "  Option: " . $pos['symbol'] . " Qty: " . $pos['quantity'] . "\n";
        }
    }
}
