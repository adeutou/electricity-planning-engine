<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Application\Arbitrage\DTO\SimulationPlanRecord;

/**
 * Port pour l'export bonus d'un plan calculé vers Home Assistant. Suit le
 * même principe que les autres ports (PriceProviderInterface,
 * *RepositoryInterface) : l'Http layer et l'Application ne connaissent que
 * cette interface, jamais le mécanisme concret (webhook, MQTT, API REST...)
 * utilisé pour effectivement pousser la donnée — cf.
 * App\Infrastructure\HomeAssistant\HomeAssistantWebhookExporter pour
 * l'implémentation actuelle et docs/home-assistant-integration.md pour les
 * alternatives (MQTT, API REST à token) non implémentées mais documentées.
 */
interface HomeAssistantExporterInterface
{
    /**
     * @throws \App\Infrastructure\HomeAssistant\HomeAssistantExportException si l'export échoue (webhook non configuré, HA injoignable, réponse en erreur...).
     */
    public function export(SimulationPlanRecord $record): void;
}
