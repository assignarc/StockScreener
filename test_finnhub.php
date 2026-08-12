<?php
require 'vendor/autoload.php';
$k = new \App\Kernel("dev", true);
$k->boot();
$c = $k->getContainer();
$f = $c->get(\App\Service\FinnhubService::class);
var_dump($f->getMarketNews('general', true));
