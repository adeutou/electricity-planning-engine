<?php

declare(strict_types=1);

namespace App\Infrastructure\Chart;

use App\Domain\Arbitrage\ArbitragePlan;
use App\Domain\Arbitrage\HourlyDecision;

/**
 * Génère un graphique SVG autonome (aucune dépendance JS/CSS externe, pas de
 * librairie de graphique) à partir d'un ArbitragePlan : trois pistes
 * empilées — prix, flux d'énergie, état de charge de la batterie — plus une
 * légende. Volontairement un rendu "à la main" plutôt qu'un binding vers une
 * lib de charting : le format de sortie (chaîne SVG) est trivial à tester
 * unitairement et ne nécessite aucun asset externe pour être servi par
 * l'API (cf. ChartImageController, `GET /api/plans/{id}/chart.svg`).
 *
 * Complémentaire à ChartDataController (JSON brut prêt à être graphé côté
 * client) : ici, le rendu visuel existe déjà côté serveur, utile pour
 * l'intégrer tel quel dans un rapport, un e-mail, ou une balise <img>.
 */
final class SvgArbitrageChartRenderer
{
    private const WIDTH = 960;

    private const MARGIN_LEFT = 55;

    private const MARGIN_RIGHT = 20;

    private const MARGIN_TOP = 40;

    private const PANEL_GAP = 24;

    private const PRICE_PANEL_HEIGHT = 100;

    private const ENERGY_PANEL_HEIGHT = 220;

    private const SOC_PANEL_HEIGHT = 90;

    private const X_AXIS_HEIGHT = 24;

    private const LEGEND_HEIGHT = 28;

    private const COLOR_PRICE = '#6366f1';

    private const COLOR_GRID = '#94a3b8';

    private const COLOR_PV = '#f59e0b';

    private const COLOR_BATTERY = '#10b981';

    private const COLOR_EXPORT = '#38bdf8';

    private const COLOR_SOC = '#a855f7';

    private const COLOR_AXIS = '#475569';

    private const COLOR_TEXT = '#1e293b';

    private const COLOR_ZERO_LINE = '#cbd5e1';

    public function render(ArbitragePlan $plan): string
    {
        $hours = $plan->hours();
        $count = count($hours);

        $height = self::MARGIN_TOP + self::PRICE_PANEL_HEIGHT + self::PANEL_GAP
            + self::ENERGY_PANEL_HEIGHT + self::PANEL_GAP
            + self::SOC_PANEL_HEIGHT + self::X_AXIS_HEIGHT + self::LEGEND_HEIGHT + 10;

        $innerWidth = self::WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;

        $svg = [];
        $svg[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $svg[] = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" font-family="system-ui, sans-serif">',
            self::WIDTH,
            $height
        );
        $svg[] = sprintf('<rect x="0" y="0" width="%d" height="%d" fill="#ffffff"/>', self::WIDTH, $height);
        $svg[] = $this->renderTitle($plan);

        $priceTop = self::MARGIN_TOP;
        $svg[] = $this->renderPricePanel($hours, $count, $priceTop, $innerWidth);

        $energyTop = $priceTop + self::PRICE_PANEL_HEIGHT + self::PANEL_GAP;
        $svg[] = $this->renderEnergyPanel($hours, $count, $energyTop, $innerWidth);

        $socTop = $energyTop + self::ENERGY_PANEL_HEIGHT + self::PANEL_GAP;
        $svg[] = $this->renderSocPanel($hours, $count, $socTop, $innerWidth);

        $axisTop = $socTop + self::SOC_PANEL_HEIGHT;
        $svg[] = $this->renderHourAxis($hours, $count, $axisTop, $innerWidth);

        $legendTop = $axisTop + self::X_AXIS_HEIGHT;
        $svg[] = $this->renderLegend($legendTop);

        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    private function renderTitle(ArbitragePlan $plan): string
    {
        $label = sprintf(
            'Zone %s — %s mode — total cost %s EUR',
            $plan->zone(),
            $plan->mode(),
            $this->formatNumber($plan->totalCost()->amount(), 2)
        );

        return sprintf(
            '<text x="%d" y="22" font-size="15" font-weight="600" fill="%s">%s</text>',
            self::MARGIN_LEFT,
            self::COLOR_TEXT,
            $this->escape($label)
        );
    }

    /**
     * @param  list<HourlyDecision>  $hours
     */
    private function renderPricePanel(array $hours, int $count, float $top, float $innerWidth): string
    {
        $prices = array_map(static fn (HourlyDecision $h) => $h->pricePerKwh()->amount(), $hours);
        $min = $prices === [] ? 0.0 : min($prices);
        $max = $prices === [] ? 0.0 : max($prices);
        // Toujours inclure zéro dans l'échelle : un prix négatif doit visuellement
        // apparaître sous la ligne de base, pas juste "plus petit".
        $min = min($min, 0.0);
        $max = max($max, $min + 1e-6);

        $parts = [];
        $parts[] = $this->panelLabel('Price (EUR/kWh)', $top);
        $parts[] = $this->zeroLine($min, $max, $top, self::PRICE_PANEL_HEIGHT, $innerWidth);

        $points = [];
        foreach ($hours as $i => $hour) {
            $x = self::MARGIN_LEFT + $this->hourCenterX($i, $count, $innerWidth);
            $y = $top + $this->scaleY($hour->pricePerKwh()->amount(), $min, $max, self::PRICE_PANEL_HEIGHT);
            $points[] = $this->formatNumber($x, 1).','.$this->formatNumber($y, 1);
        }
        $parts[] = sprintf(
            '<polyline points="%s" fill="none" stroke="%s" stroke-width="2"/>',
            implode(' ', $points),
            self::COLOR_PRICE
        );

        return implode("\n", $parts);
    }

    /**
     * @param  list<HourlyDecision>  $hours
     */
    private function renderEnergyPanel(array $hours, int $count, float $top, float $innerWidth): string
    {
        $maxConsumption = 1e-6;
        $maxExport = 1e-6;
        foreach ($hours as $hour) {
            $maxConsumption = max($maxConsumption, $hour->consumption()->kwh());
            $maxExport = max($maxExport, $hour->exportToGrid()->kwh());
        }

        // La piste "énergie" est divisée en deux moitiés partageant une ligne
        // zéro commune : la consommation (empilée réseau/PV/batterie) monte
        // au-dessus, l'export descend en dessous. Chaque moitié a sa propre
        // échelle pour rester lisible même si les deux grandeurs ont des
        // ordres de grandeur très différents.
        $zeroY = $top + self::ENERGY_PANEL_HEIGHT * 0.6;
        $upHeight = self::ENERGY_PANEL_HEIGHT * 0.6 - 4;
        $downHeight = self::ENERGY_PANEL_HEIGHT * 0.4 - 4;

        $parts = [];
        $parts[] = $this->panelLabel('Energy flows (kWh)', $top);
        $parts[] = sprintf(
            '<line x1="%d" y1="%s" x2="%s" y2="%s" stroke="%s" stroke-width="1"/>',
            self::MARGIN_LEFT,
            $this->formatNumber($zeroY, 1),
            self::MARGIN_LEFT + $innerWidth,
            $this->formatNumber($zeroY, 1),
            self::COLOR_ZERO_LINE
        );

        $slotWidth = $innerWidth / max(1, $count);
        $barWidth = $slotWidth * 0.6;

        foreach ($hours as $i => $hour) {
            $x = self::MARGIN_LEFT + $i * $slotWidth + ($slotWidth - $barWidth) / 2;

            $segments = [
                ['value' => $hour->consumptionFromGrid()->kwh(), 'color' => self::COLOR_GRID],
                ['value' => $hour->consumptionFromPv()->kwh(), 'color' => self::COLOR_PV],
                ['value' => $hour->consumptionFromBattery()->kwh(), 'color' => self::COLOR_BATTERY],
            ];

            $cursor = $zeroY;
            foreach ($segments as $segment) {
                if ($segment['value'] <= 0.0) {
                    continue;
                }
                $segmentHeight = ($segment['value'] / $maxConsumption) * $upHeight;
                $cursor -= $segmentHeight;
                $parts[] = sprintf(
                    '<rect x="%s" y="%s" width="%s" height="%s" fill="%s"/>',
                    $this->formatNumber($x, 1),
                    $this->formatNumber($cursor, 1),
                    $this->formatNumber($barWidth, 1),
                    $this->formatNumber($segmentHeight, 1),
                    $segment['color']
                );
            }

            $exportValue = $hour->exportToGrid()->kwh();
            if ($exportValue > 0.0) {
                $exportHeight = ($exportValue / $maxExport) * $downHeight;
                $parts[] = sprintf(
                    '<rect x="%s" y="%s" width="%s" height="%s" fill="%s"/>',
                    $this->formatNumber($x, 1),
                    $this->formatNumber($zeroY, 1),
                    $this->formatNumber($barWidth, 1),
                    $this->formatNumber($exportHeight, 1),
                    self::COLOR_EXPORT
                );
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param  list<HourlyDecision>  $hours
     */
    private function renderSocPanel(array $hours, int $count, float $top, float $innerWidth): string
    {
        $socValues = array_map(static fn (HourlyDecision $h) => $h->socEndOfHour()->kwh(), $hours);
        $max = max([1e-6, ...$socValues]);

        $parts = [];
        $parts[] = $this->panelLabel('Battery SOC (kWh)', $top);

        $points = [];
        foreach ($hours as $i => $hour) {
            $x = self::MARGIN_LEFT + $this->hourCenterX($i, $count, $innerWidth);
            $y = $top + self::SOC_PANEL_HEIGHT - ($hour->socEndOfHour()->kwh() / $max) * self::SOC_PANEL_HEIGHT;
            $points[] = $this->formatNumber($x, 1).','.$this->formatNumber($y, 1);
        }
        $parts[] = sprintf(
            '<polyline points="%s" fill="none" stroke="%s" stroke-width="2"/>',
            implode(' ', $points),
            self::COLOR_SOC
        );

        return implode("\n", $parts);
    }

    /**
     * @param  list<HourlyDecision>  $hours
     */
    private function renderHourAxis(array $hours, int $count, float $top, float $innerWidth): string
    {
        // Au plus ~24 étiquettes, quel que soit l'horizon (jusqu'à 168h
        // pour 7 jours) : au-delà, les libellés se chevaucheraient.
        $step = max(1, (int) ceil($count / 24));

        $parts = [];
        foreach ($hours as $i => $hour) {
            if ($i % $step !== 0) {
                continue;
            }
            $x = self::MARGIN_LEFT + $this->hourCenterX($i, $count, $innerWidth);
            $parts[] = sprintf(
                '<text x="%s" y="%s" font-size="10" text-anchor="middle" fill="%s">%s</text>',
                $this->formatNumber($x, 1),
                $top + 14,
                self::COLOR_AXIS,
                $hour->startsAt()->format('H:i')
            );
        }

        return implode("\n", $parts);
    }

    private function renderLegend(float $top): string
    {
        $items = [
            ['label' => 'Price', 'color' => self::COLOR_PRICE],
            ['label' => 'Grid', 'color' => self::COLOR_GRID],
            ['label' => 'PV', 'color' => self::COLOR_PV],
            ['label' => 'Battery', 'color' => self::COLOR_BATTERY],
            ['label' => 'Export', 'color' => self::COLOR_EXPORT],
            ['label' => 'SOC', 'color' => self::COLOR_SOC],
        ];

        $parts = [];
        $x = self::MARGIN_LEFT;
        foreach ($items as $item) {
            $parts[] = sprintf('<rect x="%s" y="%s" width="10" height="10" fill="%s"/>', $x, $top, $item['color']);
            $parts[] = sprintf(
                '<text x="%s" y="%s" font-size="11" fill="%s">%s</text>',
                $x + 14,
                $top + 9,
                self::COLOR_TEXT,
                $this->escape($item['label'])
            );
            $x += 90;
        }

        return implode("\n", $parts);
    }

    private function panelLabel(string $label, float $top): string
    {
        return sprintf(
            '<text x="%d" y="%s" font-size="11" fill="%s">%s</text>',
            self::MARGIN_LEFT,
            $top - 6,
            self::COLOR_AXIS,
            $this->escape($label)
        );
    }

    private function zeroLine(float $min, float $max, float $top, float $panelHeight, float $innerWidth): string
    {
        if ($min >= 0.0) {
            return '';
        }

        $y = $top + $this->scaleY(0.0, $min, $max, $panelHeight);

        return sprintf(
            '<line x1="%d" y1="%s" x2="%s" y2="%s" stroke="%s" stroke-width="1" stroke-dasharray="4 2"/>',
            self::MARGIN_LEFT,
            $this->formatNumber($y, 1),
            self::MARGIN_LEFT + $innerWidth,
            $this->formatNumber($y, 1),
            self::COLOR_ZERO_LINE
        );
    }

    private function hourCenterX(int $hourIndex, int $count, float $innerWidth): float
    {
        $slotWidth = $innerWidth / max(1, $count);

        return $hourIndex * $slotWidth + $slotWidth / 2;
    }

    /**
     * Convertit une valeur en position Y (0 = haut du panneau), échelle
     * inversée comme il se doit pour du SVG (Y croît vers le bas).
     */
    private function scaleY(float $value, float $min, float $max, float $panelHeight): float
    {
        $range = max($max - $min, 1e-6);
        $ratio = ($value - $min) / $range;

        return $panelHeight - $ratio * $panelHeight;
    }

    private function formatNumber(float $value, int $decimals): string
    {
        return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.') ?: '0';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
