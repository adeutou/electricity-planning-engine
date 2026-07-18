<?php

declare(strict_types=1);

namespace App\Infrastructure\PriceProvider;

use App\Domain\Pricing\PricePoint;
use App\Domain\Pricing\PriceProviderInterface;
use App\Domain\Pricing\PriceSeries;
use App\Domain\Shared\ValueObject\Money;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

/**
 * Provider de prix day-ahead branché sur la structure réelle de l'API
 * ENTSO-E Transparency Platform (document A44 "Day-ahead prices").
 *
 * Sans token configuré (ENTSOE_API_TOKEN vide), on ne fait aucun appel
 * réseau : on retombe sur un document A44 *synthétique* (même format XML,
 * contenu fictif) pour que le projet reste utilisable en démo sans compte
 * ENTSO-E. Le parsing est strictement identique dans les deux cas — c'est
 * la partie qui démontre la compréhension du format réel.
 *
 * Pour brancher une vraie clé :
 *  1. Créer un compte sur https://transparency.entsoe.eu/ puis générer un
 *     token dans "My Account Settings" ;
 *  2. Renseigner ENTSOE_API_TOKEN dans .env ;
 *  3. Basculer ENERGY_PRICE_PROVIDER=entsoe.
 * Pas d'autre changement de code nécessaire.
 */
final class EntsoEPriceProvider implements PriceProviderInterface
{
    private const SOURCE = 'entsoe';

    /**
     * Codes EIC (Energy Identification Code) des zones de dépôt des offres
     * ENTSO-E les plus courantes pour ce projet. Liste non exhaustive — cf.
     * https://www.entsoe.eu/data/energy-identification-codes-eic/ pour
     * ajouter d'autres zones.
     */
    private const DOMAIN_EIC_CODES = [
        'FR' => '10YFR-RTE------C',
        'BE' => '10YBE----------2',
        'DE_LU' => '10Y1001A1001A82H',
        'ES' => '10YES-REE------0',
        'NL' => '10YNL----------L',
    ];

    /** Même forme que MockPriceProvider, dupliquée volontairement : c'est
     *  une fixture de démo isolée, pas une dépendance vers le mock provider. */
    private const DEMO_BASE_CURVE_EUR_PER_MWH = [
        0 => 70, 1 => 65, 2 => 60, 3 => 58, 4 => 60, 5 => 65,
        6 => 80, 7 => 95, 8 => 90, 9 => 60, 10 => 30, 11 => 5,
        12 => -5, 13 => -10, 14 => -5, 15 => 10, 16 => 40, 17 => 90,
        18 => 140, 19 => 170, 20 => 150, 21 => 110, 22 => 90, 23 => 78,
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiToken,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function getPrices(DateTimeInterface $from, DateTimeInterface $to, string $zone): PriceSeries
    {
        $xml = $this->apiToken !== null && $this->apiToken !== ''
            ? $this->fetchFromApi($from, $to, $zone)
            : $this->demoFixture($from, $to);

        return $this->parseA44Document($xml, $zone);
    }

    private function fetchFromApi(DateTimeInterface $from, DateTimeInterface $to, string $zone): string
    {
        $domainCode = self::DOMAIN_EIC_CODES[$zone]
            ?? throw new RuntimeException("No ENTSO-E EIC domain code configured for zone '{$zone}'.");

        // documentType=A44 : "Price Document" (day-ahead prices). Cf. ENTSO-E
        // Transparency Platform "Restful API" guide, section 4.2.10.
        $response = Http::timeout($this->timeoutSeconds)->get($this->baseUrl, [
            'securityToken' => $this->apiToken,
            'documentType' => 'A44',
            'in_Domain' => $domainCode,
            'out_Domain' => $domainCode,
            'periodStart' => $from->format('YmdHi'),
            'periodEnd' => $to->format('YmdHi'),
        ]);

        $response->throw();

        return $response->body();
    }

    /**
     * Document A44 synthétique (même schéma XML qu'une vraie réponse) pour
     * permettre des démos/tests hors-ligne sans compte ENTSO-E.
     */
    private function demoFixture(DateTimeInterface $from, DateTimeInterface $to): string
    {
        $start = DateTimeImmutable::createFromInterface($from);
        $hours = max(1, (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / 3600));

        $pointsXml = '';
        for ($i = 0; $i < $hours; $i++) {
            $hourOfDay = (int) $start->modify("+{$i} hours")->format('G');
            $price = self::DEMO_BASE_CURVE_EUR_PER_MWH[$hourOfDay] ?? 60;
            $position = $i + 1;
            $pointsXml .= "<Point><position>{$position}</position><price.amount>{$price}</price.amount></Point>";
        }

        $startLabel = $start->format('Y-m-d\TH:i\Z');
        $endLabel = $start->modify("+{$hours} hours")->format('Y-m-d\TH:i\Z');

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Publication_MarketDocument>
            <TimeSeries>
                <Period>
                    <timeInterval>
                        <start>{$startLabel}</start>
                        <end>{$endLabel}</end>
                    </timeInterval>
                    <resolution>PT60M</resolution>
                    {$pointsXml}
                </Period>
            </TimeSeries>
        </Publication_MarketDocument>
        XML;
    }

    private function parseA44Document(string $xml, string $zone): PriceSeries
    {
        $document = new SimpleXMLElement($xml);
        $points = [];

        foreach ($document->TimeSeries as $timeSeries) {
            foreach ($timeSeries->Period as $period) {
                $periodStart = new DateTimeImmutable((string) $period->timeInterval->start);
                $resolutionMinutes = $this->resolutionToMinutes((string) $period->resolution);

                foreach ($period->Point as $point) {
                    $position = (int) $point->position; // 1-based dans la spec ENTSO-E
                    $timestamp = $periodStart->modify('+'.(($position - 1) * $resolutionMinutes).' minutes');
                    $priceEurPerMwh = (float) $point->{'price.amount'};

                    $points[] = new PricePoint(
                        timestamp: $timestamp,
                        pricePerMwh: Money::of($priceEurPerMwh),
                        zone: $zone,
                        source: self::SOURCE,
                        resolutionMinutes: $resolutionMinutes,
                    );
                }
            }
        }

        return new PriceSeries($zone, $points);
    }

    private function resolutionToMinutes(string $isoDuration): int
    {
        return match ($isoDuration) {
            'PT60M', 'PT1H' => 60,
            'PT30M' => 30,
            'PT15M' => 15,
            default => throw new RuntimeException("Unsupported ENTSO-E resolution '{$isoDuration}'."),
        };
    }
}
