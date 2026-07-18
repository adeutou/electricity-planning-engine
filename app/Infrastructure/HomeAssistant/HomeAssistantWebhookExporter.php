<?php

declare(strict_types=1);

namespace App\Infrastructure\HomeAssistant;

use App\Application\Arbitrage\DTO\SimulationPlanRecord;
use App\Application\Ports\HomeAssistantExporterInterface;
use App\Domain\Arbitrage\HourlyDecision;
use App\Domain\Shared\ValueObject\Energy;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Pousse un plan calculé vers une instance Home Assistant via un webhook
 * entrant (Settings → Automations & Scenes → Automations → + → "Webhook"
 * trigger). Choisi plutôt que MQTT ou l'API REST à token pour rester
 * dépendance-libre (pas de broker à faire tourner) ; les deux alternatives
 * sont documentées dans docs/home-assistant-integration.md pour un usage
 * plus proche d'un vrai déploiement domotique local.
 *
 * En plus du planning complet, le payload calcule un `current_hour` (avec
 * une `recommended_action`) en comparant l'heure courante aux heures du
 * plan : c'est ce qu'une automatisation HA consommerait le plus
 * naturellement pour piloter un relais/onduleur en temps réel, plutôt que
 * de reparser tout le tableau `hours` côté HA.
 */
final class HomeAssistantWebhookExporter implements HomeAssistantExporterInterface
{
    public function __construct(
        private readonly ?string $webhookUrl,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function export(SimulationPlanRecord $record): void
    {
        if ($this->webhookUrl === null || $this->webhookUrl === '') {
            throw new HomeAssistantExportException(
                'Home Assistant webhook URL is not configured. Set HOME_ASSISTANT_WEBHOOK_URL in .env '.
                '— see docs/home-assistant-integration.md.'
            );
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)->post($this->webhookUrl, $this->buildPayload($record));
        } catch (ConnectionException $e) {
            throw new HomeAssistantExportException("Could not reach Home Assistant: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            throw new HomeAssistantExportException(
                "Home Assistant webhook responded with HTTP {$response->status()}."
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(SimulationPlanRecord $record): array
    {
        $plan = $record->plan;
        $now = CarbonImmutable::now();

        $currentHour = null;
        foreach ($plan->hours() as $hour) {
            $end = $hour->startsAt()->modify('+1 hour');
            if ($hour->startsAt() <= $now && $now < $end) {
                $currentHour = $hour;
                break;
            }
        }

        return [
            'plan_id' => $record->id,
            'zone' => $plan->zone(),
            'mode' => $plan->mode(),
            'generated_at' => $now->toAtomString(),
            'totals' => [
                'cost_eur' => round($plan->totalCost()->amount(), 4),
                'consumption_kwh' => round($plan->totalConsumption()->kwh(), 4),
                'pv_production_kwh' => round($plan->totalPvProduction()->kwh(), 4),
                'export_kwh' => round($plan->totalExport()->kwh(), 4),
            ],
            'current_hour' => $currentHour !== null ? $this->summarizeHour($currentHour) : null,
            'hours' => array_map($this->summarizeHour(...), $plan->hours()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeHour(HourlyDecision $hour): array
    {
        return [
            'hour_index' => $hour->hourIndex(),
            'starts_at' => $hour->startsAt()->format(DATE_ATOM),
            'recommended_action' => $this->actionFor($hour),
            'price_eur_per_kwh' => round($hour->pricePerKwh()->amount(), 6),
            'battery_charge_kwh' => round($hour->batteryCharge()->kwh(), 4),
            'battery_discharge_kwh' => round($hour->batteryDischarge()->kwh(), 4),
            'soc_end_of_hour_kwh' => round($hour->socEndOfHour()->kwh(), 4),
            'cost_eur' => round($hour->cost()->amount(), 6),
        ];
    }

    private function actionFor(HourlyDecision $hour): string
    {
        return match (true) {
            $hour->batteryCharge()->isGreaterThan(Energy::zero()) => 'charging',
            $hour->batteryDischarge()->isGreaterThan(Energy::zero()) => 'discharging',
            $hour->exportToGrid()->isGreaterThan(Energy::zero()) => 'exporting',
            default => 'idle',
        };
    }
}
