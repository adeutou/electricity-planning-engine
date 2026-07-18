<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Persistence\Eloquent\Models\EnergyContractModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnergyContractModel>
 */
final class EnergyContractModelFactory extends Factory
{
    protected $model = EnergyContractModel::class;

    /**
     * Par défaut : un contrat HP/HC français, le cas le plus représentatif.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Tarif Bleu HP/HC',
            'country_code' => 'FR',
            'zone' => 'FR',
            'contract_type' => 'peak_off_peak',
            'currency' => 'EUR',
            'pricing_config' => [
                'off_peak_slots' => [['start' => '22:00', 'end' => '06:00']],
                'seasons' => [[
                    'label' => 'year_round',
                    'months' => range(1, 12),
                    'rates' => [
                        ['slot' => 'peak', 'price_per_kwh' => 0.27],
                        ['slot' => 'off_peak', 'price_per_kwh' => 0.20],
                    ],
                ]],
            ],
            'subscribed_power_kva' => 9.0,
            'timezone' => 'Europe/Paris',
            'is_active' => true,
        ];
    }

    public function fixed(float $pricePerKwh = 0.2276): self
    {
        return $this->state(fn () => [
            'name' => 'Tarif Base',
            'contract_type' => 'fixed',
            'pricing_config' => ['price_per_kwh' => $pricePerKwh],
        ]);
    }

    public function tempo(): self
    {
        return $this->state(fn () => [
            'name' => 'Tarif Tempo',
            'contract_type' => 'tempo',
            'pricing_config' => [
                'off_peak_slots' => [['start' => '22:00', 'end' => '06:00']],
                'default_color' => 'blue',
                'calendar' => [
                    ['date' => now()->addDays(3)->toDateString(), 'color' => 'red'],
                ],
                'rates' => [
                    'blue' => [['slot' => 'peak', 'price_per_kwh' => 0.1609], ['slot' => 'off_peak', 'price_per_kwh' => 0.1296]],
                    'white' => [['slot' => 'peak', 'price_per_kwh' => 0.1894], ['slot' => 'off_peak', 'price_per_kwh' => 0.1486]],
                    'red' => [['slot' => 'peak', 'price_per_kwh' => 0.7562], ['slot' => 'off_peak', 'price_per_kwh' => 0.1568]],
                ],
            ],
        ]);
    }

    public function dynamicSpot(float $supplierFeePerKwh = 0.02, float $supplierMarginPercent = 5.0): self
    {
        return $this->state(fn () => [
            'name' => 'Tarif Spot',
            'contract_type' => 'dynamic_spot',
            'pricing_config' => [
                'supplier_fee_per_kwh' => $supplierFeePerKwh,
                'supplier_margin_percent' => $supplierMarginPercent,
            ],
        ]);
    }
}
