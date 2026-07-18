<?php

declare(strict_types=1);

namespace App\Domain\Arbitrage;

/**
 * Contrat commun aux moteurs d'arbitrage. SimpleArbitrageEngine (V1, règles
 * greedy) et AdvancedArbitrageEngine (V2, lookahead) implémentent tous les
 * deux cette interface : le use case applicatif choisit laquelle instancier
 * selon le paramètre "mode" de la requête, sans connaître leur logique
 * interne (Strategy pattern, comme pour PricingStrategyInterface).
 */
interface ArbitrageEngineInterface
{
    public function plan(ArbitrageContext $context): ArbitragePlan;
}
